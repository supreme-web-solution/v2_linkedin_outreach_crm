<?php

namespace App\V2\Ai\Services;

use App\Models\AiChannelIdentity;
use App\Models\AiMessage;
use App\Models\User;
use App\V2\Ai\Integrations\ZernioClient;
use App\V2\Ai\Support\WhatsAppNotificationFormatter;
use Illuminate\Support\Facades\Cache;
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

        if (($meta['auto_sent'] ?? false) === true) {
            $approvalId = null;
        }

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

        if ($mirrorWhatsApp ?? $this->shouldMirrorWhatsApp($user, $meta)) {
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

        if (($meta['auto_sent'] ?? false) === true) {
            $approvalId = null;
        }

        $body = is_string($meta['whatsapp_body'] ?? null) && trim($meta['whatsapp_body']) !== ''
            ? trim($meta['whatsapp_body'])
            : $this->whatsappBody($content, $approvalId, $meta);

        $hash = hash('sha256', $body);
        $cacheKey = 'cc_whatsapp_push:'.$user->id;
        if (Cache::get($cacheKey) === $hash) {
            return false;
        }

        $buttons = null;
        if ($approvalId !== null && $approvalId > 0) {
            $tool = is_string($meta['tool'] ?? null) ? $meta['tool'] : null;
            $payload = is_array($meta['payload'] ?? null) ? $meta['payload'] : null;
            $buttons = $this->zernio->approvalButtons($approvalId, $tool, $payload);
        }

        $sent = $this->zernio->sendToIdentity($identity, $body, $buttons);
        if ($sent) {
            Cache::put($cacheKey, $hash, now()->addMinutes(3));

            $source = (string) ($meta['source'] ?? '');
            if ($source === 'proactive_inbound') {
                Cache::put('cc_whatsapp_proactive:'.$user->id, now()->toIso8601String(), now()->addMinutes(10));
            }
        } else {
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
    private function shouldMirrorWhatsApp(User $user, array $meta): bool
    {
        $source = (string) ($meta['source'] ?? '');

        if ($source === 'attention_digest' && $this->recentProactiveWhatsApp($user->id)) {
            return (bool) config('socifusion_ai.attention_digest.whatsapp_after_proactive', false);
        }

        return match ($source) {
            'attention_digest' => (bool) config('socifusion_ai.attention_digest.mirror_whatsapp', true),
            'inbound_reply_watch', 'proactive_inbound' => (bool) config('socifusion_ai.proactive_inbound_whatsapp', true),
            default => (bool) config('socifusion_ai.command_center_mirror_whatsapp', true),
        };
    }

    private function recentProactiveWhatsApp(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $last = Cache::get('cc_whatsapp_proactive:'.$userId);
        if (! is_string($last) || $last === '') {
            return false;
        }

        try {
            return \Illuminate\Support\Carbon::parse($last)->gte(now()->subMinutes(10));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function whatsappBody(string $content, ?int $approvalId, array $meta = []): string
    {
        $plain = WhatsAppNotificationFormatter::plain($content);

        if (($meta['auto_sent'] ?? false) === true) {
            return $plain;
        }

        if ($approvalId !== null && $approvalId > 0 && ! str_contains($plain, (string) $approvalId)) {
            $plain = trim($plain."\n\nTap Send to approve (Launch {$approvalId}).");
        }

        return $plain;
    }
}
