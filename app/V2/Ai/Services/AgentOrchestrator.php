<?php

namespace App\V2\Ai\Services;

use App\Ai\Agents\SociFusionAgent;
use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\V2\Ai\AgentContext;
use App\V2\Ai\Enums\AiAutonomyLevel;
use Throwable;

class AgentOrchestrator
{
    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly CommandCenterService $commandCenter,
        private readonly AutonomyContextService $autonomyContext,
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

        $settings = $this->settingsService->for($user, $organizationId);
        $modeChangeNote = $this->autonomyContext->syncConversationMode($conversation, $settings);
        $promptMessage = $this->autonomyContext->promptPrefix($settings, $modeChangeNote).$promptMessage;

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

            $latestApproval = $this->maybeAutoLaunchOutreachPlan(
                $user,
                $organizationId,
                $reply,
                $latestApproval,
            );

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

        // Always attach plan card on WhatsApp when a pending approval exists
        if ($latestApproval && $channel === 'whatsapp' && $latestApproval->status === 'pending') {
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

        return $this->payload($user, $organizationId, $conversation->id, $reply, $latestApproval, $settings);
    }

    private function maybeAutoLaunchOutreachPlan(
        User $user,
        int $organizationId,
        string &$reply,
        ?AiActionApproval $latestApproval,
    ): ?AiActionApproval {
        if (! $latestApproval || $latestApproval->status !== 'pending') {
            return $latestApproval;
        }

        $settings = $this->settingsService->for($user, $organizationId);
        $autonomy = AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
        if ($autonomy->value < AiAutonomyLevel::Autopilot->value) {
            return $latestApproval;
        }

        if (! $this->commandCenter->isAutoLaunchApproval($latestApproval)) {
            return $latestApproval;
        }

        $this->commandCenter->attachAutoAudience($user, $organizationId, $latestApproval);
        $launch = $this->commandCenter->handleControlCommand(
            $user,
            $organizationId,
            'LAUNCH '.$latestApproval->id,
        );

        if (! ($launch['handled'] ?? false) || ($launch['decision'] ?? '') !== 'approve') {
            return $latestApproval->fresh();
        }

        $reply = trim($reply."\n\n".(string) ($launch['reply'] ?? ''));
        $approved = $launch['approval'] ?? null;

        return $approved instanceof AiActionApproval ? $approved : $latestApproval->fresh();
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
        ?\App\Models\AiEmployeeSetting $settings = null,
    ): array {
        $settings ??= $this->settingsService->for($user, $organizationId);
        $pending = $this->commandCenter->pendingApprovals($user, $organizationId);
        $serialized = $this->commandCenter->serializeApprovals($pending);

        return [
            'conversation_id' => $conversationId,
            'reply' => $reply,
            'blocked' => false,
            'autonomy_level' => (int) $settings->autonomy_level,
            'autonomy_label' => $this->autonomyContext->label((int) $settings->autonomy_level),
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
