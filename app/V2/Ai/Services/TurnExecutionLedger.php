<?php

namespace App\V2\Ai\Services;

/**
 * One place for what this chat turn actually did.
 * Tools write here. The user-facing reply is built from here — not from the model's LinkedIn-only story.
 */
class TurnExecutionLedger
{
    /** @var array<string, mixed>|null */
    private static ?array $execution = null;

    private static bool $discoveryAttempted = false;

    public function reset(): void
    {
        self::$execution = null;
        self::$discoveryAttempted = false;
    }

    public function markDiscoveryAttempted(): void
    {
        self::$discoveryAttempted = true;
    }

    public function hasDiscoveryAttempt(): bool
    {
        return self::$discoveryAttempted || $this->ownsDiscovery() || $this->ownsOutreach();
    }

    /**
     * @param  array<string, mixed>  $allocation
     * @param  list<array<string, mixed>>  $channelResults
     * @param  list<array<string, mixed>>  $campaigns
     */
    public function recordOutreach(string $goal, array $allocation, array $channelResults, array $campaigns): void
    {
        self::$discoveryAttempted = true;
        self::$execution = [
            'kind' => 'find_and_outreach',
            'goal' => $goal,
            'allocation' => $allocation,
            'channels' => $this->channelSnapshots($channelResults),
            'campaigns' => $campaigns,
        ];
    }

    /**
     * @param  array<string, mixed>  $allocation
     * @param  list<array<string, mixed>>|array<string, mixed>  $channelResults
     */
    public function recordDiscovery(string $goal, array $allocation, array $channelResults): void
    {
        self::$discoveryAttempted = true;
        self::$execution = [
            'kind' => 'find_and_save',
            'goal' => $goal,
            'allocation' => $allocation,
            'channels' => $this->channelSnapshots($channelResults),
            'campaigns' => [],
        ];
    }

    public function ownsOutreach(): bool
    {
        return is_array(self::$execution)
            && (self::$execution['kind'] ?? '') === 'find_and_outreach'
            && (self::$execution['campaigns'] ?? []) !== [];
    }

    public function ownsDiscovery(): bool
    {
        return is_array(self::$execution)
            && (self::$execution['kind'] ?? '') === 'find_and_save'
            && (self::$execution['channels'] ?? []) !== [];
    }

    public function ownsTurnResult(): bool
    {
        return $this->ownsOutreach() || $this->ownsDiscovery() || ((self::$execution['kind'] ?? '') === 'workflow');
    }

    public function report(): string
    {
        if (! is_array(self::$execution)) {
            return '';
        }

        if ((self::$execution['kind'] ?? '') === 'find_and_save') {
            return $this->discoveryReport();
        }
        if ((self::$execution['kind'] ?? '') === 'workflow') {
            return $this->workflowReport();
        }

        return $this->outreachReport();
    }

    /**
     * @param array<string,mixed> $workflow
     */
    public function recordWorkflow(array $workflow): void
    {
        self::$execution = [
            'kind' => 'workflow',
            'workflow' => $workflow,
        ];
    }

    private function discoveryReport(): string
    {
        $allocation = is_array(self::$execution['allocation'] ?? null) ? self::$execution['allocation'] : [];
        $channels = is_array(self::$execution['channels'] ?? null) ? self::$execution['channels'] : [];
        $total = (int) collect($channels)->sum(fn (array $row) => (int) ($row['total_leads'] ?? 0));

        $lines = [];
        $lines[] = $total > 0
            ? "Saved {$total} prospect".($total === 1 ? '' : 's').'.'
            : 'Search finished — no new prospects were saved.';

        $actualSplit = [];
        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $found = (int) ($channel['total_leads'] ?? 0);
            if ($found > 0) {
                $actualSplit[(string) ($channel['channel'] ?? 'channel')] = $found;
            }
        }

        if ($actualSplit !== []) {
            $bits = [];
            foreach ($actualSplit as $channel => $count) {
                $bits[] = $count.' '.ucfirst((string) $channel);
            }
            $lines[] = 'Split: '.implode(' + ', $bits).'.';
        }

        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $label = ucfirst((string) ($channel['channel'] ?? 'channel'));
            $found = (int) ($channel['total_leads'] ?? 0);
            $error = trim((string) ($channel['error'] ?? ''));

            if ($found <= 0 && $error !== '') {
                $lines[] = '';
                $lines[] = "{$label}: could not save — {$error}";

                continue;
            }

            if ($found <= 0) {
                continue;
            }

