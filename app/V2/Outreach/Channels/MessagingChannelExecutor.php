<?php

namespace App\V2\Outreach\Channels;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Integrations\ProviderManager;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Outreach\OutreachLeadContactResolver;
use App\V2\Outreach\OutreachSequenceResolver;
use App\V2\Services\UnifiedInboxService;
use Illuminate\Support\Facades\Log;

class MessagingChannelExecutor implements ChannelExecutorInterface
{
    public function __construct(
        private readonly string $channelKey,
        private readonly ProviderManager $providerManager,
        private readonly OutreachLeadContactResolver $contactResolver,
        private readonly UnifiedInboxService $unifiedInbox,
        private readonly OutreachSequenceResolver $resolver = new OutreachSequenceResolver(),
    ) {}

    public function channel(): string
    {
        return $this->channelKey;
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
        if ($action !== 'send_message') {
            return ['status' => 'failed', 'error_message' => "Unsupported {$this->channelKey} action: {$action}"];
        }

        $row = $this->leadContactRow($lead);
        $recipientId = $this->contactResolver->messagingRecipientId($row, $this->channelKey);
        if ($recipientId === null || $recipientId === '') {
            $hint = match ($this->channelKey) {
                'whatsapp' => 'Run Verify WhatsApp before sending.',
                'instagram', 'twitter' => 'Run Resolve handles before sending.',
                default => 'Missing recipient identifier.',
            };

            return ['status' => 'skipped', 'error_message' => $hint];
        }

        $firstName = $this->resolver->firstNameFromLead($lead->full_name);
        $message = $this->resolver->messageText($node, $firstName);

        try {
            $providerKey = $this->providerManager->defaultProvider();
            $response = $this->providerManager->messaging($providerKey)->startChat([
                'attendee_ids' => [$recipientId],
                'text' => $message ?: 'Hello',
            ], array_merge($context, ['channel' => $this->channelKey]));

            $this->unifiedInbox->recordOutboundChat(
                (int) $campaign->user_id,
                (int) $campaign->organization_id,
                $this->channelKey,
                $lead,
                $recipientId,
                is_array($response) ? $response : [],
                $message ?: 'Hello',
            );

            return ['status' => 'completed', 'payload' => ['response' => $response]];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            Log::error('[Outreach] Messaging action failed', [
                'channel' => $this->channelKey,
                'error' => $message,
            ]);

            if ($this->isUnreachableRecipientError($message)) {
                return [
                    'status' => 'skipped',
                    'error_message' => 'Recipient is not reachable on '.OutreachChannelRegistry::channelLabel($this->channelKey).'. Verify the contact first.',
                ];
            }

            return ['status' => 'failed', 'error_message' => $message];
        }
    }

    private function isUnreachableRecipientError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'invalid_recipient')
            || str_contains($normalized, 'recipient cannot be reached')
            || str_contains($normalized, 'profile is not locked');
    }

    /**
     * @return array<string, mixed>
     */
    private function leadContactRow(V2OutreachLead $lead): array
    {
        $meta = is_array($lead->meta) ? $lead->meta : [];

        return [
            'phone' => trim((string) ($lead->phone ?? '')),
            'whatsapp_provider_id' => trim((string) ($meta['whatsapp_provider_id'] ?? '')),
            'instagram_handle' => trim((string) ($meta['instagram_handle'] ?? '')),
            'instagram_provider_id' => trim((string) ($meta['instagram_provider_id'] ?? '')),
            'telegram_handle' => trim((string) ($meta['telegram_handle'] ?? '')),
            'telegram_provider_id' => trim((string) ($meta['telegram_provider_id'] ?? '')),
            'twitter_handle' => trim((string) ($meta['twitter_handle'] ?? '')),
            'twitter_provider_id' => trim((string) ($meta['twitter_provider_id'] ?? '')),
        ];
    }
}
