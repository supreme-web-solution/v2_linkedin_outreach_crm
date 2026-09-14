<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\OneShotOutboundCommandCenterService;
use App\V2\Ai\Services\UserTurnIntentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Stage a cold one-shot outbound (email/DM) without an inbox thread.
 * Wraps OneShotOutboundCommandCenterService — research, compose, approvals stay there.
 */
class DraftColdOutboundTool extends GatedTool
{
    public function toolName(): string
    {
        return 'draft_cold_outbound';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Stage a single cold outbound (email, LinkedIn, WhatsApp, Instagram, Telegram, or X) to a named contact '
            .'when there is no Unified Inbox thread. Use for one-shot sends, recipient corrections, and draft rewrites. '
            .'Does NOT discover prospect lists. Prefer draft_reply when an inbox conversation already exists. '
            .'Pass identity fields when known; otherwise pass owner_message and the tool extracts email/URL/phone/handle.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'owner_message' => $schema->string()->nullable()->description(
                'Raw owner request (or handoff brief). Used to extract identity when channel fields are empty.'
            ),
            'channel' => $schema->string()->nullable()->description(
                'email|linkedin|whatsapp|instagram|telegram|twitter'
            ),
            'email' => $schema->string()->nullable(),
            'phone' => $schema->string()->nullable(),
            'linkedin_url' => $schema->string()->nullable(),
            'instagram_handle' => $schema->string()->nullable(),
            'telegram_handle' => $schema->string()->nullable(),
            'twitter_handle' => $schema->string()->nullable(),
            'research_url' => $schema->string()->nullable()->description('Company/person URL to research for personalization'),
            'display_name' => $schema->string()->nullable(),
            'recipient_correction' => $schema->boolean()->nullable()->description('True when fixing a wrong recipient from prior cold outbound'),
            'message_correction' => $schema->boolean()->nullable()->description('True when rewriting the prior cold draft for the same recipient'),
            'offer_override' => $schema->string()->nullable()->description('Corrected offer/pitch angle when rewriting'),
            'handoff_brief' => $schema->string()->nullable()->description('Interpreter handoff for what to write'),
        ];
    }

    protected function run(Request $request): array
    {
        $intent = app(UserTurnIntentService::class);
        $ownerMessage = trim((string) ($request['owner_message'] ?? $request['handoff_brief'] ?? ''));
        if ($ownerMessage === '') {
            $ownerMessage = 'Stage cold outbound to the contact provided.';
        }

        $identity = $this->identityFromRequest($request);
        if (($identity['channel'] ?? null) === null && $ownerMessage !== '') {
            $extracted = $intent->extractColdOutboundIdentity($ownerMessage);
            $identity = $this->mergeIdentity($identity, $extracted);
        }

        if (($identity['channel'] ?? null) === null) {
            throw new \RuntimeException(
                'Could not resolve a recipient channel. Pass email, LinkedIn/Instagram/Telegram/X URL or handle, or WhatsApp phone.'
            );
        }

        $result = app(OneShotOutboundCommandCenterService::class)->stage(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            $ownerMessage,
            $identity,
            $this->context->channel,
            isset($request['recipient_correction']) ? (bool) $request['recipient_correction'] : null,
            isset($request['handoff_brief']) ? trim((string) $request['handoff_brief']) : null,
            isset($request['message_correction']) ? (bool) $request['message_correction'] : null,
            isset($request['offer_override']) ? trim((string) $request['offer_override']) : null,
        );

        if (! ($result['handled'] ?? false)) {
            throw new \RuntimeException('Could not stage cold outbound.');
        }

        app(\App\V2\Ai\Services\WorkstreamMemoryService::class)->rememberFacts($this->context->conversation, [
            'last_channel' => (string) $identity['channel'],
            'last_recipient' => (string) (
                $identity['email']
                ?? $identity['linkedin_url']
                ?? $identity['phone']
                ?? $identity['instagram_handle']
                ?? $identity['telegram_handle']
                ?? $identity['twitter_handle']
                ?? $identity['display_name']
                ?? ''
            ),
            'research_url' => isset($identity['research_url']) ? (string) $identity['research_url'] : null,
            'offer_override' => isset($request['offer_override']) ? trim((string) $request['offer_override']) : null,
            'goal' => 'Cold outbound to named contact',
        ]);

        $approval = $result['approval'] ?? null;
        $approvalId = $approval instanceof \App\Models\AiActionApproval ? $approval->id : null;

        return [
            'ok' => true,
            'approval_id' => $approvalId,
            'reply' => (string) ($result['reply'] ?? ''),
            'channel' => $identity['channel'],
            'cta' => $approvalId
                ? 'User should Review & Launch to send (LAUNCH '.$approvalId.' on WhatsApp).'
                : 'Cold outbound staged (or already auto-launched per autonomy).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function identityFromRequest(Request $request): array
    {
        $channel = isset($request['channel']) ? strtolower(trim((string) $request['channel'])) : null;
        if ($channel === 'x') {
            $channel = 'twitter';
        }

        return array_filter([
            'channel' => $channel,
            'email' => isset($request['email']) ? trim((string) $request['email']) : null,
            'phone' => isset($request['phone']) ? trim((string) $request['phone']) : null,
            'linkedin_url' => isset($request['linkedin_url']) ? trim((string) $request['linkedin_url']) : null,
            'instagram_handle' => isset($request['instagram_handle']) ? trim((string) $request['instagram_handle']) : null,
            'telegram_handle' => isset($request['telegram_handle']) ? trim((string) $request['telegram_handle']) : null,
            'twitter_handle' => isset($request['twitter_handle']) ? trim((string) $request['twitter_handle']) : null,
            'research_url' => isset($request['research_url']) ? trim((string) $request['research_url']) : null,
            'display_name' => isset($request['display_name']) ? trim((string) $request['display_name']) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergeIdentity(array $base, array $extra): array
    {
        foreach ($extra as $key => $value) {
            if (! isset($base[$key]) || $base[$key] === null || $base[$key] === '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
