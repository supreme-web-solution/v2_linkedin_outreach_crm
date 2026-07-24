<?php

namespace App\V2\Outreach\Channels;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;
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
        $recipientId = trim((string) ($lead->provider_profile_id ?? ''));
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
                    'message' => $message ?: 'Happy to connect.',
                ], $context)),
                'send_message' => $this->sendMessage($providerKey, $recipientId, $message, $context),
                'like_post', 'endorse' => $this->profileAction($providerKey, $action, $recipientId, $context),
                'follow' => $this->completed($this->providerManager->profile($providerKey)->getProfileByIdentifier($recipientId, $context)),
                default => ['status' => 'failed', 'error_message' => "Unsupported LinkedIn action: {$action}"],
            };

            if ($action === 'send_message' && ($result['status'] ?? '') === 'completed') {
                $response = is_array($result['payload']['response'] ?? null) ? $result['payload']['response'] : [];
                $this->unifiedInbox->recordOutboundChat(
                    (int) $campaign->user_id,
                    (int) $campaign->organization_id,
                    'linkedin',
                    $lead,
                    $recipientId,
                    $response,
                    $message ?: 'Hello',
                );
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('[Outreach] LinkedIn action failed', ['action' => $action, 'error' => $e->getMessage()]);

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

        return $this->completed($chat);
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
}
