<?php

namespace App\V2\Outreach;

use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\Models\V2OutreachRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class OutreachProgressReconciler
{
    public function __construct(
        private readonly OutreachSequenceResolver $resolver,
        private readonly OutreachActivityLogger $logger,
    ) {}

    /**
     * Re-sync lead progress after the sequence changes on a live campaign.
     *
     * @return array{dispatched: int, adjusted: int}
     */
    public function reconcile(V2OutreachCampaign $campaign): array
    {
        if (! in_array($campaign->status, ['active', 'running'], true)) {
            return ['dispatched' => 0, 'adjusted' => 0];
        }

        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        if ($nodes === []) {
            return ['dispatched' => 0, 'adjusted' => 0];
        }

        $runId = V2OutreachRun::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->where('status', 'running')
            ->latest('id')
            ->value('id');

        $dispatched = 0;
        $adjusted = 0;

        $leads = V2OutreachLead::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'running', 'done', 'error'])
            ->with('progress')
            ->get();

        foreach ($leads as $lead) {
            if ($this->reconcileLead($campaign, $lead, $nodes, $runId)) {
                $adjusted++;
            }

            if ($this->dispatchIfReady($campaign, $lead, $runId)) {
                $dispatched++;
            }
        }

        if ($adjusted > 0 || $dispatched > 0) {
            Log::info('[Outreach] Sequence reconciled after edit', [
                'campaign_id' => $campaign->id,
                'adjusted' => $adjusted,
                'dispatched' => $dispatched,
            ]);
        }

        return ['dispatched' => $dispatched, 'adjusted' => $adjusted];
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function reconcileLead(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        array $nodes,
        ?int $runId,
    ): bool {
        $progress = $lead->progress ?? V2OutreachLeadProgress::query()->firstOrCreate(
            ['outreach_campaign_id' => $campaign->id, 'outreach_lead_id' => $lead->id],
            ['current_node_key' => 0, 'next_node_key' => 1, 'run_status' => 0, 'channel_state' => []]
        );

        $acceptance = $progress->acceptance_status;
        $path = $this->resolver->executionPath($nodes, $acceptance ?? true);
        $completed = is_array($progress->completed_keys) ? $progress->completed_keys : [];
        $originalNextKey = (int) $progress->next_node_key;
        $originalRunAt = $progress->next_run_at?->copy();

        $resumeKey = $this->resumeNodeKey($path, $completed);
        if ($resumeKey === null) {
            if ((int) $progress->next_node_key !== ProcessOutreachLeadJob::SEQUENCE_COMPLETE_KEY) {
                $progress->update([
                    'next_node_key' => ProcessOutreachLeadJob::SEQUENCE_COMPLETE_KEY,
                    'next_run_at' => null,
                    'run_status' => 4,
                ]);
                $lead->update(['status' => 'done']);

                return true;
            }

            return false;
        }

        $nextKey = $originalNextKey;
        if ($nextKey === ProcessOutreachLeadJob::SEQUENCE_COMPLETE_KEY || ! $this->keyInPath($path, $nextKey)) {
            $nextKey = $resumeKey;
        } elseif (! in_array($nextKey, $completed, true) && $nextKey !== $resumeKey) {
            // Pointer ahead of completed work — keep resume point so new upstream steps run.
            $nextKey = $resumeKey;
        }

        $nextRunAt = $this->resolveNextRunAt($path, $completed, $nextKey);

        $changed = $nextKey !== $originalNextKey
            || $this->runAtChanged($originalRunAt, $nextRunAt)
            || ($originalRunAt !== null && $originalRunAt->isFuture() && $nextRunAt === null);

        if (! $changed) {
            return false;
        }

        $updates = [
            'next_node_key' => $nextKey,
            'next_run_at' => $nextRunAt,
        ];

        if ($lead->status === 'done') {
            $updates['run_status'] = max((int) $progress->run_status, 1);
            $lead->update(['status' => 'pending']);
        } elseif ($lead->status === 'running' || $lead->status === 'error') {
            $lead->update(['status' => 'pending']);
        }

        $progress->update($updates);

        $this->logger->log(
            $campaign->id,
            $lead->id,
            $runId,
            $this->resolver->findNodeByKey($nodes, $nextKey),
            'rescheduled',
            sprintf(
                'Sequence updated — next step rescheduled for %s.',
                $lead->full_name ?? 'lead',
            ),
            [
                'next_node_key' => $nextKey,
                'next_run_at' => $nextRunAt?->toIso8601String(),
            ],
        );

        return true;
    }

    private function dispatchLead(V2OutreachCampaign $campaign, V2OutreachLead $lead, ?int $runId): bool
    {
        $progress = V2OutreachLeadProgress::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->where('outreach_lead_id', $lead->id)
            ->first();

        if (! $progress) {
            return false;
        }

        if ((int) $progress->next_node_key === ProcessOutreachLeadJob::SEQUENCE_COMPLETE_KEY) {
            return false;
        }

        $when = $progress->next_run_at;
        $job = ProcessOutreachLeadJob::dispatch($campaign->id, $lead->id, $runId);

        if ($when !== null && $when->isFuture()) {
            $job->delay($when);
        }

        if ($lead->status !== 'running') {
            $lead->update(['status' => 'pending']);
        }

        return true;
    }

    private function dispatchIfReady(V2OutreachCampaign $campaign, V2OutreachLead $lead, ?int $runId): bool
    {
        $progress = V2OutreachLeadProgress::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->where('outreach_lead_id', $lead->id)
            ->first();

        if (! $progress || (int) $progress->next_node_key === ProcessOutreachLeadJob::SEQUENCE_COMPLETE_KEY) {
            return false;
        }

        if ($progress->next_run_at !== null && $progress->next_run_at->isFuture()) {
            return false;
        }

        if ($lead->status === 'running') {
            $lead->update(['status' => 'pending']);
        }

        return $this->dispatchLead($campaign, $lead->fresh() ?? $lead, $runId);
    }

    /**
     * @param  array<int, array<string, mixed>>  $path
     * @param  array<int, int>  $completed
     */
    private function resumeNodeKey(array $path, array $completed): ?int
    {
        foreach ($path as $node) {
            if (($node['type'] ?? '') === 'end') {
                continue;
            }

            $key = (int) ($node['key'] ?? 0);
            if ($key === 0) {
                continue;
            }

            if (! in_array($key, $completed, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $path
     * @param  array<int, int>  $completed
     */
    private function resolveNextRunAt(array $path, array $completed, int $nextKey): ?Carbon
    {
        $previous = $this->nodeBeforeKey($path, $nextKey);
        if ($previous === null) {
            return null;
        }

        if (($previous['type'] ?? '') !== 'delay') {
            return null;
        }

        $prevKey = (int) ($previous['key'] ?? 0);
        if ($prevKey === 0 || ! in_array($prevKey, $completed, true)) {
            return null;
        }

        $runAt = now()->addSeconds($this->resolver->delaySeconds($previous));

        return $runAt->isFuture() ? $runAt : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $path
     * @return array<string, mixed>|null
     */
    private function nodeBeforeKey(array $path, int $targetKey): ?array
    {
        $previous = null;

        foreach ($path as $node) {
            if ((int) ($node['key'] ?? 0) === $targetKey) {
                return $previous;
            }

            if (($node['type'] ?? '') !== 'end') {
                $previous = $node;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $path
     */
    private function keyInPath(array $path, int $key): bool
    {
        foreach ($path as $node) {
            if ((int) ($node['key'] ?? 0) === $key) {
                return true;
            }
        }

        return false;
    }

    private function runAtChanged(?Carbon $before, ?Carbon $after): bool
    {
        if ($before === null && $after === null) {
            return false;
        }

        if ($before === null || $after === null) {
            return true;
        }

        return ! $before->equalTo($after);
    }
}
