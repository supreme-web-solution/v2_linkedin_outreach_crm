<?php

namespace App\V2\Ai\Services;

use App\Models\AiWorkflowRun;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Posts durable workflow progress updates into the Command Center conversation.
 * Mirrors completion messages to linked WhatsApp when configured.
 */
class WorkflowConversationNotifier
{
    public function __construct(
        private readonly CommandCenterPushService $push,
    ) {}

    public function notify(AiWorkflowRun $run, string $content, ?string $whatsappBody = null): void
    {
        if (! $run->conversation_id || trim($content) === '') {
            return;
        }

        $user = User::query()->find($run->user_id);
        $orgId = (int) $run->organization_id;
        if (! $user || $orgId <= 0) {
            return;
        }

        $this->push->postAssistant($user, $orgId, trim($content), [
            'source' => 'workflow_runtime',
            'workflow_run_id' => $run->id,
            'workflow_status' => $run->status,
            'whatsapp_body' => $whatsappBody ?? $this->plainWithLinks($content),
        ]);
    }

    public function notifyCompleted(AiWorkflowRun $run): void
    {
        $result = is_array($run->result) ? $run->result : [];
        $plan = is_array($run->plan) ? $run->plan : [];
        $outcome = (string) ($plan['required_outcome'] ?? '');
        $meta = is_array($run->meta) ? $run->meta : [];
        $eligible = (int) ($result['eligible'] ?? 0);
        $discoveredThisRun = (int) ($result['discovered_this_run'] ?? $meta['cumulative_candidate_delta'] ?? $eligible);
        $requested = (int) ($result['requested'] ?? 0);
        $platforms = is_array($meta['platforms_searched'] ?? null) ? implode(' + ', $meta['platforms_searched']) : null;
        $savedCount = $discoveredThisRun > 0 ? $discoveredThisRun : $eligible;

        if ($outcome === 'find_only') {
            $savedLine = $requested > 0
                ? "Saved {$savedCount} new prospect(s)".($savedCount !== $requested ? " (requested {$requested})" : '').' to Leads.'
                : "Saved {$savedCount} prospect(s) to Leads.";

            [$content, $whatsappBody] = $this->buildFindOnlyCompletion(
                $run,
                $meta,
                $platforms,
                $savedLine,
            );
            $this->notify($run, $content, $whatsappBody);

            return;
        }

        if ($outcome === 'setup_only') {
            $approvalId = (int) ($result['approval_id'] ?? 0);

            $this->notify($run, implode("\n", array_filter([
                "✅ Workflow #{$run->id} staged your outreach plan.",
                $approvalId > 0 ? "Open Review & Launch for plan #{$approvalId} — nothing sends until you LAUNCH." : null,
            ])));

            return;
        }

        if ($outcome === 'send_now') {
            $campaignId = (int) ($result['outreach_campaign_id'] ?? 0);

            $this->notify($run, implode("\n", array_filter([
                "✅ Workflow #{$run->id} completed.",
                $campaignId > 0 ? "Outreach campaign #{$campaignId} is active — first messages personalize per lead (reply-first, no pitch)." : 'Outreach is active.',
            ])));
        }
    }

    public function notifyDiscoveryComplete(AiWorkflowRun $run): void
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        if ($meta['discovery_notified'] ?? false) {
            return;
        }

        $plan = is_array($run->plan) ? $run->plan : [];
        $outcome = (string) ($plan['required_outcome'] ?? '');
        if ($outcome === 'find_only') {
            return;
        }

        $latestState = is_array($meta['latest_state'] ?? null) ? $meta['latest_state'] : [];
        $requested = (int) ($plan['constraints']['target_count'] ?? $latestState['requested_quantity'] ?? 0);
        $discovered = (int) ($meta['cumulative_candidate_delta'] ?? 0);
        $platforms = is_array($meta['platforms_searched'] ?? null) ? implode(' + ', $meta['platforms_searched']) : null;

        $this->notify($run, implode("\n", array_filter([
            "✅ Workflow #{$run->id} — found {$discovered}".($requested > 0 ? " of {$requested}" : '').' prospects.',
            $platforms ? "Saved to Leads from {$platforms}." : 'Saved to Leads.',
            'Staging reply-first outreach for Review & Launch…',
        ])));

        $run->update(['meta' => array_merge($meta, ['discovery_notified' => true])]);

