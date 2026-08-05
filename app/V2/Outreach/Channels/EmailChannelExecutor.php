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

class EmailChannelExecutor implements ChannelExecutorInterface
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly UnifiedInboxService $unifiedInbox,
        private readonly OutreachSequenceResolver $resolver = new OutreachSequenceResolver(),
    ) {}

    public function channel(): string
    {
        return 'email';
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
        $email = trim((string) ($lead->email ?? ''));
        if ($email === '') {
            $email = trim((string) ($lead->meta['email'] ?? ''));
        }

        if ($email === '') {
            return ['status' => 'skipped', 'error_message' => 'Lead has no email address.'];
        }

        if ($action !== 'send_email') {
            return ['status' => 'failed', 'error_message' => "Unsupported email action: {$action}"];
        }

        $firstName = $this->resolver->firstNameFromLead($lead->full_name);
        $content = $this->resolver->emailContent($node, $firstName);

        try {
            $providerKey = $this->providerManager->defaultProvider();
            /** @var UnipileProvider $concrete */
            $concrete = $this->providerManager->get($providerKey, UnipileProvider::class);
            $subject = $content['subject'] ?: 'Hello';
            $body = $content['body'] ?: 'Hi there,';
            $response = $concrete->sendEmail([
                'to' => [['identifier' => $email]],
                'subject' => $subject,
                'body' => $body,
            ], $context);

            $responseArray = is_array($response) ? $response : [];
            $proof = OutreachSendProof::fromResponse($responseArray);

            $conversation = $this->unifiedInbox->recordOutboundEmail(
                (int) $campaign->user_id,
                (int) $campaign->organization_id,
                $lead,
                $email,
                $responseArray,
                $subject,
                $body,
            );

            if ($conversation === null) {
                return [
                    'status' => 'failed',
                    'error_message' => 'Email could not be linked to inbox — treat as not sent.',
                ];
            }

            if ($proof['provider_message_id'] === '') {
                return [
                    'status' => 'awaiting_send_confirmation',
                    'payload' => [
                        'response' => $responseArray,
                        'conversation_id' => $conversation->id,
                    ],
                ];
            }

            return [
                'status' => 'completed',
                'payload' => [
                    'response' => $responseArray,
                    'provider_message_id' => $proof['provider_message_id'],
                    'conversation_id' => $conversation->id,
                    'confirmed_sent' => true,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[Outreach] Email action failed', ['error' => $e->getMessage()]);

            $linkedIn = app(\App\V2\Services\LinkedInConnectionService::class);
            if ($linkedIn->isDisconnectedError($e)
                || app(\App\V2\Outreach\OutreachChannelGuard::class)->isDisconnected($e)) {
                return [
                    'status' => 'channel_disconnected',
                    'error_message' => $e->getMessage(),
                    'payload' => ['channel' => 'email'],
                ];
            }

            return ['status' => 'failed', 'error_message' => $e->getMessage()];
        }
    }
}
