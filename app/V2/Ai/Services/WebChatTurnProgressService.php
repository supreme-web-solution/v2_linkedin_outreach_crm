<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\V2\Ai\AgentContext;

/**
 * Live web-chat updates while Soci runs tools — status labels + interim replies.
 */
class WebChatTurnProgressService
{
    private ?AiConversation $conversation = null;

    private string $channel = 'web';

    private bool $postedFinalReply = false;

    private bool $discoveryStreamed = false;

    public function bind(?AgentContext $context): void
    {
        if ($context === null || $context->channel !== 'web') {
            $this->bindConversation(null);

            return;
        }

        $this->bindConversation($context->conversation, $context->channel);
    }

    public function bindConversation(?AiConversation $conversation, string $channel = 'web'): void
    {
        $this->conversation = $conversation;
        $this->channel = $channel;
        $this->postedFinalReply = false;
        $this->discoveryStreamed = false;
    }

    public function active(): bool
    {
        return $this->conversation !== null && $this->channel === 'web';
    }

    public function postedFinalReply(): bool
    {
        return $this->postedFinalReply;
    }

    public function markDiscoveryStreamed(): void
    {
        $this->discoveryStreamed = true;
    }

    public function status(string $label): void
    {
        if (! $this->active()) {
            return;
        }

        $text = trim($label);
        if ($text === '') {
            return;
        }

        app(WebChatProcessingService::class)->update($this->conversation, 'working', $text);
    }

