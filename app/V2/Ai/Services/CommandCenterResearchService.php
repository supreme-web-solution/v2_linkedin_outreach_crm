<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\V2OutreachLead;
use App\V2\Services\CompanyWebSearchService;
use App\V2\Services\WebScrapeChainService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Automatic prospect/company research for Command Center chat turns.
 * When the user pastes a URL or asks to research, scrape + company lookup run
 * before the agent replies — same intelligence stack as first-touch outreach.
 */
class CommandCenterResearchService
{
    private const MAX_URLS = 2;

    public function __construct(
        private readonly ProspectResearchService $research,
        private readonly WebScrapeChainService $scraper,
        private readonly CompanyWebSearchService $companySearch,
    ) {}

    public function shouldResearch(string $message): bool
    {
        $message = trim($message);
        if ($message === '') {
            return false;
        }

        if ($this->extractResearchUrls($message) !== []) {
            return true;
        }

        return (bool) preg_match(
            '/\b(research|look up|look into|check out|analyze|tell me about)\b/i',
            $message,
        );
    }

    /**
     * Run research for URLs / company hints in the user message, persist on the conversation, return prompt context.
     */
    public function enrichTurn(AiConversation $conversation, string $userMessage, string $promptMessage): string
    {
        if (! $this->shouldResearch($userMessage)) {
            return $promptMessage;
        }

        $snapshots = [];
        $urls = $this->extractResearchUrls($userMessage);

        foreach (array_slice($urls, 0, self::MAX_URLS) as $url) {
            $lead = $this->resolveLeadFromUrl($conversation, $url);
            if ($lead) {
                $intel = $this->research->researchLead($lead, $url);
                $snapshots[] = $this->snapshotFromIntel($url, $intel, $lead);
            } else {
                $intel = $this->research->research([
                    'profile_url' => $this->isProfileUrl($url) ? $url : null,
                    'scrape_url' => $url,
                ]);
                $snapshots[] = $this->snapshotFromIntel($url, $intel);
            }
        }

        if ($snapshots === [] && preg_match('/\b(research|look up|look into|check out|analyze|tell me about)\b/i', $userMessage)) {
            $company = $this->inferCompanyQuery($userMessage);
            if ($company !== '') {
                $companyResearch = $this->companySearch->research($company);
                if ($companyResearch['ok'] ?? false) {
                    $snapshots[] = [
                        'kind' => 'company',
                        'query' => $company,
                        'url' => (string) ($companyResearch['url'] ?? ''),
                        'title' => (string) ($companyResearch['title'] ?? ''),
                        'excerpt' => Str::limit((string) ($companyResearch['excerpt'] ?? ''), 1200),
                        'signals' => $this->inferSignals((string) ($companyResearch['excerpt'] ?? '')),
                        'researched_at' => Carbon::now()->toIso8601String(),
                    ];
                }
            }
        }

        if ($snapshots === []) {
            return $promptMessage;
        }

        $this->persistSnapshots($conversation, $snapshots);

        return $this->promptBlock($snapshots)."\n\n".$promptMessage;
    }

