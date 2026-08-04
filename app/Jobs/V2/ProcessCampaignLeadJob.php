<?php

namespace App\Jobs\V2;

use App\Models\V2Campaign;
use App\Models\V2CampaignLead;
use App\Models\V2CampaignLeadProgress;
use App\Models\V2CampaignRun;
use App\V2\Campaign\CampaignActivityLogger;
use App\V2\Campaign\CampaignCompletionService;
use App\V2\Campaign\CampaignConcurrencyLimiter;
use App\V2\Campaign\CampaignLeadProfileService;
use App\V2\Campaign\CampaignLinkedInGuard;
use App\V2\Campaign\CampaignSequenceResolver;
use App\V2\Campaign\CampaignStepExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessCampaignLeadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Sentinel value when the lead has finished all sequence steps. */
    public const SEQUENCE_COMPLETE_KEY = 0;

    public int $tries = 2;

    public function __construct(
        public readonly int $campaignId,
        public readonly int $campaignLeadId,
        public readonly ?int $campaignRunId = null,
    ) {
        $this->onQueue('campaigns');
    }

    public function handle(
        CampaignSequenceResolver $resolver,
        CampaignStepExecutor $executor,
        CampaignActivityLogger $logger,
        CampaignLeadProfileService $profileService,
        CampaignCompletionService $completion,
        CampaignLinkedInGuard $linkedInGuard,
    ): void {
        Log::debug('[Campaign] ProcessCampaignLeadJob started', [
            'campaign_id' => $this->campaignId,
            'campaign_lead_id' => $this->campaignLeadId,
            'run_id' => $this->campaignRunId,
        ]);

        $campaign = V2Campaign::query()->find($this->campaignId);
        if (!$campaign || !in_array($campaign->status, ['active', 'running'], true)) {
            Log::debug('[Campaign] Job skipped — campaign missing or not running', [
                'campaign_id' => $this->campaignId,
                'status' => $campaign?->status,
            ]);

            return;
        }

        if ($linkedInGuard->isUserDisconnected((int) $campaign->user_id)) {
            Log::warning('[Campaign] Job skipped — LinkedIn disconnected', [
                'campaign_id' => $this->campaignId,
                'user_id' => $campaign->user_id,
            ]);
            $linkedInGuard->handleDisconnect((int) $campaign->user_id, $campaign->organization_id, 'LinkedIn disconnected before step execution.');

            return;
        }

        $lead = V2CampaignLead::query()
            ->where('campaign_id', $campaign->id)
            ->where('id', $this->campaignLeadId)
            ->first();

        if (!$lead || in_array($lead->status, ['done', 'skipped'], true)) {
            Log::debug('[Campaign] Job skipped — lead missing or finished', [
                'lead_id' => $this->campaignLeadId,
                'status' => $lead?->status,
            ]);

            return;
        }

        $progress = V2CampaignLeadProgress::query()->firstOrCreate(
            ['campaign_id' => $campaign->id, 'campaign_lead_id' => $lead->id],
            [
                'current_node_key' => 0,
                'next_node_key' => 1,
                'run_status' => 0,
            ]
        );

        if ((int) $progress->next_node_key === self::SEQUENCE_COMPLETE_KEY && (int) $progress->run_status === 4) {
            Log::debug('[Campaign] Job skipped — sequence already complete', ['lead_id' => $lead->id]);

            return;
        }

        if ($progress->next_run_at !== null && $progress->next_run_at->isFuture()) {
            Log::debug('[Campaign] Job skipped — waiting for scheduled time', [
                'lead_id' => $lead->id,
                'next_run_at' => $progress->next_run_at->toIso8601String(),
            ]);

            return;
        }

        if ($progress->next_run_at !== null && $progress->next_run_at->isPast()) {
            $progress->update(['next_run_at' => null]);
        }

        $userId = (int) $campaign->user_id;
        $limiter = app(CampaignConcurrencyLimiter::class);
        $leaseId = $limiter->acquire($userId);
        if ($leaseId === null) {
            $delaySeconds = random_int(20, 40);
            if (Cache::add('campaign:concurrency-notice:'.$campaign->id, 1, now()->addMinutes(30))) {
                $max = $limiter->maxInFlight();
                $logger->log(
                    $campaign->id,
                    null,
                    $this->campaignRunId,
                    null,
                    'scheduled',
                    "Pacing active — up to {$max} leads run at once to protect your LinkedIn account. Other leads wait automatically.",
                );
            }

            self::dispatch($this->campaignId, $this->campaignLeadId, $this->campaignRunId)
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
                $profileService,
                $completion,
                $linkedInGuard,
            );
        } finally {
            $limiter->release($userId, $leaseId);
        }
    }

    private function processLeadWithSlot(
        V2Campaign $campaign,
        V2CampaignLead $lead,
        V2CampaignLeadProgress $progress,
        CampaignSequenceResolver $resolver,
        CampaignStepExecutor $executor,
        CampaignActivityLogger $logger,
        CampaignLeadProfileService $profileService,
        CampaignCompletionService $completion,
        CampaignLinkedInGuard $linkedInGuard,
    ): void {
        $run = $this->campaignRunId
            ? V2CampaignRun::query()->find($this->campaignRunId)
            : null;

        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $nodeKey = (int) ($progress->next_node_key ?: 1);
        if ($nodeKey === self::SEQUENCE_COMPLETE_KEY) {
            Log::debug('[Campaign] Job skipped — no remaining steps', ['lead_id' => $lead->id]);

            return;
        }

        $completedKeys = is_array($progress->completed_keys) ? $progress->completed_keys : [];
        if (in_array($nodeKey, $completedKeys, true)) {
            $nextKey = $resolver->resolveNextNodeKey($nodes, $nodeKey, $progress->acceptance_status);
            Log::warning('[Campaign] Step already completed — advancing pointer', [
                'lead_id' => $lead->id,
                'node_key' => $nodeKey,
                'next_node_key' => $nextKey,
            ]);

            if ($nextKey === null) {
                $this->markSequenceComplete($campaign, $lead, $progress, $run, $nodeKey, $completedKeys, $logger, $completion);

                return;
            }

            $progress->update(['next_node_key' => $nextKey, 'next_run_at' => null]);
            self::dispatch($campaign->id, $lead->id, $run?->id)->delay(now()->addSeconds(2));

            return;
        }

        $node = $resolver->findNodeByKey($nodes, $nodeKey);

        if (!$node) {
            $topLevel = $resolver->topLevelNodes($nodes);
            $node = $topLevel[0] ?? null;
            $nodeKey = $node ? (int) ($node['key'] ?? 1) : 1;
        }

        if (!$node) {
            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                null,
                'failed',
                'No sequence steps configured for this campaign.',
            );
            $lead->update(['status' => 'error']);
            $progress->update(['run_status' => 9]);

            return;
        }

        $lead->update(['status' => 'running']);
        $stepType = $resolver->stepType($node);
        $nodeLabel = $resolver->nodeLabel($node);
        $nodeKey = (int) ($node['key'] ?? 0);

        Log::debug('[Campaign] Executing step', [
            'campaign_id' => $campaign->id,
            'lead_id' => $lead->id,
            'node_key' => $nodeKey,
            'step_type' => $stepType,
            'label' => $nodeLabel,
        ]);

        $resolved = $profileService->resolveRecipient($campaign, $lead);
        if ($resolved['provider_id'] !== '' && $resolved['provider_id'] !== $lead->provider_profile_id) {
            $lead->forceFill(['provider_profile_id' => $resolved['provider_id']])->save();
            Log::debug('[Campaign] Updated lead provider_profile_id', [
                'lead_id' => $lead->id,
                'provider_id' => $resolved['provider_id'],
                'source' => $resolved['source'],
            ]);
        }

        $logger->log(
            $campaign->id,
            $lead->id,
            $run?->id,
            $node,
            'started',
            "Starting \"{$nodeLabel}\" for {$lead->full_name}.",
        );

        try {
            if ($stepType === 'condition') {
                $this->handleCondition($campaign, $lead, $progress, $run, $node, $nodes, $resolver, $logger);

                return;
            }

            if ($stepType === 'end-sequence') {
                $completedKeys[] = $nodeKey;
                $this->markSequenceComplete(
                    $campaign,
                    $lead,
                    $progress,
                    $run,
                    $nodeKey,
                    $completedKeys,
                    $logger,
                    $completion,
                );

                return;
            }

            $normalizedStep = $executor->normalizeStepType($stepType);
            if ($normalizedStep === 'send-invites' && $profileService->isAlreadyConnected($lead, $resolved['profile'])) {
                $this->skipInviteAlreadyConnected(
                    $campaign, $lead, $progress, $run, $node, $nodes, $resolver, $logger
                );

                return;
            }

            $result = $executor->execute($stepType, $campaign, $lead, $run, $node, $resolved['provider_id']);
            $this->applyResult(
                $campaign, $lead, $progress, $run, $node, $nodes, $result, $resolver, $logger, $profileService, $completion, $linkedInGuard
            );
        } catch (Throwable $exception) {
            if ($linkedInGuard->isDisconnected($exception)) {
                $this->handleAccountDisconnected(
                    $campaign,
                    $lead,
                    $progress,
                    $run,
                    $node,
                    $linkedInGuard,
                    $logger,
                    $exception->getMessage(),
                );

                return;
            }

            Log::error('[Campaign] Step exception', [
                'campaign_id' => $campaign->id,
                'lead_id' => $lead->id,
                'node_key' => $nodeKey,
                'error' => $exception->getMessage(),
            ]);

            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                $node,
                'failed',
                "Failed \"{$nodeLabel}\": {$exception->getMessage()}",
                ['error' => $exception->getMessage()],
            );

            $lead->update(['status' => 'error']);
            $progress->update(['run_status' => 9]);
        }
    }

    /**
     * Skip invite when lead is already a 1st-degree connection — advance to accepted path.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function skipInviteAlreadyConnected(
        V2Campaign $campaign,
        V2CampaignLead $lead,
        V2CampaignLeadProgress $progress,
        ?V2CampaignRun $run,
        array $node,
        array $nodes,
        CampaignSequenceResolver $resolver,
        CampaignActivityLogger $logger,
    ): void {
        $nodeKey = (int) ($node['key'] ?? 0);
        $nodeLabel = $resolver->nodeLabel($node);
        $completed = is_array($progress->completed_keys) ? $progress->completed_keys : [];
        $completed[] = $nodeKey;

        Log::debug('[Campaign] Skipping invite — already connected', [
            'lead_id' => $lead->id,
            'node_key' => $nodeKey,
        ]);

        $logger->log(
            $campaign->id,
            $lead->id,
            $run?->id,
            $node,
            'skipped',
            "Already connected with {$lead->full_name} — skipping \"{$nodeLabel}\", moving to nurture steps.",
            ['reason' => 'already_connected'],
        );

        $progress->update([
            'current_node_key' => $nodeKey,
            'next_node_key' => $this->nextKeyAfterInvite($nodes, $resolver),
            'completed_keys' => array_values(array_unique($completed)),
            'acceptance_status' => true,
            'run_status' => 2,
            'next_run_at' => null,
        ]);

        self::dispatch($campaign->id, $lead->id, $run?->id)->delay(now()->addSeconds(2));
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function nextKeyAfterInvite(array $nodes, CampaignSequenceResolver $resolver): ?int
    {
        $path = $resolver->executionPath($nodes, true);
        $pastCondition = false;

        foreach ($path as $node) {
            if (($node['type'] ?? '') === 'condition') {
                $pastCondition = true;
                continue;
            }

            if ($pastCondition) {
                return (int) ($node['key'] ?? null);
            }
        }

        foreach ($path as $index => $node) {
            if ((int) ($node['key'] ?? -1) === 1) {
                $next = $path[$index + 1] ?? null;

                return $next !== null ? (int) ($next['key'] ?? null) : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function handleCondition(
        V2Campaign $campaign,
        V2CampaignLead $lead,
        V2CampaignLeadProgress $progress,
        ?V2CampaignRun $run,
        array $node,
        array $nodes,
        CampaignSequenceResolver $resolver,
        CampaignActivityLogger $logger,
    ): void {
        $nodeLabel = $resolver->nodeLabel($node);
        $acceptance = $progress->acceptance_status;

        if ($acceptance === null) {
            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                $node,
                'waiting',
                "Waiting at \"{$nodeLabel}\" — invite not accepted yet for {$lead->full_name}.",
            );
            $progress->update([
                'current_node_key' => (int) ($node['key'] ?? 0),
                'run_status' => max((int) $progress->run_status, 1),
                'next_run_at' => now()->addHours(6),
            ]);

            return;
        }

        $nextKey = $resolver->resolveNextNodeKey($nodes, (int) ($node['key'] ?? 0), $acceptance);
        $branchLabel = $acceptance ? 'accepted' : 'not accepted';

        $logger->log(
            $campaign->id,
            $lead->id,
            $run?->id,
            $node,
            'completed',
            "Condition \"{$nodeLabel}\" — {$branchLabel} path for {$lead->full_name}.",
        );

        $completed = is_array($progress->completed_keys) ? $progress->completed_keys : [];
        $completed[] = (int) ($node['key'] ?? 0);

        $progress->update([
            'current_node_key' => (int) ($node['key'] ?? 0),
            'next_node_key' => $nextKey,
            'completed_keys' => array_values(array_unique($completed)),
            'next_run_at' => null,
            'run_status' => $acceptance ? 2 : (int) $progress->run_status,
        ]);

        if ($nextKey !== null) {
            self::dispatch($campaign->id, $lead->id, $run?->id)->delay(now()->addSeconds(2));
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $result
     */
    private function applyResult(
        V2Campaign $campaign,
        V2CampaignLead $lead,
        V2CampaignLeadProgress $progress,
        ?V2CampaignRun $run,
        array $node,
        array $nodes,
        array $result,
        CampaignSequenceResolver $resolver,
        CampaignActivityLogger $logger,
        ?CampaignLeadProfileService $profileService = null,
        ?CampaignCompletionService $completion = null,
        ?CampaignLinkedInGuard $linkedInGuard = null,
    ): void {
        $status = (string) ($result['status'] ?? 'failed');
        $nodeLabel = $resolver->nodeLabel($node);
        $nodeKey = (int) ($node['key'] ?? 0);
        $completed = is_array($progress->completed_keys) ? $progress->completed_keys : [];

        Log::debug('[Campaign] Step result', [
            'lead_id' => $lead->id,
            'node_key' => $nodeKey,
            'status' => $status,
            'error' => $result['error_message'] ?? null,
        ]);

        if ($status === 'account_disconnected' || ($status === 'failed' && $linkedInGuard !== null && $linkedInGuard->isDisconnected((string) ($result['error_message'] ?? '')))) {
            $this->handleAccountDisconnected(
                $campaign,
                $lead,
                $progress,
                $run,
                $node,
                $linkedInGuard ?? app(CampaignLinkedInGuard::class),
                $logger,
                (string) ($result['error_message'] ?? 'LinkedIn account disconnected.'),
            );

            return;
        }

        if ($status === 'failed' && $profileService !== null) {
            $error = (string) ($result['error_message'] ?? '');
            $normalized = strtolower($resolver->stepType($node));
            if ($profileService->isAlreadyConnectedError($error)
                && in_array($normalized, ['send-invite', 'send-invites'], true)) {
                $this->skipInviteAlreadyConnected(
                    $campaign, $lead, $progress, $run, $node, $nodes, $resolver, $logger
                );

                return;
            }
        }

        // Safety net: temporary LinkedIn/Unipile limits should retry, not permanent Error.
        if ($status === 'failed') {
            $tempLimit = app(\App\V2\Services\UnipileTemporaryLimitGuard::class);
            $error = (string) ($result['error_message'] ?? '');
            if ($tempLimit->isTemporaryLimit($error)) {
                $action = str_contains(strtolower($resolver->stepType($node)), 'invite')
                    ? \App\V2\Services\UnipileDailyActionLimiter::ACTION_INVITES
                    : \App\V2\Services\UnipileDailyActionLimiter::ACTION_MESSAGES;
                $result = $tempLimit->deferredResult((int) $campaign->user_id, $action, $error);
                $status = 'deferred';
            }
        }

        if ($status === 'deferred') {
            $runAt = $result['next_run_at'] ?? now()->addDay()->startOfDay()->addMinutes(10);
            $reason = (string) ($result['payload']['reason'] ?? 'daily_limit');
            $isTemp = str_starts_with($reason, 'temporary_');

            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                $node,
                'scheduled',
                $isTemp
                    ? "LinkedIn temporary limit — \"{$nodeLabel}\" for {$lead->full_name} retries ".$runAt->diffForHumans().'.'
                    : "Daily LinkedIn limit reached — \"{$nodeLabel}\" for {$lead->full_name} resumes ".$runAt->diffForHumans().'.',
                $result['payload'] ?? [],
            );

            $lead->update(['status' => 'pending']);
            // Same node retries later; keys are not advanced.
            $progress->update(['next_run_at' => $runAt, 'run_status' => 0]);
            self::dispatch($campaign->id, $lead->id, $run?->id)->delay($runAt);

            return;
        }

        if ($status === 'scheduled') {
            $waitSeconds = (int) ($result['payload']['wait_seconds'] ?? 3600);
            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                $node,
                'scheduled',
                "Scheduled \"{$nodeLabel}\" — wait {$waitSeconds}s for {$lead->full_name}.",
                $result['payload'] ?? [],
            );

            $completed[] = $nodeKey;
            $nextKey = $resolver->resolveNextNodeKey($nodes, $nodeKey, $progress->acceptance_status);
            $runAt = $result['next_run_at'] ?? now()->addSeconds($waitSeconds);

            Log::debug('[Campaign] Wait scheduled', [
                'lead_id' => $lead->id,
                'node_key' => $nodeKey,
                'next_node_key' => $nextKey,
                'wait_seconds' => $waitSeconds,
                'run_at' => $runAt->toIso8601String(),
            ]);

            $progress->update([
                'current_node_key' => $nodeKey,
                'next_node_key' => $nextKey,
                'completed_keys' => array_values(array_unique($completed)),
                'next_run_at' => $runAt,
            ]);

            if ($nextKey !== null) {
                self::dispatch($campaign->id, $lead->id, $run?->id)->delay($runAt);
            } else {
                Log::warning('[Campaign] Wait finished but no next node key', [
                    'lead_id' => $lead->id,
                    'node_key' => $nodeKey,
                ]);
            }

            return;
        }

        if ($status === 'waiting') {
            $progress->update(['next_run_at' => now()->addHours(6)]);

            return;
        }

        if ($status === 'completed') {
            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                $node,
                'completed',
                "Completed \"{$nodeLabel}\" for {$lead->full_name}.",
                $result['payload'] ?? [],
            );

            $completed[] = $nodeKey;
            $nextKey = $resolver->resolveNextNodeKey($nodes, $nodeKey, $progress->acceptance_status);
            $runStatus = $this->mapRunStatus($resolver->stepType($node), (int) $progress->run_status);

            if (($result['payload']['halt'] ?? false) === true || $nextKey === null) {
                $this->markSequenceComplete(
                    $campaign,
                    $lead,
                    $progress,
                    $run,
                    $nodeKey,
                    $completed,
                    $logger,
                    $completion,
                );

                return;
            }

            $progress->update([
                'current_node_key' => $nodeKey,
                'next_node_key' => $nextKey,
                'completed_keys' => array_values(array_unique($completed)),
                'run_status' => $runStatus,
                'next_run_at' => null,
            ]);

            self::dispatch($campaign->id, $lead->id, $run?->id)->delay(now()->addSeconds(3));

            return;
        }

        if ($status === 'skipped') {
            $reason = (string) ($result['payload']['reason'] ?? 'skipped');
            $logger->log(
                $campaign->id,
                $lead->id,
                $run?->id,
                $node,
                'skipped',
                "Skipped \"{$nodeLabel}\" for {$lead->full_name}: {$reason}.",
                $result['payload'] ?? [],
            );

            $nextKey = $resolver->resolveNextNodeKey($nodes, $nodeKey, $progress->acceptance_status);
            if ($nextKey === null) {
                $completed[] = $nodeKey;
                $this->markSequenceComplete($campaign, $lead, $progress, $run, $nodeKey, $completed, $logger, $completion);

                return;
            }

            $progress->update([
                'current_node_key' => $nodeKey,
                'next_node_key' => $nextKey,
                'next_run_at' => null,
            ]);

            self::dispatch($campaign->id, $lead->id, $run?->id)->delay(now()->addSeconds(2));

            return;
        }

        $error = (string) ($result['error_message'] ?? 'Step failed');
        $logger->log(
            $campaign->id,
            $lead->id,
            $run?->id,
            $node,
            'failed',
            "Failed \"{$nodeLabel}\" for {$lead->full_name}: {$error}.",
            $result['payload'] ?? [],
        );

        $lead->update(['status' => 'error']);
        $progress->update(['run_status' => 9, 'next_run_at' => null]);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function handleAccountDisconnected(
        V2Campaign $campaign,
        V2CampaignLead $lead,
        V2CampaignLeadProgress $progress,
        ?V2CampaignRun $run,
        array $node,
        CampaignLinkedInGuard $linkedInGuard,
        CampaignActivityLogger $logger,
        string $reason,
    ): void {
        $nodeLabel = $node['label'] ?? 'step';
        $linkedInGuard->handleDisconnect((int) $campaign->user_id, $campaign->organization_id, $reason);

        $logger->log(
            $campaign->id,
            $lead->id,
            $run?->id,
            $node,
            'paused',
            "Paused \"{$nodeLabel}\" — LinkedIn disconnected. Reconnect to resume.",
            ['reason' => 'linkedin_disconnected', 'error' => $reason],
        );

        $lead->update(['status' => 'pending']);
        $progress->update([
            'run_status' => max((int) $progress->run_status, 1),
            'next_run_at' => null,
        ]);

        Log::warning('[Campaign] Stopped processing — LinkedIn disconnected', [
            'campaign_id' => $campaign->id,
            'lead_id' => $lead->id,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<int, int>  $completed
     */
    private function markSequenceComplete(
        V2Campaign $campaign,
        V2CampaignLead $lead,
        V2CampaignLeadProgress $progress,
        ?V2CampaignRun $run,
        int $lastNodeKey,
        array $completed,
        CampaignActivityLogger $logger,
        ?CampaignCompletionService $completion = null,
    ): void {
        $lead->update(['status' => 'done']);
        $progress->update([
            'current_node_key' => $lastNodeKey,
            'next_node_key' => self::SEQUENCE_COMPLETE_KEY,
            'completed_keys' => array_values(array_unique($completed)),
            'run_status' => 4,
            'next_run_at' => null,
        ]);

        Log::info('[Campaign] Sequence complete', [
            'campaign_id' => $campaign->id,
            'lead_id' => $lead->id,
            'last_node_key' => $lastNodeKey,
        ]);

        $logger->log(
            $campaign->id,
            $lead->id,
            $run?->id,
            null,
            'completed',
            "Sequence finished for {$lead->full_name}.",
        );

        if ($completion !== null) {
            $completion->maybeFinish($campaign, $run);
        }
    }

    private function mapRunStatus(string $stepType, int $current): int
    {
        $normalized = strtolower($stepType);

        if (in_array($normalized, ['send-invite', 'send-invites', 'connect'], true)) {
            return max($current, 1);
        }

        if (in_array($normalized, ['message', 'send-message'], true)) {
            return max($current, 3);
        }

        return max($current, 1);
    }
}