        if ($run->conversation_id && $run->fresh()?->status === 'running') {
            $conversation = \App\Models\AiConversation::query()->find($run->conversation_id);
            if ($conversation) {
                app(WebChatProcessingService::class)->update(
                    $conversation,
                    'workflow',
                    'Workflow #'.$run->id.' — staging outreach for Review & Launch…',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0:string,1:string}
     */
    private function buildFindOnlyCompletion(
        AiWorkflowRun $run,
        array $meta,
        ?string $platforms,
        string $savedLine,
    ): array {
        $channelLists = is_array($meta['discovery_channel_lists'] ?? null) ? $meta['discovery_channel_lists'] : [];
        $samples = is_array($meta['sample_profiles'] ?? null) ? $meta['sample_profiles'] : [];

        $webLines = array_filter([
            "✅ Workflow #{$run->id} finished discovery.",
            $platforms ? "Platforms searched: {$platforms}." : null,
            $savedLine,
        ]);
        $waLines = array_filter([
            "Workflow #{$run->id} finished discovery.",
            $platforms ? "Platforms: {$platforms}." : null,
            $savedLine,
        ]);

        [$webListLines, $waListLines] = $this->formatListLinks($channelLists, $meta);
        $webLines = array_merge($webLines, $webListLines);
        $waLines = array_merge($waLines, $waListLines);

        [$webSampleLines, $waSampleLines] = $this->formatSampleProfiles($samples);
        if ($webSampleLines !== []) {
            $webLines[] = 'Sample prospects:';
            $webLines = array_merge($webLines, $webSampleLines);
            $waLines[] = 'Samples:';
            $waLines = array_merge($waLines, $waSampleLines);
        }

        $webLines[] = 'Next: tell me to **start relevant conversations** for this list and I will stage reply-first outreach for Review & Launch.';
        $waLines[] = 'Reply here to start conversations for this list.';

        return [implode("\n", $webLines), implode("\n", $waLines)];
    }

    /**
     * @param  list<array<string, mixed>>  $channelLists
     * @param  array<string, mixed>  $meta
     * @return array{0:list<string>,1:list<string>}
     */
    private function formatListLinks(array $channelLists, array $meta): array
    {
        $web = [];
        $wa = [];

        if ($channelLists !== []) {
            foreach ($channelLists as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $hash = trim((string) ($row['list_hash'] ?? ''));
                if ($hash === '') {
                    continue;
                }
                $channel = ucfirst(trim((string) ($row['channel'] ?? 'Leads')));
                $count = (int) ($row['total_leads'] ?? 0);
                $url = url('/leads?list='.$hash);
                $label = $count > 0 ? "{$channel} ({$count})" : $channel;
                $web[] = "• [{$label}]({$url})";
                $wa[] = "• {$label}: {$url}";
            }

            return [$web, $wa];
        }

        $listHash = trim((string) ($meta['discovery_list_hash'] ?? ''));
        $url = $listHash !== '' ? url('/leads?list='.$listHash) : url('/leads');
        $web[] = '[Open list in Leads]('.$url.')';
        $wa[] = 'List: '.$url;

        return [$web, $wa];
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @return array{0:list<string>,1:list<string>}
     */
    private function formatSampleProfiles(array $profiles): array
    {
        $web = [];
        $wa = [];

        foreach (array_slice($profiles, 0, 5) as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $name = trim((string) ($profile['name'] ?? $profile['full_name'] ?? $profile['username'] ?? ''));
            if ($name === '') {
                continue;
            }

            $username = trim((string) ($profile['username'] ?? ''));
            if ($username !== '' && ! str_starts_with($name, '@')) {
                $name = '@'.$username.' — '.$name;
            }

            $headline = trim((string) ($profile['headline'] ?? $profile['title'] ?? ''));
            $url = trim((string) ($profile['profile_url'] ?? ''));
            if ($url === '' && $username !== '') {
                $platform = strtolower((string) ($profile['platform'] ?? ''));
                $url = $platform === 'instagram'
                    ? 'https://www.instagram.com/'.$username
                    : ($username !== '' ? 'https://www.linkedin.com/in/'.$username : '');
            }

            $label = $headline !== '' ? "{$name} — {$headline}" : $name;
            if ($url !== '') {
                $web[] = '• ['.$label.']('.$url.')';
                $wa[] = '• '.$label."\n  ".$url;
            } else {
                $web[] = '• '.$label;
                $wa[] = '• '.$label;
            }
        }

        return [$web, $wa];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function prospectCountForDisplay(array $meta): int
    {
        $latestState = is_array($meta['latest_state'] ?? null) ? $meta['latest_state'] : [];
        $fromState = (int) ($latestState['intersection_eligible_count'] ?? 0);
        $fromDelta = (int) ($meta['cumulative_candidate_delta'] ?? 0);
        $lists = is_array($meta['discovery_lists'] ?? null) ? $meta['discovery_lists'] : [];
        $fromLists = $lists === [] ? 0 : array_sum(array_map(
            fn (array $row) => (int) ($row['total_leads'] ?? 0),
            $lists,
        ));

        return max($fromState, $fromDelta, $fromLists);
    }

    public function notifyWaitingForApproval(AiWorkflowRun $run): void
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $approvalIds = is_array($meta['approval_ids'] ?? null) ? $meta['approval_ids'] : [];
        if ($approvalIds === [] && (int) ($meta['approval_id'] ?? 0) > 0) {
            $approvalIds = [(int) $meta['approval_id']];
        }
        $eligible = $this->prospectCountForDisplay($meta);
        $platforms = is_array($meta['platforms_searched'] ?? null) ? implode(' + ', $meta['platforms_searched']) : null;

        $launchLines = [];
        foreach ($approvalIds as $id) {
            $launchLines[] = '• Send **LAUNCH '.$id.'** to start personalized conversations on that channel';
        }

        if ($run->conversation_id) {
            $conversation = \App\Models\AiConversation::query()->find($run->conversation_id);
            if ($conversation) {
                app(WebChatProcessingService::class)->clearAll($conversation);
            }
        }

        $this->notify($run, implode("\n", array_filter([
            "✅ Workflow #{$run->id} — discovery complete. Reply-first outreach is ready for Review & Launch.",
            $platforms ? "Lists saved from: {$platforms} ({$eligible} prospects)." : "Prospects ready: {$eligible}.",
            $launchLines !== [] ? implode("\n", $launchLines) : 'Open Review & Launch to approve the staged plan.',
            'Nothing sends until you LAUNCH. First messages earn a reply — no SociFusion pitch yet.',
        ])));
    }

    private function plainWithLinks(string $markdown): string
    {
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1: $2', $markdown) ?? $markdown;
        $text = str_replace(['**', '__', '`'], '', $text);

        return Str::limit(trim($text), 900, '…');
    }
}