    /**
     * @param  array<string, mixed>  $intel
     * @return array<string, mixed>
     */
    public function snapshotFromIntel(string $url, array $intel, ?V2OutreachLead $lead = null): array
    {
        $scraped = is_array($intel['scraped'] ?? null) ? $intel['scraped'] : [];
        $firstScrape = is_array($scraped[0] ?? null) ? $scraped[0] : [];
        $companyRows = is_array($intel['company_research'] ?? null) ? $intel['company_research'] : [];
        $firstCompany = is_array($companyRows[0] ?? null) ? $companyRows[0] : [];

        return array_filter([
            'kind' => $lead ? 'outreach_lead' : 'url',
            'url' => $url,
            'outreach_lead_id' => $lead?->id,
            'prospect_name' => $lead?->full_name,
            'headline' => $lead?->headline,
            'title' => $firstScrape['title'] ?? $firstCompany['title'] ?? null,
            'excerpt' => Str::limit(
                (string) ($firstScrape['excerpt'] ?? $firstCompany['excerpt'] ?? ''),
                1200,
            ),
            'company' => $firstCompany['company'] ?? null,
            'signals' => is_array($intel['signals'] ?? null) ? $intel['signals'] : [],
            'researched_at' => $intel['researched_at'] ?? Carbon::now()->toIso8601String(),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     */
    public function persistSnapshots(AiConversation $conversation, array $snapshots): void
    {
        if ($snapshots === []) {
            return;
        }

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $existing = is_array($meta['command_center_research'] ?? null) ? $meta['command_center_research'] : [];
        $merged = array_merge($existing, $snapshots);
        $meta['command_center_research'] = array_slice($merged, -10);
        $meta['command_center_research_updated_at'] = Carbon::now()->toIso8601String();
        $conversation->forceFill(['meta' => $meta])->save();
    }

    /**
     * @return list<string>
     */
    public function extractResearchUrls(string $message): array
    {
        $urls = $this->scraper->extractUrls($message);

        if (preg_match_all('~(?:https?://)?(?:www\.)?linkedin\.com/in/[a-zA-Z0-9\-_%]+~i', $message, $matches)) {
            foreach ($matches[0] as $raw) {
                $normalized = Str::startsWith(strtolower($raw), 'http') ? $raw : 'https://'.$raw;
                if (! in_array($normalized, $urls, true)) {
                    $urls[] = $normalized;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     */
    public function promptBlock(array $snapshots): string
    {
        $lines = [
            '[Command Center research completed — use these facts in your reply. Do not invent details beyond this evidence.]',
        ];

        foreach ($snapshots as $index => $row) {
            $n = $index + 1;
            $lines[] = "Research #{$n}:";
            if (! empty($row['prospect_name'])) {
                $lines[] = '- Name: '.$row['prospect_name'];
            }
            if (! empty($row['headline'])) {
                $lines[] = '- Headline: '.$row['headline'];
            }
            if (! empty($row['company'])) {
                $lines[] = '- Company: '.$row['company'];
            }
            if (! empty($row['url'])) {
                $lines[] = '- URL: '.$row['url'];
            }
            if (! empty($row['title'])) {
                $lines[] = '- Page title: '.$row['title'];
            }
            if (! empty($row['excerpt'])) {
                $lines[] = '- Excerpt: '.$row['excerpt'];
            }
            if (! empty($row['signals']) && is_array($row['signals'])) {
                $lines[] = '- Signals: '.implode(', ', $row['signals']);
            }
            if (! empty($row['outreach_lead_id'])) {
                $lines[] = '- Saved on outreach lead #'.$row['outreach_lead_id'].' (research_prospect / draft_personalized_message available).';
            }
        }

        $lines[] = 'Summarize what you learned, then suggest the next step (draft a reply-first message, add to a list, start discovery, etc.).';

        return implode("\n", $lines);
    }

    private function isProfileUrl(string $url): bool
    {
        return (bool) preg_match('~linkedin\.com/in/~i', $url);
    }

    private function resolveLeadFromUrl(AiConversation $conversation, string $url): ?V2OutreachLead
    {
        if (! $this->isProfileUrl($url)) {
            return null;
        }

        $normalized = Str::lower(rtrim($url, '/'));

        return V2OutreachLead::query()
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $conversation->user_id))
            ->where(function ($q) use ($normalized, $url) {
                $q->whereRaw('LOWER(TRIM(profile_url)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(profile_url)) = ?', [Str::lower(rtrim($url, '/'))]);
            })
            ->orderByDesc('id')
            ->first();
    }

    private function inferCompanyQuery(string $message): string
    {
        if (preg_match('/\b(?:research|look up|look into|check out|analyze|tell me about)\s+(.{3,80}?)(?:\.|$|\?|,\s)/iu', $message, $match)) {
            return trim($match[1], " \t\n\r\0\x0B\"'");
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function inferSignals(string $text): array
    {
        $hay = Str::lower($text);
        $signals = [];

        foreach ([
            'agency' => ['agency', 'consulting'],
            'saas' => ['saas', 'software'],
            'outbound' => ['outbound', 'cold email'],
        ] as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($hay, $needle)) {
                    $signals[] = $label;
                    break;
                }
            }
        }

        return array_values(array_unique($signals));
    }
}
