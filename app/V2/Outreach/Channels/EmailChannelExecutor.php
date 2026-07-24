<?php

namespace App\V2\Outreach\Channels;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Outreach\OutreachSequenceResolver;
use Illuminate\Support\Facades\Log;

class EmailChannelExecutor implements ChannelExecutorInterface
{
    public function __construct(
        private readonly ProviderManager $providerManager,
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
            $response = $concrete->sendEmail([
                'to' => [['identifier' => $email]],
                'subject' => $content['subject'] ?: 'Hello',
                'body' => $content['body'] ?: 'Hi there,',
            ], $context);

            return ['status' => 'completed', 'payload' => ['response' => $response]];
        } catch (\Throwable $e) {
            Log::error('[Outreach] Email action failed', ['error' => $e->getMessage()]);

            return ['status' => 'failed', 'error_message' => $e->getMessage()];
        }
    }
}
