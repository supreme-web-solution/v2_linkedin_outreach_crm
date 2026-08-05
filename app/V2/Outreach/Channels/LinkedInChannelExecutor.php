<?php

namespace App\V2\Outreach\Channels;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Outreach\OutreachSendProof;
use App\V2\Outreach\OutreachSequenceResolver;
use App\V2\Services\UnifiedInboxService;
use Illuminate\Support\Facades\Log;

class LinkedInChannelExecutor implements ChannelExecutorInterface
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly UnifiedInboxService $unifiedInbox,
        private readonly OutreachSequenceResolver $resolver = new OutreachSequenceResolver(),
    ) {}

    public function channel(): string
    {
        return 'linkedin';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function execute(
        string $action,
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        array $node,
        array $context,
    ): array {
        $recipientId = $this->resolveRecipientId($campaign, $lead, $context);
        $firstName = $this->resolver->firstNameFromLead($lead->full_name);
        $message = $this->resolver->messageText($node, $firstName);
        $providerKey = $this->providerManager->defaultProvider();

        if ($recipientId === '' && ! in_array($action, [], true)) {
            return ['status' => 'skipped', 'error_message' => 'Missing LinkedIn profile ID for lead.'];
        }

        try {
            $result = match ($action) {
                'visit_profile' => $this->completed($this->providerManager->profile($providerKey)->getProfileByIdentifier($recipientId, $context)),
                'send_invite' => $this->completed($this->providerManager->invitation($providerKey)->sendInvitation([
                    'recipient_id' => $recipientId,
                    'message' => $message,
                ], $context)),
                'send_message' => $this->sendMessage($providerKey, $recipientId, $message, $context),
                'like_post', 'endorse' => $this->profileAction($providerKey, $action, $recipientId, $context),
                'follow' => $this->completed($this->providerManager->profile($providerKey)->getProfileByIdentifier($recipientId, $context)),
                default => ['status' => 'failed', 'error_message' => "Unsupported LinkedIn action: {$action}"],
            };

            if ($action === 'send_message' && ($result['status'] ?? '') === 'completed') {
                $response = is_array($result['payload']['response'] ?? null) ? $result['payload']['response'] : [];
                $conversation = $this->unifiedInbox->recordOutboundChat(
                    (int) $campaign->user_id,
                    (int) $campaign->organization_id,
                    'linkedin',
                    $lead,
                    $recipientId,
                    $response,
                    $message ?: 'Hello',
                );

                if ($conversation === null) {
                    return [
                        'status' => 'failed',
                        'error_message' => 'LinkedIn message could not be linked to inbox — treat as not sent.',
                    ];
                }

                $result['payload']['conversation_id'] = $conversation->id;
            }

            if ($action === 'send_message' && ($result['status'] ?? '') === 'awaiting_send_confirmation') {
                $response = is_array($result['payload']['response'] ?? null) ? $result['payload']['response'] : [];
                $conversation = $this->unifiedInbox->recordOutboundChat(
                    (int) $campaign->user_id,
                    (int) $campaign->organization_id,
                    'linkedin',
                    $lead,
                    $recipientId,
                    $response,
                    $message ?: 'Hello',
                );

                if ($conversation === null) {
                    return [
                        'status' => 'failed',
                        'error_message' => 'LinkedIn message could not be linked to inbox — treat as not sent.',
                    ];
                }

                $result['payload']['conversation_id'] = $conversation->id;
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('[Outreach] LinkedIn action failed', ['action' => $action, 'error' => $e->getMessage()]);

            $linkedIn = app(\App\V2\Services\LinkedInConnectionService::class);
            if ($linkedIn->isDisconnectedError($e)
                || app(\App\V2\Outreach\OutreachChannelGuard::class)->isDisconnected($e)) {
                return [
                    'status' => 'channel_disconnected',
                    'error_message' => $e->getMessage(),
                    'payload' => ['channel' => 'linkedin'],
                ];
            }

            $tempLimit = app(\App\V2\Services\UnipileTemporaryLimitGuard::class);
            if ($tempLimit->isTemporaryLimit($e)) {
                $quotaAction = match ($action) {
                    'send_invite' => \App\V2\Services\UnipileDailyActionLimiter::ACTION_INVITES,
                    'send_message' => \App\V2\Services\UnipileDailyActionLimiter::ACTION_MESSAGES,
                    default => null,
                };
                if ($quotaAction !== null) {
                    app(\App\V2\Services\UnipileDailyActionLimiter::class)->release((int) $campaign->user_id, $quotaAction);
                }

                return $tempLimit->deferredResult(
                    (int) $campaign->user_id,
                    \App\V2\Services\UnipileTemporaryLimitGuard::ACTION_LINKEDIN,
                    $e->getMessage(),
                );
            }

            return ['status' => 'failed', 'error_message' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sendMessage(string $providerKey, string $recipientId, string $message, array $context): array
    {
        $chat = $this->providerManager->messaging($providerKey)->startChat([
            'attendee_ids' => [$recipientId],
            'text' => $message ?: 'Hello',
        ], $context);

        $response = is_array($chat) ? $chat : [];
        $proof = OutreachSendProof::fromResponse($response);

        if ($proof['chat_id'] === '' && $proof['provider_message_id'] === '') {
            return [
                'status' => 'failed',
                'error_message' => 'LinkedIn did not confirm the message was sent (missing chat/message id).',
            ];
        }

        if ($proof['provider_message_id'] === '') {
            return [
                'status' => 'awaiting_send_confirmation',
                'payload' => [
                    'response' => $response,
                    'chat_id' => $proof['chat_id'],
                ],
            ];
        }

        return [
            'status' => 'completed',
            'payload' => [
                'response' => $response,
                'chat_id' => $proof['chat_id'],
                'provider_message_id' => $proof['provider_message_id'],
                'confirmed_sent' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function profileAction(string $providerKey, string $action, string $recipientId, array $context): array
    {
        /** @var UnipileProvider $concrete */
        $concrete = $this->providerManager->get($providerKey, UnipileProvider::class);
        $mapped = $action === 'like_post' ? 'like_post' : ($action === 'endorse' ? 'endorse' : 'view_profile');

        return $this->completed($concrete->performLinkedinProfileAction($mapped, array_merge($context, [
            'recipient_id' => $recipientId,
        ])));
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function completed(array $response): array
    {
        return [
            'status' => 'completed',
            'payload' => ['response' => $response],
        ];
    }

    /**
     * Audience/import lists often store LinkedIn vanity slugs — resolve to ACo… before Unipile calls.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveRecipientId(V2OutreachCampaign $campaign, V2OutreachLead $lead, array $context): string
    {
        $raw = trim((string) ($lead->provider_profile_id ?? ''));
        $profileUrl = trim((string) ($lead->profile_url ?? ''));

        if ($raw === '' && $profileUrl !== '' && preg_match('~linkedin\.com/in/([^/?#]+)~i', $profileUrl, $matches)) {
            $raw = $matches[1];
        }

        if ($raw === '') {
            return '';
        }

        if (preg_match('/^(ACo|ADo|ACw|AE)/i', $raw)) {
            return $raw;
        }

        $accountId = trim((string) ($context['account_id'] ?? ''));
        $providerKey = $this->providerManager->defaultProvider();
        /** @var UnipileProvider $provider */
        $provider = $this->providerManager->profile($providerKey);

        if ($profileUrl !== '' && str_contains($profileUrl, 'linkedin.com/in/') && $accountId !== '') {
            try {
                $normalized = $provider->getProfileByUrl($profileUrl, $accountId);
                $providerId = trim((string) ($normalized['provider_id'] ?? $normalized['id'] ?? ''));
                if ($providerId !== '' && preg_match('/^(ACo|ADo|ACw|AE)/i', $providerId)) {
                    $this->persistResolvedProviderId($lead, $providerId);

                    return $providerId;
                }
            } catch (\Throwable) {
                // Fall through to identifier lookup.
            }
        }

        try {
            $resolved = $provider->resolveProviderId($raw, $context);
            $providerId = trim((string) ($resolved['provider_id'] ?? ''));
            if ($providerId !== '' && $this->isResolvedLinkedInMemberId($providerId)) {
                $this->persistResolvedProviderId($lead, $providerId);

                return $providerId;
            }
        } catch (\Throwable $e) {
            Log::warning('[Outreach] LinkedIn provider id resolution failed', [
                'lead_id' => $lead->id,
                'identifier' => $raw,
                'error' => $e->getMessage(),
            ]);
        }

        return $raw;
    }

    private function isResolvedLinkedInMemberId(string $providerId): bool
    {
        return (bool) preg_match('/^(ACo|ADo|ACw|AE)/i', trim($providerId));
    }

    private function persistResolvedProviderId(V2OutreachLead $lead, string $providerId): void
    {
        if ($providerId === '' || $providerId === (string) ($lead->provider_profile_id ?? '')) {
            return;
        }

        $lead->forceFill(['provider_profile_id' => $providerId])->save();
    }
}