            $lines[] = '';
            $lines[] = "{$label} ({$found})".($channel['list_name'] ? ' — '.$channel['list_name'] : '').':';
            foreach ($channel['samples'] as $sample) {
                $lines[] = '- '.$sample;
            }
        }

        $planned = is_array($allocation['allocation'] ?? null) ? $allocation['allocation'] : [];
        if ($planned !== [] && collect($channels)->contains(fn (array $row) => trim((string) ($row['error'] ?? '')) !== '')) {
            $plannedBits = [];
            foreach ($planned as $channel => $count) {
                $plannedBits[] = $count.' '.ucfirst((string) $channel);
            }
            $lines[] = '';
            $lines[] = 'Planned split was '.implode(' + ', $plannedBits).'. Retry Instagram from Leads or ask Soci to search again.';
        }

        $lines[] = '';
        $lines[] = 'Saved in Leads — no outreach started.';
        $lines[] = 'Say "start outreach to these" when you want me to message them.';

        return trim(implode("\n", $lines));
    }

    private function outreachReport(): string
    {
        $allocation = is_array(self::$execution['allocation'] ?? null) ? self::$execution['allocation'] : [];
        $lines = [];
        $lines[] = 'Done — here are your results.';
        $lines[] = '';

        $summary = trim((string) ($allocation['summary'] ?? ''));
        if ($summary !== '') {
            $lines[] = $summary;
            $lines[] = '';
        }

        $split = is_array($allocation['allocation'] ?? null) ? $allocation['allocation'] : [];
        if ($split !== []) {
            $bits = [];
            foreach ($split as $channel => $count) {
                $bits[] = $count.' '.ucfirst((string) $channel);
            }
            $lines[] = 'Planned split: '.implode(' + ', $bits).'.';
            $lines[] = '';
        }

        $channels = is_array(self::$execution['channels'] ?? null) ? self::$execution['channels'] : [];
        $campaigns = is_array(self::$execution['campaigns'] ?? null) ? self::$execution['campaigns'] : [];
        $byChannel = [];
        foreach ($campaigns as $campaign) {
            if (is_array($campaign)) {
                $byChannel[(string) ($campaign['channel'] ?? '')] = $campaign;
            }
        }

        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $key = (string) ($channel['channel'] ?? '');
            $label = ucfirst($key);
            $found = (int) ($channel['total_leads'] ?? 0);
            $lines[] = $label.': '.$found.' prospects saved'.($channel['list_name'] ? ' ('.$channel['list_name'].')' : '').'.';
            foreach ($channel['samples'] as $sample) {
                $lines[] = '- '.$sample;
            }
            $campaign = $byChannel[$key] ?? null;
            if (is_array($campaign)) {
                $lines[] = 'Campaign ready: '.($campaign['name'] ?? $label);
                if (! empty($campaign['url'])) {
                    $lines[] = (string) $campaign['url'];
                }
                $lines[] = 'Status: '.($campaign['status_label'] ?? 'ready');
            } else {
                $lines[] = 'No campaign created for '.$label.'.';
            }
            $lines[] = '';
        }

        $lines[] = 'First messages are personalized from profile/company research.';
        $lines[] = 'LinkedIn sends DM only after invite acceptance.';
        $lines[] = 'Goal: start a conversation and get a reply.';

        return trim(implode("\n", $lines));
    }

    private function workflowReport(): string
    {
        $workflow = is_array(self::$execution['workflow'] ?? null) ? self::$execution['workflow'] : [];
        $id = (int) ($workflow['id'] ?? 0);
        $status = (string) ($workflow['status'] ?? 'planned');
        $goal = trim((string) ($workflow['goal'] ?? ''));
        $steps = is_array($workflow['steps'] ?? null) ? $workflow['steps'] : [];

        $lines = [];
        $lines[] = $id > 0 ? "Workflow #{$id} is {$status}." : "Workflow is {$status}.";
        if ($goal !== '') {
            $lines[] = "Goal: {$goal}.";
        }
        if ($steps !== []) {
            $lines[] = '';
            $lines[] = 'Planned steps:';
            foreach (array_slice($steps, 0, 8) as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }
                $label = trim((string) ($step['step_key'] ?? $step['tool_name'] ?? 'step'));
                $lines[] = ($index + 1).'. '.$label;
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param  list<array<string, mixed>>  $channelResults
     * @return list<array{channel:string, total_leads:int, list_name:?string, samples:list<string>}>
     */
    private function channelSnapshots(array $channelResults): array
    {
        $out = [];
        foreach ($channelResults as $channel => $result) {
            if (! is_array($result)) {
                continue;
            }
            $best = is_array($result['best_match'] ?? null) ? $result['best_match'] : [];
            $samples = [];
            $raw = $result['sample_profiles'] ?? $best['sample_profiles'] ?? [];
            if (is_array($raw)) {
                foreach (array_slice($raw, 0, 5) as $profile) {
                    if (! is_array($profile)) {
                        continue;
                    }
                    $name = trim((string) ($profile['name'] ?? $profile['full_name'] ?? $profile['username'] ?? ''));
                    $headline = trim((string) ($profile['headline'] ?? $profile['title'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $samples[] = $headline !== '' ? $name.' — '.$headline : $name;
                }
            }

            $out[] = [
                'channel' => (string) $channel,
                'total_leads' => (int) ($best['total_leads'] ?? $result['total_leads_in_matches'] ?? 0),
                'list_name' => isset($best['list_name']) ? (string) $best['list_name'] : null,
                'samples' => $samples,
                'error' => isset($result['failure_reason']) ? (string) $result['failure_reason'] : null,
            ];
        }

        return $out;
    }
}
