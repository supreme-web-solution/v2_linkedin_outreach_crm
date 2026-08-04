<?php

namespace App\V2\Outreach;

use App\Models\V2IntegrationAccount;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Integrations\Unipile\UnipileException;
use App\V2\Outreach\Channels\ChannelExecutorInterface;
use App\V2\Outreach\Channels\EmailChannelExecutor;
use App\V2\Outreach\Channels\LinkedInChannelExecutor;
use App\V2\Outreach\Channels\MessagingChannelExecutor;
use App\V2\Services\LinkedInConnectionService;
use App\V2\Services\UnifiedInboxService;
use App\V2\Services\UnipileDailyActionLimiter;
use App\V2\Services\UnipileTemporaryLimitGuard;
use Illuminate\Support\Facades\Log;
use Throwable;

class OutreachStepExecutor
{
    /** @var array<string, ChannelExecutorInterface> */
    private array $executors;

    public function __construct(
        LinkedInChannelExecutor $linkedIn,
        EmailChannelExecutor $email,
        LinkedInConnectionService $linkedInConnection,
        OutreachChannelGuard $guard,
        OutreachSequenceResolver $resolver,
        \App\V2\Integrations\ProviderManager $providerManager,
        UnifiedInboxService $unifiedInbox,
    ) {
        $this->resolver = $resolver;
        $this->linkedInConnection = $linkedInConnection;
        $this->guard = $guard;
        $contactResolver = app(OutreachLeadContactResolver::class);
        $allExecutors = [
            'linkedin' => $linkedIn,
            'email' => new EmailChannelExecutor($providerManager, $unifiedInbox),
            'whatsapp' => new MessagingChannelExecutor('whatsapp', $providerManager, $contactResolver, $unifiedInbox),
            'instagram' => new MessagingChannelExecutor('instagram', $providerManager, $contactResolver, $unifiedInbox),
            'telegram' => new MessagingChannelExecutor('telegram', $providerManager, $contactResolver, $unifiedInbox),
            'twitter' => new MessagingChannelExecutor('twitter', $providerManager, $contactResolver, $unifiedInbox),
        ];
        $this->executors = array_intersect_key(
            $allExecutors,
            array_flip(OutreachChannelRegistry::enabledChannelKeys()),
        );
    }

    private OutreachSequenceResolver $resolver;

    private LinkedInConnectionService $linkedInConnection;

    private OutreachChannelGuard $guard;

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function execute(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        array $node,
    ): array {
        $type = (string) ($node['type'] ?? 'action');

        if ($type === 'delay') {
            $seconds = $this->resolver->delaySeconds($node);

            return [
                'status' => 'scheduled',
                'payload' => ['wait_seconds' => $seconds],
                'next_run_at' => now()->addSeconds($seconds),
            ];
        }

        if ($type === 'condition') {
            return ['status' => 'waiting', 'payload' => ['reason' => 'awaiting_condition']];
        }

        if ($type === 'end') {
            return ['status' => 'completed', 'payload' => ['halt' => true]];
        }

        $channel = (string) ($node['channel'] ?? '');
        $action = (string) ($node['action'] ?? '');

        if ($channel === '' || $action === '') {
            return ['status' => 'failed', 'error_message' => 'Step missing channel or action.'];
        }

        $integrationProvider = OutreachChannelRegistry::channels()[$channel]['integration_provider'] ?? $channel;
        $accountId = V2IntegrationAccount::activeUnipileAccountIdForProvider((int) $campaign->user_id, $integrationProvider);

        if ($accountId === null) {
            return [
                'status' => 'channel_disconnected',
                'error_message' => OutreachChannelRegistry::channelLabel($channel).' is not connected.',
                'payload' => ['channel' => $channel],
            ];
        }

        $executor = $this->executors[$channel] ?? null;
        if ($executor === null) {
            return ['status' => 'failed', 'error_message' => "No executor for channel: {$channel}"];
        }

        if ($deferred = $this->deferIfOverDailyCap((int) $campaign->user_id, $action)) {
            return $deferred;
        }

        $quotaAction = match ($action) {
            'send_invite' => UnipileDailyActionLimiter::ACTION_INVITES,
            'send_message' => UnipileDailyActionLimiter::ACTION_MESSAGES,
            default => null,
        };
        $tempLimit = app(UnipileTemporaryLimitGuard::class);
        if ($quotaAction !== null && $tempLimit->isLimited((int) $campaign->user_id, $quotaAction)) {
            return $tempLimit->deferredResult((int) $campaign->user_id, $quotaAction);
        }

        $context = [
            'owner_id' => (string) $campaign->user_id,
            'organization_id' => $campaign->organization_id,
            'account_id' => $accountId,
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'channel' => $channel,
        ];

        try {
            $result = $executor->execute($action, $campaign, $lead, $node, $context);

            if (($result['status'] ?? '') === 'failed') {
                $error = (string) ($result['error_message'] ?? '');
                if ($this->guard->isDisconnectedMessage($error)) {
                    return ['status' => 'channel_disconnected', 'error_message' => $error, 'payload' => ['channel' => $channel]];
                }
                if ($quotaAction !== null && $tempLimit->isTemporaryLimit($error)) {
                    app(UnipileDailyActionLimiter::class)->release((int) $campaign->user_id, $quotaAction);

                    return $tempLimit->deferredResult((int) $campaign->user_id, $quotaAction, $error);
                }
            }

            return $result;
        } catch (Throwable $e) {
            if ($e instanceof UnipileException && $this->linkedInConnection->isDisconnectedError($e)) {
                return [
                    'status' => 'channel_disconnected',
                    'error_message' => $e->getMessage(),
                    'payload' => ['channel' => $channel],
                ];
            }

            if ($this->guard->isDisconnected($e)) {
                return [
                    'status' => 'channel_disconnected',
                    'error_message' => $e->getMessage(),
                    'payload' => ['channel' => $channel],
                ];
            }

            if ($quotaAction !== null && $tempLimit->isTemporaryLimit($e)) {
                app(UnipileDailyActionLimiter::class)->release((int) $campaign->user_id, $quotaAction);

                return $tempLimit->deferredResult((int) $campaign->user_id, $quotaAction, $e->getMessage());
            }

            return ['status' => 'failed', 'error_message' => $e->getMessage()];
        }
    }

    /**
     * Reserve daily quota for send actions; return a "deferred" result when
     * the user's cap is reached so the lead retries this node tomorrow.
     * Email sends are excluded — they go through ESP limits, not Unipile pacing.
     *
     * @return array<string, mixed>|null
     */
    private function deferIfOverDailyCap(int $userId, string $action): ?array
    {
        $quotaAction = match ($action) {
            'send_invite' => UnipileDailyActionLimiter::ACTION_INVITES,
            'send_message' => UnipileDailyActionLimiter::ACTION_MESSAGES,
            default => null,
        };

        if ($quotaAction === null) {
            return null;
        }

        $limiter = app(UnipileDailyActionLimiter::class);
        if ($limiter->tryConsume($userId, $quotaAction)) {
            return null;
        }

        $resumeAt = $limiter->resumeAt();

        Log::info('[Outreach] Daily quota reached — step deferred', [
            'user_id' => $userId,
            'action' => $action,
            'quota' => $quotaAction,
            'resume_at' => $resumeAt->toIso8601String(),
        ]);

        return [
            'status' => 'deferred',
            'next_run_at' => $resumeAt,
            'payload' => [
                'reason' => 'daily_'.$quotaAction.'_limit',
                'limit' => $limiter->limitFor($quotaAction),
            ],
        ];
    }
}
