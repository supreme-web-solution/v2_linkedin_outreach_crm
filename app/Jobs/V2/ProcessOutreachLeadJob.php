<?php

namespace App\Jobs\V2;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\Models\V2OutreachRun;
use App\V2\Outreach\OutreachActivityLogger;
use App\V2\Outreach\OutreachChannelGuard;
use App\V2\Outreach\OutreachCompletionService;
use App\V2\Outreach\OutreachConcurrencyLimiter;
use App\V2\Outreach\OutreachConditionEvaluator;
use App\V2\Outreach\OutreachSendProof;
use App\V2\Outreach\OutreachSequenceResolver;
use App\V2\Outreach\OutreachStepExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

        $userId = (int) $campaign->user_id;
        $limiter = app(OutreachConcurrencyLimiter::class);
        $leaseId = $limiter->acquire($userId);
        if ($leaseId === null) {
            $delaySeconds = random_int(20, 40);
            if (Cache::add('outreach:concurrency-notice:'.$campaign->id, 1, now()->addMinutes(30))) {
                $max = $limiter->maxInFlight();
                $logger->log(
                    $campaign->id,
                    null,
                    $this->outreachRunId,
                    null,
                    'scheduled',
                    "Pacing active — up to {$max} leads run at once to protect your LinkedIn account. Other leads wait automatically.",
                );
            }

            self::dispatch($this->outreachCampaignId, $this->outreachLeadId, $this->outreachRunId)
                ->delay(now()->addSeconds($delaySeconds));

            return;
        }

        try {
            $this->processLeadWithSlot(
                $campaign,
                $lead,
                $progress,
                $resolver,
                $executor,
                $logger,
                $completion,
                $guard,
                $conditionEvaluator,
            );
        } finally {
            $limiter->release($userId, $leaseId);
        }
    }

    private function processLeadWithSlot(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        V2OutreachLeadProgress $progress,
        OutreachSequenceResolver $resolver,
        OutreachStepExecutor $executor,
        OutreachActivityLogger $logger,
        OutreachCompletionService $completion,
        OutreachChannelGuard $guard,
        OutreachConditionEvaluator $conditionEvaluator,
    ): void {
        $run = $this->outreachRunId ? V2OutreachRun::query()->find($this->outreachRunId) : null;
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];

        if ($this->resolvePendingSendTimeout($campaign, $lead, $progress, $nodes, $resolver, $logger, $run)) {
            return;
        }

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
            $reason = (string) ($result['payload']['reason'] ?? 'daily_limit');
            $isEscalated = ! empty($result['payload']['escalated']) || str_starts_with($reason, 'escalated_');
            $isTemp = str_starts_with($reason, 'temporary_');
            $channel = (string) ($result['payload']['channel'] ?? $node['channel'] ?? 'linkedin');
            $platform = app(\App\V2\Services\UnipileTemporaryLimitGuard::class)->platformLabel($channel);

            $deferMessage = match (true) {
                $isEscalated => "{$platform} is still limiting this account — \"{$nodeLabel}\" for {$lead->full_name} paused until ".$runAt->diffForHumans().' (protects your account).',
                $isTemp => "{$platform} temporary limit — \"{$nodeLabel}\" for {$lead->full_name} retries ".$runAt->diffForHumans().'.',
                default => "Daily {$platform} limit reached — \"{$nodeLabel}\" for {$lead->full_name} resumes ".$runAt->diffForHumans().'.',
            };

            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                $node,
                'scheduled',
                $deferMessage,
                $result['payload'] ?? [],
            );

            $lead->update(['status' => 'pending']);
            // Same node retries later; keys are not advanced.
            $progress->update(['next_run_at' => $runAt, 'run_status' => 0]);
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

        if ($status === 'skipped') {
            $skipReason = (string) ($result['error_message'] ?? 'Missing contact info for this step.');
            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                $node,
                'skipped',
                "Skipped \"{$nodeLabel}\" for {$lead->full_name} — {$skipReason}",
                $result['payload'] ?? [],
            );

            if (OutreachSendProof::nodeIsOutboundSend($node)) {
                $lead->update(['status' => 'skipped']);
                $progress->update(['run_status' => 9, 'next_run_at' => null]);

                return;
            }

            $this->advanceAfterStep($campaign, $lead, $progress, $run, $node, $nodes, $result, $resolver, $logger, $completion, $completed, $nodeKey, $nodeLabel, 'skipped');

            return;
        }

        if ($status === 'awaiting_send_confirmation') {
            $channel = (string) ($node['channel'] ?? 'linkedin');
            $channelState = is_array($progress->channel_state) ? $progress->channel_state : [];
            $channelState[$channel] = array_merge(
                is_array($channelState[$channel] ?? null) ? $channelState[$channel] : [],
                [
                    'pending_confirmation' => [
                        'node_key' => $nodeKey,
                        'chat_id' => (string) ($result['payload']['chat_id'] ?? ''),
                        'conversation_id' => (int) ($result['payload']['conversation_id'] ?? 0),
                    ],
                    'sent_at' => now()->toIso8601String(),
                ],
            );

            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                $node,
                'pending',
                "Sent \"{$nodeLabel}\" for {$lead->full_name} — waiting for delivery confirmation.",
                $result['payload'] ?? [],
            );

            $lead->update(['status' => 'running']);
            $progress->update([
                'channel_state' => $channelState,
                'next_run_at' => now()->addMinutes(10),
            ]);

            self::dispatch($campaign->id, $lead->id, $run?->id)->delay(now()->addMinutes(10));

            return;
        }

        if ($status === 'completed') {
            $logStatus = OutreachSendProof::nodeIsOutboundSend($node) ? 'sent' : 'completed';
            $logMessage = $logStatus === 'sent'
                ? "Confirmed sent \"{$nodeLabel}\" for {$lead->full_name}."
                : "Completed \"{$nodeLabel}\" for {$lead->full_name}.";

            $logger->log($campaign->id, $lead->id, $run?->id, $node, $logStatus, $logMessage, $result['payload'] ?? []);
            $this->advanceAfterStep($campaign, $lead, $progress, $run, $node, $nodes, $result, $resolver, $logger, $completion, $completed, $nodeKey, $nodeLabel, 'completed');

            return;
        }

        $error = (string) ($result['error_message'] ?? 'Step failed');

        $tempLimit = app(\App\V2\Services\UnipileTemporaryLimitGuard::class);
        if ($tempLimit->isTemporaryLimit($error)) {
            $channel = (string) ($node['channel'] ?? 'linkedin');
            if (! \App\V2\Services\UnipileTemporaryLimitGuard::supportsChannel($channel)) {
                $channel = 'linkedin';
            }
            $deferred = $tempLimit->deferredResult(
                (int) $campaign->user_id,
                $channel,
                $error,
            );
            $runAt = $deferred['next_run_at'];
            $escalated = ! empty($deferred['payload']['escalated']);
            $platform = $tempLimit->platformLabel($channel);
            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                $node,
                'scheduled',
                $escalated
                    ? "{$platform} is still limiting this account — \"{$nodeLabel}\" for {$lead->full_name} paused until ".$runAt->diffForHumans().' (protects your account).'
                    : "{$platform} temporary limit — \"{$nodeLabel}\" for {$lead->full_name} retries ".$runAt->diffForHumans().'.',
                $deferred['payload'] ?? [],
            );
            $lead->update(['status' => 'pending']);
            $progress->update(['next_run_at' => $runAt, 'run_status' => 0]);
            self::dispatch($campaign->id, $lead->id, $run?->id)->delay($runAt);

            return;
        }

        $logger->log($campaign->id, $lead->id, $run?->id, $node, 'failed', "Failed \"{$nodeLabel}\": {$error}");
        $lead->update(['status' => 'error']);
        $progress->update(['run_status' => 9, 'next_run_at' => null]);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $result
     * @param  array<int, int>  $completed
     */
    private function advanceAfterStep(
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
        array $completed,
        int $nodeKey,
        string $nodeLabel,
        string $outcome,
    ): void {
        $completed[] = $nodeKey;
        $nextKey = $resolver->resolveNextNodeKey($nodes, $nodeKey, $progress->acceptance_status);

        if (($result['payload']['halt'] ?? false) === true || $nextKey === null) {
            if ($outcome === 'skipped' && OutreachSendProof::nodeIsOutboundSend($node)) {
                return;
            }

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
    }

    /**
     * Confirm a send that was waiting on Unipile webhook delivery proof, then advance the sequence.
     *
     * @param  array<string, mixed>  $confirmation
     */
    public static function confirmPendingSend(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        V2OutreachLeadProgress $progress,
        array $confirmation,
        ?string $providerMessageId = null,
    ): void {
        $nodeKey = (int) ($confirmation['node_key'] ?? 0);
        if ($nodeKey <= 0) {
            return;
        }

        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $resolver = app(OutreachSequenceResolver::class);
        $logger = app(OutreachActivityLogger::class);
        $completion = app(OutreachCompletionService::class);
        $node = $resolver->findNodeByKey($nodes, $nodeKey);
        if (! $node) {
            return;
        }

        $channel = (string) ($node['channel'] ?? '');
        $channelState = is_array($progress->channel_state) ? $progress->channel_state : [];
        if ($channel !== '') {
            $channelSlice = is_array($channelState[$channel] ?? null) ? $channelState[$channel] : [];
            unset($channelSlice['pending_confirmation']);
            $channelSlice['confirmed_sent'] = true;
            $channelSlice['provider_message_id'] = $providerMessageId;
            $channelSlice['confirmed_at'] = now()->toIso8601String();
            $channelState[$channel] = $channelSlice;
        }

        $completed = is_array($progress->completed_keys) ? $progress->completed_keys : [];
        $nodeLabel = $resolver->nodeLabel($node);

        $logger->log(
            $campaign->id,
            $lead->id,
            null,
            $node,
            'sent',
            "Confirmed sent \"{$nodeLabel}\" for {$lead->full_name}.",
            array_filter(['provider_message_id' => $providerMessageId]),
        );

        $runId = null;
        $nextKey = $resolver->resolveNextNodeKey($nodes, $nodeKey, $progress->acceptance_status);
        $completed[] = $nodeKey;

        if ($nextKey === null) {
            $lead->update(['status' => 'done']);
            $progress->update([
                'current_node_key' => $nodeKey,
                'next_node_key' => self::SEQUENCE_COMPLETE_KEY,
                'completed_keys' => array_values(array_unique($completed)),
                'channel_state' => $channelState,
                'run_status' => 4,
                'next_run_at' => null,
            ]);
            $logger->log($campaign->id, $lead->id, null, null, 'completed', "Sequence finished for {$lead->full_name}.");
            $completion->maybeFinish($campaign, null);

            return;
        }

        $progress->update([
            'current_node_key' => $nodeKey,
            'next_node_key' => $nextKey,
            'completed_keys' => array_values(array_unique($completed)),
            'channel_state' => $channelState,
            'next_run_at' => null,
            'run_status' => max((int) $progress->run_status, 1),
        ]);

        self::dispatch($campaign->id, $lead->id, $runId)->delay(now()->addSeconds(3));
    }

    /**
     * Fail the step when Unipile never confirmed delivery within the wait window.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function resolvePendingSendTimeout(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        V2OutreachLeadProgress $progress,
        array $nodes,
        OutreachSequenceResolver $resolver,
        OutreachActivityLogger $logger,
        ?V2OutreachRun $run,
    ): bool {
        $nodeKey = (int) $progress->next_node_key;
        if ($nodeKey <= 0 || $nodeKey === self::SEQUENCE_COMPLETE_KEY) {
            return false;
        }

        $node = $resolver->findNodeByKey($nodes, $nodeKey);
        if (! $node || ! OutreachSendProof::nodeIsOutboundSend($node)) {
            return false;
        }

        $channel = (string) ($node['channel'] ?? '');
        $channelState = is_array($progress->channel_state) ? $progress->channel_state : [];
        $pending = $channelState[$channel]['pending_confirmation'] ?? null;
        if (! is_array($pending) || (int) ($pending['node_key'] ?? 0) !== $nodeKey) {
            return false;
        }

        if ($progress->next_run_at !== null && $progress->next_run_at->isFuture()) {
            return true;
        }

        $nodeLabel = $resolver->nodeLabel($node);
        $logger->log(
            $campaign->id,
            $lead->id,
            $run?->id,
            $node,
            'failed',
            "Delivery not confirmed for \"{$nodeLabel}\" — no delivery confirmation received.",
        );
        $lead->update(['status' => 'error']);
        $progress->update(['run_status' => 9, 'next_run_at' => null]);

        return true;
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
