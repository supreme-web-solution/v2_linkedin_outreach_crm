<?php

namespace App\V2\Ai\Services;

use App\Models\AiMessage;
use App\Models\AiWorkflowRun;

/**
 * Posts durable workflow progress updates into the Command Center conversation.
 */
class WorkflowConversationNotifier
{
    public function notify(AiWorkflowRun $run, string $content): void
    {
        if (! $run->conversation_id || trim($content) === '') {
            return;
        }

        AiMessage::query()->create([
            'conversation_id' => $run->conversation_id,
            'role' => 'assistant',
            'content' => trim($content),
            'meta' => [
                'workflow_run_id' => $run->id,
                'workflow_status' => $run->status,
                'source' => 'workflow_runtime',
                'channel' => 'web',
            ],
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

            $this->notify($run, implode("\n", array_filter([
                "✅ Workflow #{$run->id} finished discovery.",
                $platforms ? "Platforms searched: {$platforms}." : null,
                $savedLine,
                'Next: tell me to **start relevant conversations** for this list and I will stage reply-first outreach for Review & Launch.',
            ])));

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

        $requested = (int) ($plan['constraints']['target_count'] ?? $meta['latest_state']['requested_quantity'] ?? 0);
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
     */
    private function prospectCountForDisplay(array $meta): int
    {
        $fromState = (int) ($meta['latest_state']['intersection_eligible_count'] ?? 0);
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
}
