<?php

namespace App\Jobs\V2;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\Models\V2OutreachRun;
use App\V2\Outreach\OutreachActivityLogger;
use App\V2\Outreach\OutreachChannelGuard;
use App\V2\Outreach\OutreachCompletionService;
use App\V2\Outreach\OutreachConditionEvaluator;
use App\V2\Outreach\OutreachSequenceResolver;
use App\V2\Outreach\OutreachStepExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOutreachLeadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const SEQUENCE_COMPLETE_KEY = 0;

    public int $tries = 2;

    public function __construct(
        public readonly int $outreachCampaignId,
        public readonly int $outreachLeadId,
        public readonly ?int $outreachRunId = null,
    ) {
        $this->onQueue('outreach');
    }

    public function handle(
        OutreachSequenceResolver $resolver,
        OutreachStepExecutor $executor,
        OutreachActivityLogger $logger,
        OutreachCompletionService $completion,
        OutreachChannelGuard $guard,
        OutreachConditionEvaluator $conditionEvaluator,
    ): void {
        $campaign = V2OutreachCampaign::query()->find($this->outreachCampaignId);
        if (! $campaign || ! in_array($campaign->status, ['active', 'running'], true)) {
            return;
        }

        $lead = V2OutreachLead::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->where('id', $this->outreachLeadId)
            ->first();

        if (! $lead || in_array($lead->status, ['done', 'skipped', 'replied'], true)) {
            return;
        }

        $progress = V2OutreachLeadProgress::query()->firstOrCreate(
            ['outreach_campaign_id' => $campaign->id, 'outreach_lead_id' => $lead->id],
            ['current_node_key' => 0, 'next_node_key' => 1, 'run_status' => 0, 'channel_state' => []]
        );

        if ($progress->next_run_at !== null && $progress->next_run_at->isFuture()) {
            self::dispatch($this->outreachCampaignId, $this->outreachLeadId, $this->outreachRunId)
                ->delay($progress->next_run_at);

            return;
        }

        if ($progress->next_run_at !== null && $progress->next_run_at->isPast()) {
            $progress->update(['next_run_at' => null]);
        }

        $run = $this->outreachRunId ? V2OutreachRun::query()->find($this->outreachRunId) : null;
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $nodeKey = (int) ($progress->next_node_key ?: 1);

        if ($nodeKey === self::SEQUENCE_COMPLETE_KEY) {
            return;
        }

        $node = $resolver->findNodeByKey($nodes, $nodeKey);
        if (! $node) {
            $top = $resolver->topLevelNodes($nodes);
            $node = $top[0] ?? null;
            $nodeKey = $node ? (int) ($node['key'] ?? 1) : 1;
        }

        if (! $node) {
            $lead->update(['status' => 'error']);
            $progress->update(['run_status' => 9]);

            return;
        }

        $lead->update(['status' => 'running']);
        $nodeLabel = $resolver->nodeLabel($node);
        $stepType = (string) ($node['type'] ?? 'action');

        $logger->log($campaign->id, $lead->id, $run?->id, $node, 'started', "Starting \"{$nodeLabel}\" for {$lead->full_name}.");

        try {
            if ($stepType === 'condition') {
                $this->handleCondition($campaign, $lead, $progress, $run, $node, $nodes, $resolver, $logger, $conditionEvaluator);

                return;
            }

            if ($stepType === 'end') {
                $this->markComplete($campaign, $lead, $progress, $run, $node, $logger, $completion);

                return;
            }

            $result = $executor->execute($campaign, $lead, $node);
            $this->applyResult($campaign, $lead, $progress, $run, $node, $nodes, $result, $resolver, $logger, $completion, $guard);
        } catch (Throwable $e) {
            if ($guard->isDisconnected($e)) {
                $channel = (string) ($node['channel'] ?? 'linkedin');
                $guard->handleChannelDisconnect((int) $campaign->user_id, $campaign->organization_id, $channel, $e->getMessage());
                $lead->update(['status' => 'pending']);
                $progress->update(['next_run_at' => null]);

                return;
            }

            $logger->log($campaign->id, $lead->id, $run?->id, $node, 'failed', "Failed \"{$nodeLabel}\": {$e->getMessage()}");
            $lead->update(['status' => 'error']);
            $progress->update(['run_status' => 9]);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $result
     */
    private function applyResult(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        V2OutreachLeadProgress $progress,
        ?V2OutreachRun $run,
        array $node,
        array $nodes,
        array $result,
        OutreachSequenceResolver $resolver,
        OutreachActivityLogger $logger,
        OutreachCompletionService $completion,
        OutreachChannelGuard $guard,
    ): void {
        $status = (string) ($result['status'] ?? 'failed');
        $nodeKey = (int) ($node['key'] ?? 0);
        $nodeLabel = $resolver->nodeLabel($node);
        $completed = is_array($progress->completed_keys) ? $progress->completed_keys : [];

        if ($status === 'channel_disconnected') {
            $channel = (string) ($result['payload']['channel'] ?? $node['channel'] ?? 'linkedin');
            $guard->handleChannelDisconnect((int) $campaign->user_id, $campaign->organization_id, $channel, (string) ($result['error_message'] ?? ''));
            $logger->log($campaign->id, $lead->id, $run?->id, $node, 'paused', "Paused — {$channel} disconnected.");
            $lead->update(['status' => 'pending']);
            $progress->update(['next_run_at' => null]);

            return;
        }

        if ($status === 'deferred') {
            $runAt = $result['next_run_at'] ?? now()->addDay()->startOfDay()->addMinutes(10);

            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                $node,
                'scheduled',
                "Daily limit reached — \"{$nodeLabel}\" for {$lead->full_name} resumes ".$runAt->diffForHumans().'.',
                $result['payload'] ?? [],
            );

            // Same node retries tomorrow; keys are not advanced.
            $progress->update(['next_run_at' => $runAt]);
            self::dispatch($campaign->id, $lead->id, $run?->id)->delay($runAt);

            return;
        }

        if ($status === 'scheduled') {
            $completed[] = $nodeKey;
            $nextKey = $resolver->resolveNextNodeKey($nodes, $nodeKey, $progress->acceptance_status);
            $runAt = $result['next_run_at'] ?? now()->addSeconds((int) ($result['payload']['wait_seconds'] ?? 3600));
            $progress->update([
                'current_node_key' => $nodeKey,
                'next_node_key' => $nextKey,
                'completed_keys' => array_values(array_unique($completed)),
                'next_run_at' => $runAt,
            ]);
            if ($nextKey !== null) {
                self::dispatch($campaign->id, $lead->id, $run?->id)->delay($runAt);
            }

            return;
        }

        if ($status === 'waiting') {
            $progress->update(['next_run_at' => now()->addHours(6)]);

            return;
        }

        if ($status === 'completed' || $status === 'skipped') {
            $logger->log($campaign->id, $lead->id, $run?->id, $node, $status, "Completed \"{$nodeLabel}\" for {$lead->full_name}.", $result['payload'] ?? []);
            $completed[] = $nodeKey;
            $nextKey = $resolver->resolveNextNodeKey($nodes, $nodeKey, $progress->acceptance_status);

            if (($result['payload']['halt'] ?? false) === true || $nextKey === null) {
                $this->markComplete($campaign, $lead, $progress, $run, $node, $logger, $completion, $completed);

                return;
            }

            $progress->update([
                'current_node_key' => $nodeKey,
                'next_node_key' => $nextKey,
                'completed_keys' => array_values(array_unique($completed)),
                'next_run_at' => null,
                'run_status' => max((int) $progress->run_status, 1),
            ]);
            self::dispatch($campaign->id, $lead->id, $run?->id)->delay(now()->addSeconds(3));

            return;
        }

        $error = (string) ($result['error_message'] ?? 'Step failed');
        $logger->log($campaign->id, $lead->id, $run?->id, $node, 'failed', "Failed \"{$nodeLabel}\": {$error}");
        $lead->update(['status' => 'error']);
        $progress->update(['run_status' => 9, 'next_run_at' => null]);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function handleCondition(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        V2OutreachLeadProgress $progress,
        ?V2OutreachRun $run,
        array $node,
        array $nodes,
        OutreachSequenceResolver $resolver,
        OutreachActivityLogger $logger,
        OutreachConditionEvaluator $conditionEvaluator,
    ): void {
        $acceptance = $conditionEvaluator->evaluate($progress, $node);
        if ($acceptance === null) {
            $conditionEvaluator->markConditionWaiting($progress);
            $logger->log($campaign->id, $lead->id, $run?->id, $node, 'waiting', 'Waiting for condition.');
            $progress->update(['next_run_at' => now()->addHours(6)]);
            self::dispatch($campaign->id, $lead->id, $run?->id)->delay(now()->addHours(6));

            return;
        }

        $nextKey = $resolver->resolveNextNodeKey($nodes, (int) ($node['key'] ?? 0), $acceptance);
        $completed = is_array($progress->completed_keys) ? $progress->completed_keys : [];
        $completed[] = (int) ($node['key'] ?? 0);

        $progress->update([
            'current_node_key' => (int) ($node['key'] ?? 0),
            'next_node_key' => $nextKey,
            'completed_keys' => array_values(array_unique($completed)),
            'next_run_at' => null,
            'meta' => array_merge(is_array($progress->meta) ? $progress->meta : [], ['condition_wait_since' => null]),
        ]);

        if ($nextKey !== null) {
            self::dispatch($campaign->id, $lead->id, $run?->id)->delay(now()->addSeconds(2));
        }
    }

    /**
     * @param  array<int, int>  $completed
     */
    private function markComplete(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        V2OutreachLeadProgress $progress,
        ?V2OutreachRun $run,
        array $node,
        OutreachActivityLogger $logger,
        OutreachCompletionService $completion,
        array $completed = [],
    ): void {
        if ($completed === []) {
            $completed = is_array($progress->completed_keys) ? $progress->completed_keys : [];
            $completed[] = (int) ($node['key'] ?? 0);
        }

        $lead->update(['status' => 'done']);
        $progress->update([
            'current_node_key' => (int) ($node['key'] ?? 0),
            'next_node_key' => self::SEQUENCE_COMPLETE_KEY,
            'completed_keys' => array_values(array_unique($completed)),
            'run_status' => 4,
            'next_run_at' => null,
        ]);

        $logger->log($campaign->id, $lead->id, $run?->id, null, 'completed', "Sequence finished for {$lead->full_name}.");
        $completion->maybeFinish($campaign, $run);
    }
}
