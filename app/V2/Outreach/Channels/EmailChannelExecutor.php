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
            $email = trim((string) (app(\App\V2\Outreach\CampaignEmailEnrichmentWaveService::class)
                ->refreshLeadEmail($lead) ?? ''));
        }

        if ($email === '') {
            // Still enriching or daily cap — retry later instead of permanently skipping.
            $meta = is_array($campaign->meta) ? $campaign->meta : [];
            $auto = is_array($meta['auto_email_enrich'] ?? null) ? $meta['auto_email_enrich'] : [];
            if (! empty($auto['enabled']) || app(\App\V2\Outreach\CampaignEmailEnrichmentWaveService::class)->campaignNeedsEmail($campaign)) {
                $resumeAt = ! empty($auto['deferred_until_tomorrow'])
                    ? now()->addDay()->startOfDay()->addMinutes(random_int(10, 40))
                    : now()->addMinutes(random_int(20, 45));

                return [
                    'status' => 'deferred',
                    'error_message' => 'Waiting for email enrichment.',
                    'next_run_at' => $resumeAt,
                    'payload' => ['reason' => 'awaiting_email_enrichment'],
                ];
            }

            return ['status' => 'skipped', 'error_message' => 'Lead has no email address.'];
        }

        if ($action !== 'send_email') {
            return ['status' => 'failed', 'error_message' => "Unsupported email action: {$action}"];
        }

        $firstName = $this->resolver->firstNameFromLead($lead->full_name);
        $content = $this->resolver->emailContent($node, $firstName);
        $content['body'] = app(\App\V2\Ai\Services\CampaignFirstTouchPersonalizationService::class)
            ->resolveMessageText($lead, $content['body'] ?: 'Hi there,');

        $owner = \App\Models\User::query()->find((int) $campaign->user_id);
        $content['subject'] = \App\V2\Ai\Support\RecipientFacingCopyGuard::prepareOutbound(
            (string) ($content['subject'] ?? ''),
            [
                'user' => $owner,
                'organization_id' => (int) $campaign->organization_id,
                'lead' => $lead,
                'first_name' => $firstName,
                'campaign' => $campaign,
            ],
        );
        $content['body'] = \App\V2\Ai\Support\RecipientFacingCopyGuard::prepareOutbound(
            (string) ($content['body'] ?? ''),
            [
                'user' => $owner,
                'organization_id' => (int) $campaign->organization_id,
                'lead' => $lead,
                'first_name' => $firstName,
                'campaign' => $campaign,
            ],
        );

        $block = \App\V2\Ai\Support\RecipientFacingCopyGuard::blockReason($content['body'] ?? '');
        if ($block !== null) {
            return ['status' => 'failed', 'error_message' => 'Blocked unsafe outbound email: '.$block];
        }

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
