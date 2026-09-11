<?php

namespace App\V2\Ai\Services;

use App\Models\V2OutreachLead;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Growing prospect dossier merged from research, scrapes, and conversation facts.
 */
class ProspectMemoryService
{
    /**
     * @return array<string, mixed>
     */
    public function dossier(V2OutreachLead $lead): array
    {
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $dossier = is_array($meta['prospect_dossier'] ?? null) ? $meta['prospect_dossier'] : [];

        if ($dossier === []) {
            $dossier = $this->emptyDossier();
        }

        $intel = is_array($meta['prospect_intelligence'] ?? null) ? $meta['prospect_intelligence'] : [];
        if ($intel !== [] && ($dossier['seeded_from_intelligence'] ?? false) !== true) {
            $dossier = $this->mergeIntelligence($dossier, $intel);
            $dossier['seeded_from_intelligence'] = true;
        }

        $leadMeta = is_array($lead->meta) ? $lead->meta : [];
        if (trim((string) ($leadMeta['company_name'] ?? Arr::get($leadMeta, 'company', ''))) !== '' && ($dossier['business']['company'] ?? '') === '') {
            $dossier['business']['company'] = trim((string) ($leadMeta['company_name'] ?? Arr::get($leadMeta, 'company', '')));
        }

        if (trim((string) ($lead->headline ?? '')) !== '' && ($dossier['business']['headline'] ?? '') === '') {
            $dossier['business']['headline'] = trim((string) $lead->headline);
        }

        return $dossier;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function merge(V2OutreachLead $lead, array $patch): array
    {
        $dossier = $this->dossier($lead);
        $dossier = array_replace_recursive($dossier, $patch);
        $dossier['updated_at'] = Carbon::now()->toIso8601String();

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $meta['prospect_dossier'] = $dossier;
        $lead->forceFill(['meta' => $meta])->save();

        return $dossier;
    }

    /**
     * @param  array<string, mixed>  $intel
     */
    public function mergeIntelligenceSnapshot(V2OutreachLead $lead, array $intel): array
    {
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $meta['prospect_intelligence'] = $intel;

        $dossier = is_array($meta['prospect_dossier'] ?? null) ? $meta['prospect_dossier'] : $this->emptyDossier();
        $dossier = $this->mergeIntelligence($dossier, $intel);
        $dossier['seeded_from_intelligence'] = true;
        $dossier['updated_at'] = Carbon::now()->toIso8601String();

        $meta['prospect_dossier'] = $dossier;
        $lead->forceFill(['meta' => $meta])->save();

        return $dossier;
    }

    /**
     * @param  array<string, mixed>  $page
     */
    public function appendScrapedPage(V2OutreachLead $lead, array $page, string $trigger = 'inbound'): array
    {
        $dossier = $this->dossier($lead);
        $url = trim((string) ($page['url'] ?? ''));
        if ($url === '') {
            return $dossier;
        }

        $existing = collect($dossier['scraped_pages'] ?? [])
            ->first(fn (array $row) => ($row['url'] ?? '') === $url);

        if ($existing !== null) {
            return $dossier;
        }

        $dossier['scraped_pages'][] = [
            'url' => $url,
            'title' => $page['title'] ?? null,
            'excerpt' => Str::limit((string) ($page['excerpt'] ?? $page['content'] ?? ''), 2000),
            'source' => $page['source'] ?? null,
            'trigger' => $trigger,
            'scraped_at' => Carbon::now()->toIso8601String(),
        ];

        $dossier['urls_seen'] = array_values(array_unique([
            ...($dossier['urls_seen'] ?? []),
            $url,
        ]));

        if (! empty($page['signals']) && is_array($page['signals'])) {
            $dossier['signals'] = array_values(array_unique([
                ...($dossier['signals'] ?? []),
                ...$page['signals'],
            ]));
        }

        return $this->merge($lead, $dossier);
    }

    /**
     * @param  array<string, mixed>  $research
     */
    public function appendCompanyResearch(V2OutreachLead $lead, array $research): array
    {
        $dossier = $this->dossier($lead);
        $company = trim((string) ($research['company'] ?? ''));
        if ($company === '') {
            return $dossier;
        }

        $existing = collect($dossier['company_research'] ?? [])
            ->first(fn (array $row) => Str::lower((string) ($row['company'] ?? '')) === Str::lower($company));

        if ($existing !== null) {
            return $dossier;
        }

        $dossier['company_research'][] = [
            'company' => $company,
            'query' => $research['query'] ?? $company,
            'url' => $research['url'] ?? null,
            'title' => $research['title'] ?? null,
            'excerpt' => Str::limit((string) ($research['excerpt'] ?? ''), 2000),
            'source' => $research['source'] ?? null,
            'researched_at' => Carbon::now()->toIso8601String(),
        ];

        if (($dossier['business']['company'] ?? '') === '') {
            $dossier['business']['company'] = $company;
        }

        return $this->merge($lead, $dossier);
    }

    public function appendConversationFact(V2OutreachLead $lead, string $fact, string $source = 'inbound'): array
    {
        $fact = trim($fact);
        if ($fact === '') {
            return $this->dossier($lead);
        }

        $dossier = $this->dossier($lead);
        $facts = $dossier['conversation_facts'] ?? [];

        foreach ($facts as $existing) {
            if (Str::lower((string) ($existing['fact'] ?? '')) === Str::lower($fact)) {
                return $dossier;
            }
        }

        $facts[] = [
            'fact' => Str::limit($fact, 500),
            'source' => $source,
            'recorded_at' => Carbon::now()->toIso8601String(),
        ];

        $dossier['conversation_facts'] = array_slice($facts, -20);

        return $this->merge($lead, $dossier);
    }

    public function setConversionStage(V2OutreachLead $lead, string $stage): array
    {
        $dossier = $this->dossier($lead);
        $dossier['conversion_stage'] = $stage;
        $dossier['conversion_stage_updated_at'] = Carbon::now()->toIso8601String();

        return $this->merge($lead, $dossier);
    }

    public function agentBrief(V2OutreachLead $lead): string
    {
        $dossier = $this->dossier($lead);
        $lines = ['Prospect intelligence dossier (use for context — never paste raw research to the prospect):'];

        $business = is_array($dossier['business'] ?? null) ? $dossier['business'] : [];
        if ($company = trim((string) ($business['company'] ?? ''))) {
            $lines[] = 'Company: '.$company;
        }
        if ($headline = trim((string) ($business['headline'] ?? ''))) {
            $lines[] = 'Role/headline: '.$headline;
        }

        $signals = array_filter($dossier['signals'] ?? [], fn ($s) => is_string($s) && $s !== '');
        if ($signals !== []) {
            $lines[] = 'Signals: '.implode(', ', $signals);
        }

        foreach (array_slice($dossier['scraped_pages'] ?? [], -3) as $page) {
            if (! is_array($page)) {
                continue;
            }
            $title = trim((string) ($page['title'] ?? ''));
            $excerpt = trim((string) ($page['excerpt'] ?? ''));
            if ($excerpt === '') {
                continue;
            }
            $lines[] = 'Scraped'.($title !== '' ? " ({$title})" : '').': '.Str::limit($excerpt, 400);
        }

        foreach (array_slice($dossier['company_research'] ?? [], -2) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $excerpt = trim((string) ($row['excerpt'] ?? ''));
            if ($excerpt === '') {
                continue;
            }
            $lines[] = 'Company research ('.($row['company'] ?? 'unknown').'): '.Str::limit($excerpt, 350);
        }

        $facts = array_slice($dossier['conversation_facts'] ?? [], -6);
        foreach ($facts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $fact = trim((string) ($row['fact'] ?? ''));
            if ($fact !== '') {
                $lines[] = 'Prospect said/fact: '.$fact;
            }
        }

        if ($stage = trim((string) ($dossier['conversion_stage'] ?? ''))) {
            $lines[] = 'Conversion stage: '.$stage;
        }

        if (count($lines) <= 1) {
            return '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $dossier
     * @param  array<string, mixed>  $intel
     * @return array<string, mixed>
     */
    private function mergeIntelligence(array $dossier, array $intel): array
    {
        if (! empty($intel['signals']) && is_array($intel['signals'])) {
            $dossier['signals'] = array_values(array_unique([
                ...($dossier['signals'] ?? []),
                ...$intel['signals'],
            ]));
        }

        foreach ($intel['scraped'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }
            $url = trim((string) ($page['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $exists = collect($dossier['scraped_pages'] ?? [])
                ->contains(fn (array $row) => ($row['url'] ?? '') === $url);
            if ($exists) {
                continue;
            }
            $dossier['scraped_pages'][] = [
                'url' => $url,
                'title' => $page['title'] ?? null,
                'excerpt' => Str::limit((string) ($page['excerpt'] ?? ''), 2000),
                'source' => 'prospect_intelligence',
                'trigger' => 'discovery',
                'scraped_at' => $intel['researched_at'] ?? Carbon::now()->toIso8601String(),
            ];
        }

        $dossier['urls_seen'] = array_values(array_unique([
            ...($dossier['urls_seen'] ?? []),
            ...($intel['urls_found'] ?? []),
        ]));

        return $dossier;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDossier(): array
    {
        return [
            'version' => 1,
            'updated_at' => Carbon::now()->toIso8601String(),
            'business' => [
                'company' => null,
                'headline' => null,
            ],
            'signals' => [],
            'scraped_pages' => [],
            'company_research' => [],
            'conversation_facts' => [],
            'urls_seen' => [],
            'conversion_stage' => 'opening',
            'conversion_stage_updated_at' => Carbon::now()->toIso8601String(),
            'seeded_from_intelligence' => false,
        ];
    }
}
