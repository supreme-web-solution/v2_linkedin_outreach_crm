<?php

namespace App\V2\Ai\Services;

use App\Ai\Agents\SociFusionAgent;
use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\V2\Ai\AgentContext;
use Throwable;

class AgentOrchestrator
{
    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly CommandCenterService $commandCenter,
    ) {}

    /**
     * @return array{
     *     conversation_id:int,
     *     reply:string,
     *     blocked?:bool,
     *     duplicate?:bool,
     *     pending_approvals?:list<array<string,mixed>>,
     *     latest_approval?:array<string,mixed>|null
     * }
     */
    public function handle(
        User $user,
        int $organizationId,
        string $message,
        string $channel = 'web',
        ?int $conversationId = null,
        ?int $channelIdentityId = null,
        ?string $providerMessageId = null,
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);

        if ($this->settingsService->isBlocked($settings)) {
            return [
                'conversation_id' => $conversationId ?? 0,
                'reply' => 'AI Employee is currently disabled for this workspace.',
                'blocked' => true,
                'pending_approvals' => [],
            ];
        }

        // Shared Command Center session (web + WhatsApp = same brain/thread)
        $conversation = $this->commandCenter->conversation($user, $organizationId, $channelIdentityId);

        if ($conversationId) {
            $requested = AiConversation::query()
                ->whereKey($conversationId)
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->first();

            if ($requested) {
                $conversation = $requested;
            }
        }

        if ($providerMessageId) {
            $exists = AiMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('provider_message_id', $providerMessageId)
                ->exists();

            if ($exists) {
                return [
                    'conversation_id' => $conversation->id,
                    'reply' => '',
                    'blocked' => false,
                    'duplicate' => true,
                    'pending_approvals' => $this->commandCenter->serializeApprovals(
                        $this->commandCenter->pendingApprovals($user, $organizationId)
                    ),
                ];
            }
        }

        $control = $this->commandCenter->handleControlCommand($user, $organizationId, $message);
        $promptMessage = $message;

        if ($control && ($control['handled'] ?? false)) {
            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $message,
                'provider_message_id' => $providerMessageId,
                'meta' => ['channel' => $channel],
            ]);

            $reply = (string) ($control['reply'] ?? '');

            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $reply,
                'meta' => ['channel' => $channel, 'control' => $control['decision'] ?? 'command'],
            ]);

            return $this->payload(
                $user,
                $organizationId,
                $conversation->id,
                $reply,
                isset($control['approval']) && $control['approval']->status === 'pending'
                    ? $control['approval']
                    : null,
            );
        }

        if ($control && ! empty($control['rewrite'])) {
            $promptMessage = (string) $control['rewrite'];
        }

        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $message,
            'provider_message_id' => $providerMessageId,
            'meta' => ['channel' => $channel],
        ]);

        $context = new AgentContext(
            user: $user,
            organizationId: $organizationId,
            settings: $settings,
            conversation: $conversation,
            channel: $channel,
        );

        $latestApproval = null;

        try {
            $response = (new SociFusionAgent($context))->prompt($promptMessage);
            $reply = trim((string) $response);
            $latestApproval = $this->commandCenter->pendingApprovals($user, $organizationId)->first();

            // Prefer structured plan card on WhatsApp when a pending approval was just created
            if ($channel === 'whatsapp' && $latestApproval && str_contains(mb_strtolower($reply), 'plan')) {
                // keep model reply; card also available in pending_approvals for client
            }
        } catch (Throwable $e) {
            report($e);
            $reply = 'I hit an error processing that. Please try again in a moment.';
        }

        if ($reply === '') {
            $reply = 'Done.';
        }

        // If tools staged an approval but the model reply is thin, append WA-friendly card
        if ($latestApproval && $channel === 'whatsapp' && ! str_contains($reply, 'LAUNCH '.$latestApproval->id)) {
            $card = $this->commandCenter->formatPlanCard(
                $latestApproval->payload ?? [],
                $latestApproval->id,
                'whatsapp'
            );
            if (! str_contains($reply, (string) $latestApproval->id)) {
                $reply = trim($reply."\n\n".$card);
            }
        }

        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
            'meta' => [
                'channel' => $channel,
                'approval_id' => $latestApproval?->id,
            ],
        ]);

        return $this->payload($user, $organizationId, $conversation->id, $reply, $latestApproval);
    }

    /**
     * @return array{
     *     conversation_id:int,
     *     reply:string,
     *     blocked:bool,
     *     pending_approvals:list<array<string,mixed>>,
     *     latest_approval:array<string,mixed>|null
     * }
     */
    private function payload(
        User $user,
        int $organizationId,
        int $conversationId,
        string $reply,
        ?AiActionApproval $latest = null,
    ): array {
        $pending = $this->commandCenter->pendingApprovals($user, $organizationId);
        $serialized = $this->commandCenter->serializeApprovals($pending);

        return [
            'conversation_id' => $conversationId,
            'reply' => $reply,
            'blocked' => false,
            'pending_approvals' => $serialized,
            'latest_approval' => $latest && $latest->status === 'pending'
                ? ($serialized[0] ?? [
                    'id' => $latest->id,
                    'status' => $latest->status,
                    'tool' => $latest->tool,
                    'payload' => $latest->payload,
                    'card_text' => $this->commandCenter->formatPlanCard($latest->payload ?? [], $latest->id, 'web'),
                ])
                : null,
        ];
    }
}
