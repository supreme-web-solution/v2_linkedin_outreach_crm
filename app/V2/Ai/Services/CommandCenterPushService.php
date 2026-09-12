<?php

namespace App\V2\Ai\Services;

use App\Models\AiChannelIdentity;
use App\Models\AiMessage;
use App\Models\User;
use App\V2\Ai\Integrations\ZernioClient;
use Illuminate\Support\Facades\Log;

/**
 * Post assistant messages to Command Center and mirror to linked WhatsApp when configured.
 */
class CommandCenterPushService
{
    public function __construct(
        private readonly CommandCenterService $commandCenter,
        private readonly ZernioClient $zernio,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function postAssistant(
        User $user,
        int $organizationId,
        string $content,
        array $meta = [],
        ?int $approvalId = null,
        ?bool $mirrorWhatsApp = null,
    ): AiMessage {
        $content = trim($content);
        $conversation = $this->commandCenter->conversation($user, $organizationId);

        $messageMeta = array_merge([
            'channel' => 'web',
        ], $meta);

        if ($approvalId !== null && $approvalId > 0) {
            $messageMeta['approval_id'] = $approvalId;
        }

        $message = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $content,
            'meta' => $messageMeta,
        ]);

        if ($mirrorWhatsApp ?? $this->shouldMirrorWhatsApp($meta)) {
            $this->mirrorToWhatsApp($user, $organizationId, $content, $approvalId, $meta);
        }

        return $message;
    }

    public function whatsAppIdentity(User $user, int $organizationId): ?AiChannelIdentity
    {
        return AiChannelIdentity::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('channel', 'whatsapp')
            ->where('status', 'active')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function mirrorToWhatsApp(
        User $user,
        int $organizationId,
        string $content,
        ?int $approvalId = null,
        array $meta = [],
    ): bool {
        if (! $this->zernio->configured()) {
            return false;
        }

        $identity = $this->whatsAppIdentity($user, $organizationId);
        if (! $identity) {
            return false;
        }

        $buttons = null;
        if ($approvalId !== null && $approvalId > 0) {
            $tool = is_string($meta['tool'] ?? null) ? $meta['tool'] : null;
            $payload = is_array($meta['payload'] ?? null) ? $meta['payload'] : null;
            $buttons = $this->zernio->approvalButtons($approvalId, $tool, $payload);
        }

        $body = $this->whatsappBody($content, $approvalId);
        $sent = $this->zernio->sendToIdentity($identity, $body, $buttons);

        if (! $sent) {
            Log::warning('[CommandCenterPush] WhatsApp mirror failed', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'approval_id' => $approvalId,
            ]);
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function shouldMirrorWhatsApp(array $meta): bool
    {
        $source = (string) ($meta['source'] ?? '');

        return match ($source) {
            'attention_digest' => (bool) config('socifusion_ai.attention_digest.mirror_whatsapp', true),
            'inbound_reply_watch', 'proactive_inbound' => (bool) config('socifusion_ai.proactive_inbound_whatsapp', true),
            default => (bool) config('socifusion_ai.command_center_mirror_whatsapp', true),
        };
    }

    private function whatsappBody(string $content, ?int $approvalId): string
    {
        $plain = trim(strip_tags(str_replace(['**', '[', ']', '(', ')'], ' ', $content)));
        $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;

        if ($approvalId !== null && $approvalId > 0 && ! str_contains($plain, (string) $approvalId)) {
            $plain = trim($plain."\n\nTap Send to approve (Launch {$approvalId}).");
        }

        return $plain;
    }
}