    public function relay(string $content, bool $isFinal = false, ?string $nextLabel = null): void
    {
        if (! $this->active()) {
            return;
        }

        $text = trim($content);
        if ($text === '') {
            return;
        }

        app(WebChatProcessingService::class)->postProgressReply(
            $this->conversation,
            $text,
            isFinal: $isFinal,
            nextLabel: $nextLabel,
        );

        if ($isFinal) {
            $this->postedFinalReply = true;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function relayToolComplete(string $toolName, array $payload, int $durationMs): void
    {
        if (! $this->active() || $this->postedFinalReply) {
            return;
        }

        if ($this->shouldSkipToolRelay($toolName, $payload)) {
            return;
        }

        $message = $this->formatToolRelay($toolName, $payload, $durationMs);
        if ($message === null) {
            return;
        }

        $isFinal = $this->isFinalToolRelay($toolName, $payload);
        $nextLabel = $isFinal ? null : $this->labelForTool($this->guessNextToolHint($toolName));

        $this->relay($message, $isFinal, $nextLabel);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $allChannelResults
     */
    public function afterDiscoveryChannel(
        string $channel,
        array $result,
        bool $moreChannelsPending,
        ?string $nextLabel,
        array $allChannelResults = [],
    ): void {
        if (! $this->active()) {
            return;
        }

        $this->discoveryStreamed = true;

        $content = $moreChannelsPending
            ? $this->formatDiscoveryInterim($channel, $result, $nextLabel)
            : $this->formatDiscoveryFinal($allChannelResults);

        $this->relay(
            $content,
            isFinal: ! $moreChannelsPending,
            nextLabel: $moreChannelsPending ? ($nextLabel ?? 'Working…') : null,
        );
    }

    public function labelForTool(string $toolName): string
    {
        return self::TOOL_STATUS_LABELS[$toolName] ?? 'Working on it…';
    }

    /** @var array<string, string> */
    private const TOOL_STATUS_LABELS = [
        'discover_prospects' => 'Finding prospects…',
        'find_prospects' => 'Finding prospects…',
        'get_sales_brief' => 'Checking your pipeline…',
        'get_weekly_sales_brief' => 'Pulling your weekly brief…',
        'propose_strategy' => 'Building your plan…',
        'draft_campaign_plan' => 'Drafting your campaign…',
        'prepare_competitor_harvest' => 'Harvesting competitor engagers…',
        'prepare_enrichment' => 'Preparing enrichment…',
        'save_contacts' => 'Saving contacts…',
        'import_leads_csv' => 'Importing your list…',
        'research_prospect' => 'Researching this prospect…',
        'build_icp' => 'Building your ICP…',
        'start_acquisition_experiment' => 'Starting acquisition test…',
        'analyze_competitor_audience' => 'Analyzing competitor audience…',
        'prepare_linkedin_post' => 'Drafting your post…',
        'send_inbox_reply' => 'Sending your reply…',
        'book_meeting' => 'Booking the meeting…',
        'check_integrations' => 'Checking integrations…',
    ];

    /** @var list<string> */
    private const STATUS_ONLY_TOOLS = [
        'check_integrations',
        'classify_reply',
        'qualify_lead',
        'get_campaign_stats',
        'list_content_posts',
        'get_nurture_due_queue',
        'get_meeting_brief',
        'get_attention_queue',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldSkipToolRelay(string $toolName, array $payload): bool
    {
        if (! empty($payload['already_executed']) || ! empty($payload['blocked'])) {
            return true;
        }

        if ($toolName === 'discover_prospects' && $this->discoveryStreamed) {
            return true;
        }

        if (in_array($toolName, self::STATUS_ONLY_TOOLS, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isFinalToolRelay(string $toolName, array $payload): bool
    {
        if (in_array($toolName, ['get_sales_brief', 'get_weekly_sales_brief'], true)) {
            return true;
        }

        if ($toolName === 'discover_prospects' && ! empty($payload['discovery_only'])) {
            return true;
        }

        if (! empty($payload['execution_report']) && empty($payload['staged_campaigns'])) {
            return true;
        }

        return false;
    }

    private function guessNextToolHint(string $completedTool): ?string
    {
        return match ($completedTool) {
            'discover_prospects', 'find_prospects', 'save_contacts', 'import_leads_csv' => 'propose_strategy',
            'propose_strategy' => 'draft_campaign_plan',
            'prepare_competitor_harvest' => 'discover_prospects',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatToolRelay(string $toolName, array $payload, int $durationMs): ?string
    {
        if ($brief = trim((string) ($payload['brief'] ?? ''))) {
            if (in_array($toolName, ['get_sales_brief', 'get_weekly_sales_brief'], true)) {
                return $brief;
            }
        }

        if ($report = trim((string) ($payload['execution_report'] ?? ''))) {
            if ($toolName === 'discover_prospects' || ! empty($payload['discovery_only'])) {
                return $report;
            }
        }

        if ($report = trim((string) ($payload['report'] ?? ''))) {
            if (! empty($payload['already_executed'])) {
                return null;
            }

            return $report;
        }

        return match ($toolName) {
            'save_contacts' => $this->formatCountSaved($payload, 'contacts'),
            'import_leads_csv' => $this->formatCountSaved($payload, 'contacts'),
            'prepare_competitor_harvest' => 'Competitor harvest is running — new engagers will appear in Leads when ready.',
            'prepare_enrichment' => 'Enrichment is queued — emails and phones will fill in as results arrive.',
            'propose_strategy' => ! empty($payload['approval_id'])
                ? 'Strategy plan is ready — review it below when you are ready to launch.'
                : 'Strategy draft is ready — I am pulling the next pieces together.',
            'draft_campaign_plan' => ! empty($payload['approval_id'])
                ? 'Campaign draft is ready — review and launch when it looks good.'
                : 'Campaign draft is ready.',
            'research_prospect' => ! empty($payload['summary'])
                ? (string) $payload['summary']
                : 'Prospect research finished.',
            'build_icp' => ! empty($payload['icp_summary'])
                ? 'ICP saved: '.(string) $payload['icp_summary']
                : 'Your ICP profile is updated.',
            'start_acquisition_experiment' => 'Acquisition experiment started — I will report results as they come in.',
            'analyze_competitor_audience' => 'Competitor audience analysis is ready.',
            'prepare_linkedin_post' => 'LinkedIn post draft is ready for review.',
            'send_inbox_reply' => 'Reply sent.',
            'book_meeting' => 'Meeting booked.',
            default => $durationMs >= 4000
                ? $this->labelForTool($toolName).' Done — still working on the rest.'
                : null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatCountSaved(array $payload, string $noun): ?string
    {
        $count = (int) ($payload['imported'] ?? $payload['saved'] ?? $payload['total'] ?? 0);
        if ($count <= 0) {
            return null;
        }

        return 'Saved '.$count.' '.$noun.' to Leads.';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function formatDiscoveryInterim(string $channel, array $result, ?string $nextLabel): string
    {
        $label = ucfirst($channel);
        $lines = [];
        $best = is_array($result['best_match'] ?? null) ? $result['best_match'] : [];
        $found = (int) ($best['total_leads'] ?? $result['total_leads_in_matches'] ?? 0);
        $listName = trim((string) ($best['list_name'] ?? ''));

        if ($found > 0) {
            $lines[] = "{$label} done — saved {$found} prospect".($found === 1 ? '' : 's').' to Leads'
                .($listName !== '' ? " ({$listName})" : '').'.';
            $lines = array_merge($lines, $this->discoverySampleLines($result, $best));
        } else {
            $error = trim((string) ($result['failure_reason'] ?? ''));
            $lines[] = $error !== ''
                ? "{$label}: could not save — {$error}"
                : "{$label}: no profiles saved this round.";
        }

        $next = trim((string) $nextLabel);
        if ($next !== '') {
            $lines[] = '';
            $lines[] = $next;
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param  array<string, mixed>  $allChannelResults
     */
    private function formatDiscoveryFinal(array $allChannelResults): string
    {
        $ledger = app(TurnExecutionLedger::class);
        $allocation = ['allocation' => $this->discoveryActualSplit($allChannelResults)];
        $ledger->recordDiscovery('prospect search', $allocation, $allChannelResults);

        return $ledger->report();
    }

    /**
     * @param  array<string, mixed>  $allChannelResults
     * @return array<string, int>
     */
    private function discoveryActualSplit(array $allChannelResults): array
    {
        $split = [];
        foreach ($allChannelResults as $channel => $result) {
            if (! is_array($result)) {
                continue;
            }
            $best = is_array($result['best_match'] ?? null) ? $result['best_match'] : [];
            $found = (int) ($best['total_leads'] ?? $result['total_leads_in_matches'] ?? 0);
            if ($found > 0) {
                $split[(string) $channel] = $found;
            }
        }

        return $split;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $best
     * @return list<string>
     */
    private function discoverySampleLines(array $result, array $best): array
    {
        $lines = [];
        $raw = $result['sample_profiles'] ?? $best['sample_profiles'] ?? [];
        if (! is_array($raw)) {
            return $lines;
        }

        foreach (array_slice($raw, 0, 5) as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $name = trim((string) ($profile['name'] ?? $profile['full_name'] ?? $profile['username'] ?? ''));
            if ($name === '') {
                continue;
            }
            $headline = trim((string) ($profile['headline'] ?? $profile['title'] ?? ''));
            $username = trim((string) ($profile['username'] ?? ''));
            if ($username !== '' && ! str_starts_with($name, '@')) {
                $name = '@'.$username.' — '.$name;
            }
            $lines[] = '- '.($headline !== '' ? $name.' — '.$headline : $name);
        }

        if ($lines !== []) {
            array_unshift($lines, '');
        }

        return $lines;
    }
}
