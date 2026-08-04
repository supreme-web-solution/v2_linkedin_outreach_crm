<?php

namespace App\V2\Campaign;

use App\Models\V2Campaign;
use App\Models\V2CampaignLead;
use App\Models\V2CampaignRun;
use App\Models\User;
use App\V2\Campaign\CampaignLinkedInGuard;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Services\CallOrchestrationService;
use App\V2\Services\UnipileDailyActionLimiter;
use App\V2\Services\UnipileTemporaryLimitGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class CampaignStepExecutor
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly CallOrchestrationService $callOrchestration,
        private readonly CampaignLinkedInGuard $linkedInGuard,
        private readonly CampaignSequenceResolver $resolver = new CampaignSequenceResolver(),
    ) {}

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function execute(
        string $stepType,
        V2Campaign $campaign,
        V2CampaignLead $lead,
        ?V2CampaignRun $run,
        array $node,
        ?string $resolvedProviderId = null,
    ): array {
        $normalized = $this->normalizeStepType($stepType);
        $context = [
            'owner_id' => (string) $campaign->user_id,
            'organization_id' => $campaign->organization_id,
            'campaign_id' => $campaign->id,
            'campaign_run_id' => $run?->id,
            'campaign_lead_id' => $lead->id,
        ];

        $recipientId = trim($resolvedProviderId ?? (string) ($lead->provider_profile_id ?? ''));
        $firstName = $this->resolver->firstNameFromLead($lead->full_name);
        $message = $this->resolver->messageText($node, $firstName);

        Log::debug('[Campaign] CampaignStepExecutor', [
            'step' => $normalized,
            'lead_id' => $lead->id,
            'recipient_id' => $recipientId,
        ]);

        if ($normalized === 'wait') {
            $seconds = $this->resolver->delaySeconds($node);

            return [
                'status' => 'scheduled',
                'payload' => ['wait_seconds' => $seconds],
                'next_run_at' => now()->addSeconds($seconds),
            ];
        }

        if ($normalized === 'condition') {
            return [
                'status' => 'waiting',
                'payload' => ['reason' => 'awaiting_condition'],
            ];
        }

        if ($normalized === 'end-sequence') {
            return [
                'status' => 'completed',
                'payload' => ['halt' => true],
            ];
        }

        if ($normalized === 'send-invites') {
            if ($recipientId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_recipient_id']];
            }

            $tempLimit = app(UnipileTemporaryLimitGuard::class);
            if ($tempLimit->isLimited((int) $campaign->user_id, UnipileDailyActionLimiter::ACTION_INVITES)) {
                return $tempLimit->deferredResult((int) $campaign->user_id, UnipileDailyActionLimiter::ACTION_INVITES);
            }

            if ($deferred = $this->deferIfOverDailyCap((int) $campaign->user_id, UnipileDailyActionLimiter::ACTION_INVITES)) {
                return $deferred;
            }

            return $this->executeWithProviderFallback(
                'campaign_send_invitation',
                function (string $providerKey) use ($recipientId, $message, $context): array {
                    $response = $this->providerManager->invitation($providerKey)->sendInvitation([
                        'recipient_id' => $recipientId,
                        'message' => $message ?: 'Happy to connect.',
                    ], $context);

                    return ['response' => $response];
                },
                (int) $campaign->user_id,
                UnipileDailyActionLimiter::ACTION_INVITES,
            );
        }

        if ($normalized === 'message') {
            if ($recipientId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_recipient_id']];
            }

            $tempLimit = app(UnipileTemporaryLimitGuard::class);
            if ($tempLimit->isLimited((int) $campaign->user_id, UnipileDailyActionLimiter::ACTION_NEW_CHATS)) {
                return $tempLimit->deferredResult((int) $campaign->user_id, UnipileDailyActionLimiter::ACTION_NEW_CHATS);
            }

            if ($deferred = $this->deferIfOverDailyCap((int) $campaign->user_id, UnipileDailyActionLimiter::ACTION_NEW_CHATS)) {
                return $deferred;
            }

            $startResult = $this->executeWithProviderFallback(
                'campaign_start_chat',
                function (string $providerKey) use ($recipientId, $message, $context): array {
                    $chat = $this->providerManager->messaging($providerKey)->startChat([
                        'attendee_ids' => [$recipientId],
                        'text' => $message ?: 'Hello',
                    ], $context);

                    return [
                        'chat_id' => $this->extractChatId($chat),
                        'response' => $chat,
                    ];
                },
                (int) $campaign->user_id,
                UnipileDailyActionLimiter::ACTION_NEW_CHATS,
            );

            if (($startResult['status'] ?? '') !== 'completed') {
                return $startResult;
            }

            return [
                'status' => 'completed',
                'payload' => $startResult['payload'] ?? [],
            ];
        }

        if ($normalized === 'call') {
            $user = User::query()->find($campaign->user_id);
            if (!$user || !$campaign->organization_id) {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_user_or_org']];
            }

            $call = $this->callOrchestration->createCall($user, (int) $campaign->organization_id, [
                'connection_id' => $recipientId !== '' ? $recipientId : null,
                'prospect_name' => $lead->full_name,
                'prospect_headline' => $lead->headline,
                'lead_id' => $lead->id,
                'pending_message' => $message ?: null,
                'meta' => [
                    'source' => 'campaign_step',
                    'campaign_id' => $campaign->id,
                    'campaign_lead_id' => $lead->id,
                ],
            ]);

            return [
                'status' => 'completed',
                'payload' => ['call_id' => $call->id],
            ];
        }

        if (in_array($normalized, ['profile-view', 'endorse'], true)) {
            if ($recipientId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_recipient_id']];
            }

            return $this->executeWithProviderFallback(
                'campaign_profile_action',
                function (string $providerKey) use ($normalized, $recipientId, $context): array {
                    if ($normalized === 'profile-view') {
                        $response = $this->providerManager->profile($providerKey)->getProfileByIdentifier($recipientId, $context);

                        return ['action' => $normalized, 'response' => $response];
                    }

                    /** @var UnipileProvider $concrete */
                    $concrete = $this->providerManager->get($providerKey, UnipileProvider::class);
                    $response = $concrete->performLinkedinProfileAction('endorse', array_merge($context, [
                        'recipient_id' => $recipientId,
                    ]));

                    return ['action' => 'endorse', 'response' => $response];
                }
            );
        }

        return [
            'status' => 'skipped',
            'payload' => ['reason' => 'unsupported_step_type', 'step_type' => $normalized],
        ];
    }

    /**
     * Reserve daily quota for the action; return a "deferred" result when
     * the user's cap is reached so the lead retries this node tomorrow.
     *
     * @return array<string, mixed>|null
     */
    private function deferIfOverDailyCap(int $userId, string $quotaAction): ?array
    {
        $limiter = app(UnipileDailyActionLimiter::class);
        if ($limiter->tryConsume($userId, $quotaAction)) {
            return null;
        }

        $resumeAt = $limiter->resumeAt();

        Log::info('[Campaign] Daily quota reached — step deferred', [
            'user_id' => $userId,
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

    public function normalizeStepType(string $raw): string
    {
        $value = strtolower(trim($raw));
        $aliases = [
            'send-invite' => 'send-invites',
            'invite' => 'send-invites',
            'connect' => 'send-invites',
            'connection-request' => 'send-invites',
            'message' => 'message',
            'send-message' => 'message',
            'wait' => 'wait',
            'delay' => 'wait',
            'accepted' => 'condition',
            'condition' => 'condition',
            'endorse' => 'endorse',
            'profile-view' => 'profile-view',
            'view-profile' => 'profile-view',
            'follow' => 'profile-view',
            'call' => 'call',
            'book-call' => 'call',
            'end' => 'end-sequence',
            'end-sequence' => 'end-sequence',
        ];

        return $aliases[$value] ?? $value;
    }

    /**
     * @param  callable(string): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function executeWithProviderFallback(
        string $operation,
        callable $callback,
        ?int $userId = null,
        ?string $quotaAction = null,
    ): array {
        $attempts = [];
        $lastError = null;
        $tempLimit = app(UnipileTemporaryLimitGuard::class);

        foreach ($this->providerManager->providersForOperation($operation) as $providerKey) {
            if (!$this->providerManager->isRegistered($providerKey)) {
                $attempts[] = ['provider' => $providerKey, 'status' => 'skipped_unregistered'];
                continue;
            }

            try {
                $result = $callback($providerKey);
                $attempts[] = ['provider' => $providerKey, 'status' => 'completed'];

                Log::debug('[Campaign] Provider step OK', [
                    'operation' => $operation,
                    'provider' => $providerKey,
                ]);

                return [
                    'status' => 'completed',
                    'payload' => [
                        'operation' => $operation,
                        'provider' => $providerKey,
                        'attempts' => $attempts,
                        'result' => $result,
                    ],
                ];
            } catch (Throwable $exception) {
                if ($this->linkedInGuard->isDisconnected($exception)) {
                    $attempts[] = [
                        'provider' => $providerKey,
                        'status' => 'account_disconnected',
                        'error' => $exception->getMessage(),
                    ];

                    return [
                        'status' => 'account_disconnected',
                        'error_message' => $exception->getMessage(),
                        'payload' => ['operation' => $operation, 'attempts' => $attempts],
                    ];
                }

                if ($userId && $quotaAction && $tempLimit->isTemporaryLimit($exception)) {
                    app(UnipileDailyActionLimiter::class)->release($userId, $quotaAction);
                    $deferred = $tempLimit->deferredResult($userId, $quotaAction, $exception->getMessage());
                    $deferred['payload']['operation'] = $operation;
                    $deferred['payload']['attempts'] = $attempts;

                    Log::warning('[Campaign] Temporary provider limit — step deferred', [
                        'operation' => $operation,
                        'user_id' => $userId,
                        'resume_at' => $deferred['next_run_at']->toIso8601String(),
                        'error' => $exception->getMessage(),
                    ]);

                    return $deferred;
                }

                $lastError = $exception->getMessage();
                $attempts[] = [
                    'provider' => $providerKey,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ];
                Log::warning('[Campaign] Provider step failed', [
                    'operation' => $operation,
                    'provider' => $providerKey,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'status' => 'failed',
            'error_message' => $lastError ?: "All providers failed for operation [{$operation}].",
            'payload' => ['operation' => $operation, 'attempts' => $attempts],
        ];
    }

    private function extractChatId(array $response): string
    {
        return (string) (Arr::get($response, 'id') ?? Arr::get($response, 'chat_id') ?? Arr::get($response, 'data.id') ?? '');
    }
}
