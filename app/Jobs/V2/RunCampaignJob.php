<?php

namespace App\Jobs\V2;

use App\Models\V2Campaign;
use App\Models\V2Call;
use App\Models\V2CampaignRun;
use App\Models\V2CampaignStep;
use App\Models\V2Lead;
use App\Models\V2UserActivity;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Throwable;

class RunCampaignJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $campaignRunId)
    {
        $this->onQueue('campaigns');
    }

    public function handle(ProviderManager $providerManager): void
    {
        $run = V2CampaignRun::query()->find($this->campaignRunId);
        if (!$run || $run->status !== 'queued') {
            return;
        }

        $campaign = V2Campaign::query()->find($run->legacy_campaign_id);

        $run->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'current_step_key' => 'initial',
            'meta' => (is_array($run->meta) ? $run->meta : []) + [
                'campaign_name' => $campaign?->name,
            ],
        ])->save();

        $nodes = is_array($campaign?->node_model) ? $campaign->node_model : [];
        if (array_is_list($nodes) === false && isset($nodes['nodes']) && is_array($nodes['nodes'])) {
            $nodes = $nodes['nodes'];
        }

        if (!is_array($nodes) || empty($nodes)) {
            $nodes = [
                ['id' => 'start', 'value' => 'send-invites'],
                ['id' => 'follow', 'value' => 'message'],
            ];
        }

        $hadFailures = false;
        foreach ($nodes as $index => $node) {
            $rawStepType = (string) (($node['value'] ?? $node['type'] ?? 'unknown'));
            $stepType = $this->normalizeStepType($rawStepType);
            $stepKey = (string) (($node['id'] ?? 'node_'.$index));

            $run->forceFill(['current_step_key' => $stepKey])->save();

            try {
                $result = $this->executeStep(
                    $stepType,
                    $run,
                    $campaign,
                    is_array($node) ? $node : [],
                    $providerManager
                );
            } catch (Throwable $exception) {
                $result = [
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'payload' => [],
                ];
            }

            if (($result['status'] ?? 'failed') === 'failed') {
                $hadFailures = true;
            }

            $this->recordTrackingHooks($run, $stepType, is_array($node) ? $node : [], $result);
            $this->recordStepActivity($run, $stepType, $result);

            V2CampaignStep::query()->create([
                'campaign_run_id' => $run->id,
                'step_key' => $stepKey,
                'step_type' => $stepType,
                'status' => (string) ($result['status'] ?? 'failed'),
                'executed_at' => now(),
                'error_message' => isset($result['error_message']) ? (string) $result['error_message'] : null,
                'payload' => array_merge(
                    is_array($node) ? $node : [],
                    [
                        'raw_step_type' => $rawStepType,
                        'normalized_step_type' => $stepType,
                        'execution' => is_array($result['payload'] ?? null) ? $result['payload'] : [],
                    ]
                ),
            ]);

            if ((bool) Arr::get($result, 'payload.halt_run', false) === true) {
                break;
            }
        }

        $run->forceFill([
            'status' => $hadFailures ? 'failed' : 'completed',
            'completed_at' => now(),
            'current_step_key' => 'completed',
        ])->save();
    }

    /**
     * @param array<string, mixed> $node
     */
    private function executeStep(
        string $stepType,
        V2CampaignRun $run,
        ?V2Campaign $campaign,
        array $node,
        ProviderManager $providerManager
    ): array
    {
        $context = [
            'owner_id' => (string) $run->user_id,
            'organization_id' => $campaign?->organization_id,
            'campaign_id' => $campaign?->id,
            'campaign_run_id' => $run->id,
        ];

        $recipientId = $this->resolveRecipientId($run, $node);
        $chatId = $this->resolveChatId($node);
        $invitationId = $this->resolveInvitationId($node);
        $message = $this->resolveMessageText($node);
        $postId = $this->resolvePostId($node);

        if ($stepType === 'wait') {
            $waitSeconds = $this->resolveDelaySeconds($node);
            $shouldJitter = (bool) ($node['randomize'] ?? $node['jitter'] ?? false);
            if ($shouldJitter) {
                $jitterPercent = max(0, min(50, (int) ($node['jitter_percent'] ?? 20)));
                $jitter = (int) round($waitSeconds * ($jitterPercent / 100));
                $waitSeconds += random_int(-$jitter, $jitter);
                $waitSeconds = max(1, $waitSeconds);
            }

            return [
                'status' => 'completed',
                'payload' => [
                    'wait_seconds' => $waitSeconds,
                ],
            ];
        }

        if ($stepType === 'track-event') {
            $label = trim((string) ($node['tracking_label'] ?? $node['event_name'] ?? 'campaign.custom_event'));
            $score = (int) ($node['tracking_score'] ?? 1);
            $organizationId = (int) ($context['organization_id'] ?? 0);

            if ($organizationId > 0) {
                V2UserActivity::query()->create([
                    'user_id' => $run->user_id,
                    'organization_id' => $organizationId,
                    'module' => 'campaign',
                    'identifier' => $label,
                    'stat' => $score,
                    'meta' => [
                        'campaign_run_id' => $run->id,
                        'step_type' => $stepType,
                    ],
                ]);
            }

            return [
                'status' => 'completed',
                'payload' => [
                    'tracking_label' => $label,
                    'tracking_score' => $score,
                ],
            ];
        }

        if ($stepType === 'call') {
            $organizationId = (int) ($context['organization_id'] ?? 0);
            if ($organizationId <= 0) {
                return [
                    'status' => 'skipped',
                    'payload' => ['reason' => 'missing_organization_context'],
                ];
            }

            $call = V2Call::query()->create([
                'user_id' => $run->user_id,
                'organization_id' => $organizationId,
                'conversation_id' => null,
                'connection_id' => $recipientId ?: null,
                'prospect_name' => $this->resolveRecipientName($run, $node),
                'status' => 'engaged',
                'pending_message' => $message ?: 'Can we schedule a quick call?',
                'scheduled_send_at' => now()->addMinutes(5),
                'conversation_history' => [],
                'ai_analysis' => [],
                'meta' => [
                    'source' => 'campaign_step',
                    'campaign_run_id' => $run->id,
                ],
            ]);

            return [
                'status' => 'completed',
                'payload' => ['call_id' => $call->id],
            ];
        }

        if ($stepType === 'send-invites') {
            if ($recipientId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_recipient_id']];
            }

            return $this->executeWithProviderFallback(
                $providerManager,
                'campaign_send_invitation',
                function (string $providerKey) use ($providerManager, $recipientId, $message, $context): array {
                    $response = $providerManager->invitation($providerKey)->sendInvitation([
                        'recipient_id' => $recipientId,
                        'message' => $message ?: 'Happy to connect.',
                    ], $context);

                    return ['response' => $response];
                }
            );
        }

        if ($stepType === 'start-chat') {
            if ($recipientId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_recipient_id']];
            }

            return $this->executeWithProviderFallback(
                $providerManager,
                'campaign_start_chat',
                function (string $providerKey) use ($providerManager, $recipientId, $message, $context): array {
                    $chat = $providerManager->messaging($providerKey)->startChat([
                        'attendee_ids' => [$recipientId],
                        'text' => $message ?: 'Hello',
                    ], $context);

                    return [
                        'chat_id' => $this->extractChatId($chat),
                        'response' => $chat,
                    ];
                }
            );
        }

        if ($stepType === 'message') {
            if ($recipientId === '' && $chatId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_chat_or_recipient']];
            }

            if ($chatId === '' && $recipientId !== '') {
                $startResult = $this->executeWithProviderFallback(
                    $providerManager,
                    'campaign_start_chat',
                    function (string $providerKey) use ($providerManager, $recipientId, $message, $context): array {
                        $chat = $providerManager->messaging($providerKey)->startChat([
                            'attendee_ids' => [$recipientId],
                            'text' => $message ?: 'Hello',
                        ], $context);

                        return [
                            'chat_id' => $this->extractChatId($chat),
                            'response' => $chat,
                        ];
                    }
                );

                if (($startResult['status'] ?? '') !== 'completed') {
                    return $startResult;
                }

                $chatId = (string) Arr::get($startResult, 'payload.result.chat_id', '');
                if ($chatId === '') {
                    return [
                        'status' => 'failed',
                        'error_message' => 'Unable to resolve chat id for message step.',
                        'payload' => $startResult['payload'] ?? [],
                    ];
                }
            }

            return $this->executeWithProviderFallback(
                $providerManager,
                'campaign_send_message',
                function (string $providerKey) use ($providerManager, $chatId, $message, $context): array {
                    $response = $providerManager->messaging($providerKey)->sendMessage($chatId, [
                        'text' => $message ?: 'Follow-up',
                    ], $context);

                    return ['chat_id' => $chatId, 'response' => $response];
                }
            );
        }

        if ($stepType === 'check-reply') {
            if ($chatId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_chat_id']];
            }

            return $this->executeWithProviderFallback(
                $providerManager,
                'campaign_send_message',
                function (string $providerKey) use ($providerManager, $chatId, $context, $node): array {
                    $response = $providerManager->messaging($providerKey)->listMessages($chatId, [
                        'limit' => 20,
                    ], $context);

                    $items = Arr::get($response, 'items', $response);
                    $count = is_array($items) ? count($items) : 0;
                    $minimumReplies = max(1, (int) ($node['min_reply_count'] ?? 1));
                    $haltWhenMissingReply = (bool) ($node['halt_if_missing_reply'] ?? false);

                    return [
                        'chat_id' => $chatId,
                        'message_count' => $count,
                        'has_reply' => $count >= $minimumReplies,
                        'halt_run' => $haltWhenMissingReply && $count < $minimumReplies,
                    ];
                }
            );
        }

        if ($stepType === 'stop-if-no-reply') {
            if ($chatId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_chat_id']];
            }

            return $this->executeWithProviderFallback(
                $providerManager,
                'campaign_send_message',
                function (string $providerKey) use ($providerManager, $chatId, $context): array {
                    $response = $providerManager->messaging($providerKey)->listMessages($chatId, ['limit' => 20], $context);
                    $items = Arr::get($response, 'items', $response);
                    $count = is_array($items) ? count($items) : 0;
                    return [
                        'chat_id' => $chatId,
                        'message_count' => $count,
                        'halt_run' => $count === 0,
                    ];
                }
            );
        }

        if ($stepType === 'end-sequence') {
            return [
                'status' => 'completed',
                'payload' => [
                    'halt_run' => true,
                    'reason' => 'sequence_end_marker',
                ],
            ];
        }

        if ($stepType === 'mark-read') {
            if ($chatId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_chat_id']];
            }

            return $this->executeWithProviderFallback(
                $providerManager,
                'campaign_read_state',
                function (string $providerKey) use ($providerManager, $chatId, $context): array {
                    $response = $providerManager->messaging($providerKey)->markChatReadState($chatId, true, $context);
                    return ['chat_id' => $chatId, 'response' => $response];
                }
            );
        }

        if (in_array($stepType, ['accept-invite', 'withdraw-invite'], true)) {
            if ($invitationId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_invitation_id']];
            }

            return $this->executeWithProviderFallback(
                $providerManager,
                'campaign_invitation_action',
                function (string $providerKey) use ($providerManager, $stepType, $invitationId, $context): array {
                    $response = $stepType === 'accept-invite'
                        ? $providerManager->invitation($providerKey)->handleReceivedInvitation($invitationId, 'accept', $context)
                        : $providerManager->invitation($providerKey)->cancelInvitation($invitationId, $context);

                    return ['invitation_id' => $invitationId, 'response' => $response];
                }
            );
        }

        if (in_array($stepType, ['profile-view', 'endorse'], true)) {
            if ($recipientId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_recipient_id']];
            }

            return $this->executeWithProviderFallback(
                $providerManager,
                'campaign_profile_action',
                function (string $providerKey) use ($providerManager, $stepType, $recipientId, $context): array {
                    if ($stepType === 'profile-view') {
                        $response = $providerManager->profile($providerKey)->getProfileByIdentifier($recipientId, $context);
                        return ['action' => $stepType, 'response' => $response];
                    }

                    /** @var UnipileProvider $concrete */
                    $concrete = $providerManager->get($providerKey, UnipileProvider::class);
                    $response = $concrete->performLinkedinProfileAction('endorse', array_merge($context, [
                        'recipient_id' => $recipientId,
                    ]));

                    return ['action' => 'endorse', 'response' => $response];
                }
            );
        }

        if (in_array($stepType, ['like-post', 'comment-post', 'view-post-engagers'], true)) {
            if ($postId === '') {
                return ['status' => 'skipped', 'payload' => ['reason' => 'missing_post_id']];
            }

            return $this->executeWithProviderFallback(
                $providerManager,
                'campaign_post_action',
                function (string $providerKey) use ($providerManager, $stepType, $postId, $message, $context): array {
                    if ($stepType === 'view-post-engagers') {
                        $response = $providerManager->post($providerKey)->listPostReactions($postId, [], $context);
                        return ['action' => 'view_post_engagers', 'response' => $response];
                    }

                    /** @var UnipileProvider $concrete */
                    $concrete = $providerManager->get($providerKey, UnipileProvider::class);

                    if ($stepType === 'comment-post') {
                        $response = $concrete->performLinkedinProfileAction('comment_post', array_merge($context, [
                            'post_id' => $postId,
                            'text' => $message ?: 'Great insights.',
                        ]));

                        return ['action' => 'comment_post', 'response' => $response];
                    }

                    $response = $concrete->reactToPost($postId, 'like', $context);
                    return ['action' => 'like_post', 'response' => $response];
                }
            );
        }

        return [
            'status' => 'skipped',
            'payload' => ['reason' => 'unsupported_step_type'],
        ];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function resolveRecipientId(V2CampaignRun $run, array $node): string
    {
        $recipientId = (string) ($node['recipient_id'] ?? '');
        if ($recipientId !== '') {
            return $recipientId;
        }

        if (!$run->lead_id) {
            return '';
        }

        $lead = V2Lead::query()->find($run->lead_id);
        if (!$lead) {
            return '';
        }

        return (string) ($lead->provider_profile_id ?: $lead->public_identifier ?: '');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function resolveRecipientName(V2CampaignRun $run, array $node): ?string
    {
        $name = trim((string) ($node['recipient_name'] ?? ($node['lead_name'] ?? '')));
        if ($name !== '') {
            return $name;
        }

        if (!$run->lead_id) {
            return null;
        }

        $lead = V2Lead::query()->find($run->lead_id);

        return $lead?->full_name ?: $lead?->first_name;
    }

    private function resolveMessageText(array $node): string
    {
        return trim((string) ($node['message'] ?? ($node['text'] ?? ($node['body'] ?? ''))));
    }

    private function resolveChatId(array $node): string
    {
        return trim((string) ($node['chat_id'] ?? ($node['provider_chat_id'] ?? '')));
    }

    private function resolveInvitationId(array $node): string
    {
        return trim((string) ($node['invitation_id'] ?? ($node['provider_invitation_id'] ?? '')));
    }

    private function resolvePostId(array $node): string
    {
        return trim((string) ($node['post_id'] ?? ($node['provider_post_id'] ?? '')));
    }

    private function resolveDelaySeconds(array $node): int
    {
        $seconds = (int) ($node['wait_seconds'] ?? $node['delay_seconds'] ?? 0);
        $minutes = (int) ($node['minutes'] ?? 0);
        $hours = (int) ($node['hours'] ?? 0);

        $computed = $seconds + ($minutes * 60) + ($hours * 3600);
        if ($computed <= 0) {
            $computed = 300;
        }

        return min($computed, 86400);
    }

    private function normalizeStepType(string $raw): string
    {
        $value = strtolower(trim($raw));
        $aliases = [
            'send-invite' => 'send-invites',
            'invite' => 'send-invites',
            'connect' => 'send-invites',
            'connection-request' => 'send-invites',
            'message' => 'message',
            'send-message' => 'message',
            'dm' => 'message',
            'followup-message' => 'message',
            'start-chat' => 'start-chat',
            'open-chat' => 'start-chat',
            'init-chat' => 'start-chat',
            'check-reply' => 'check-reply',
            'check-replies' => 'check-reply',
            'mark-read' => 'mark-read',
            'wait' => 'wait',
            'delay' => 'wait',
            'pause' => 'wait',
            'accept-invite' => 'accept-invite',
            'accept-invitation' => 'accept-invite',
            'withdraw-invite' => 'withdraw-invite',
            'cancel-invite' => 'withdraw-invite',
            'call' => 'call',
            'endorse' => 'endorse',
            'profile-view' => 'profile-view',
            'view-profile' => 'profile-view',
            'follow' => 'profile-view',
            'like-post' => 'like-post',
            'comment-post' => 'comment-post',
            'view-post-engagers' => 'view-post-engagers',
            'track-event' => 'track-event',
            'tracking' => 'track-event',
            'custom-track' => 'track-event',
            'stop-if-no-reply' => 'stop-if-no-reply',
            'condition-no-reply' => 'stop-if-no-reply',
            'end-sequence' => 'end-sequence',
            'stop-sequence' => 'end-sequence',
        ];

        return $aliases[$value] ?? $value;
    }

    /**
     * @param callable(string): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function executeWithProviderFallback(
        ProviderManager $providerManager,
        string $operation,
        callable $callback
    ): array {
        $attempts = [];
        $lastError = null;

        foreach ($providerManager->providersForOperation($operation) as $providerKey) {
            if (!$providerManager->isRegistered($providerKey)) {
                $attempts[] = [
                    'provider' => $providerKey,
                    'status' => 'skipped_unregistered',
                ];
                continue;
            }

            try {
                $result = $callback($providerKey);
                $attempts[] = [
                    'provider' => $providerKey,
                    'status' => 'completed',
                ];

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
                $lastError = $exception->getMessage();
                $attempts[] = [
                    'provider' => $providerKey,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [
            'status' => 'failed',
            'error_message' => $lastError ?: "All providers failed for operation [{$operation}].",
            'payload' => [
                'operation' => $operation,
                'attempts' => $attempts,
            ],
        ];
    }

    private function extractChatId(array $response): string
    {
        return (string) (Arr::get($response, 'id') ?? Arr::get($response, 'chat_id') ?? Arr::get($response, 'data.id') ?? '');
    }

    /**
     * @param array<string, mixed> $result
     */
    private function recordStepActivity(V2CampaignRun $run, string $stepType, array $result): void
    {
        if ((string) ($result['status'] ?? '') !== 'completed' || $stepType !== 'profile-view') {
            return;
        }

        $organizationId = (int) Arr::get($run->meta ?? [], 'organization_id', 0);
        if ($organizationId <= 0) {
            return;
        }

        V2UserActivity::query()->create([
            'user_id' => $run->user_id,
            'organization_id' => $organizationId,
            'module' => 'campaign',
            'identifier' => 'profile-view',
            'stat' => 1,
            'meta' => ['campaign_run_id' => $run->id],
        ]);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $result
     */
    private function recordTrackingHooks(V2CampaignRun $run, string $stepType, array $node, array $result): void
    {
        $trackingHook = trim((string) ($node['tracking_hook'] ?? $node['tracking_event'] ?? ''));
        if ($trackingHook === '') {
            return;
        }

        $organizationId = (int) Arr::get($run->meta ?? [], 'organization_id', 0);
        if ($organizationId <= 0) {
            return;
        }

        V2UserActivity::query()->create([
            'user_id' => $run->user_id,
            'organization_id' => $organizationId,
            'module' => 'campaign',
            'identifier' => $trackingHook,
            'stat' => (string) ($result['status'] ?? '') === 'completed' ? 1 : 0,
            'meta' => [
                'campaign_run_id' => $run->id,
                'step_type' => $stepType,
                'step_status' => $result['status'] ?? 'unknown',
                'step_result' => $result['payload'] ?? [],
            ],
        ]);
    }
}
