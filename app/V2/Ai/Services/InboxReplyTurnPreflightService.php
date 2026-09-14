<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Services\InboxConversationResolverService;
use Illuminate\Support\Str;

/**
 * Optional short-circuit for inbox reply staging when socifusion_ai.outbound_preflight=true.
 * Default path: SociFusionAgent calls draft_reply (or draft_cold_outbound when no thread).
 */
class InboxReplyTurnPreflightService
{
    public function __construct(
        private readonly UserTurnIntentService $intent,
        private readonly InboxCommandCenterService $inbox,
        private readonly InboxConversationResolverService $resolver,
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly OneShotOutboundCommandCenterService $coldOutbound,
        private readonly SemanticTurnPlanService $semanticPlanner,
    ) {}

    /**
     * @return array{handled:bool, reply?:string, approval?:AiActionApproval|null}|null
     */
    public function tryHandle(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $message,
        string $channel = 'web',
    ): ?array {
        if (! $this->wantsInboxReply($user, $organizationId, $conversation, $message)) {
            return null;
        }

        if ($this->intent->wantsDraftOnly($message)) {
            return null;
        }

        $target = $this->intent->extractInboxReplyTarget($message);
        $v2Conversation = $this->resolver->findForUser(
            $user,
            email: $target['email'],
            prospectName: $target['name'],
        );

        if (! $v2Conversation) {
            // No prior thread — if they named a contact, treat as cold outbound (all channels).
            $cold = $this->coldOutbound->tryHandle(
                $user,
                $organizationId,
                $conversation,
                $message,
                $channel,
            );
            if ($cold !== null) {
                return $cold;
            }

            return [
                'handled' => true,
                'reply' => 'No inbox thread yet for that person. Paste their email, LinkedIn URL, Instagram/Telegram/X handle, or WhatsApp number and I will stage a one-shot send for Review & Launch.',
                'approval' => null,
            ];
        }

        $staged = $this->inbox->stageDraftReply(
            $user,
            $organizationId,
            $conversation,
            (int) $v2Conversation->id,
            null,
            $channel,
        );

        if ($staged['blocked'] ?? false) {
            return [
                'handled' => true,
                'reply' => (string) ($staged['message'] ?? 'AI Employee is blocked for this workspace.'),
                'approval' => null,
            ];
        }

        $plan = is_array($staged['plan'] ?? null) ? $staged['plan'] : [];
        $prospect = (string) ($plan['prospect_name'] ?? 'prospect');
        $channelLabel = (string) ($plan['channel_label'] ?? $plan['channel'] ?? 'inbox');
        $draft = trim((string) ($plan['draft_text'] ?? ''));
        $inboxUrl = (string) ($plan['inbox_url'] ?? '');
        $approval = $staged['approval'] ?? null;

        if ($staged['auto_sent'] ?? false) {
            return [
                'handled' => true,
                'reply' => implode("\n\n", array_filter([
                    "Sent a tailored **{$channelLabel}** reply to **{$prospect}**.",
                    $draft !== '' ? "> {$draft}" : null,
                    $inboxUrl !== '' ? "[Open thread]({$inboxUrl})" : null,
                ])),
                'approval' => $approval instanceof AiActionApproval ? $approval : null,
            ];
        }

        $settings = $this->settingsService->for($user, $organizationId);
        $autonomy = $this->settingsService->autonomy($settings);

        if ($autonomy->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'handled' => true,
                'reply' => implode("\n\n", array_filter([
                    "**{$channelLabel} draft for {$prospect}** (Copilot — review before sending):",
                    $draft !== '' ? "> {$draft}" : null,
                    $inboxUrl !== '' ? "[Open inbox to send]({$inboxUrl})" : null,
                ])),
                'approval' => null,
            ];
        }

        $approvalId = $approval instanceof AiActionApproval ? (int) $approval->id : null;

        return [
            'handled' => true,
            'reply' => implode("\n\n", array_filter([
                "**{$channelLabel} reply for {$prospect}** — ready for review:",
                $draft !== '' ? "> {$draft}" : null,
                $approvalId
                    ? "Tap **Send** below or say **LAUNCH {$approvalId}**."
                    : ($inboxUrl !== '' ? "[Open inbox]({$inboxUrl})" : null),
            ])),
            'approval' => $approval instanceof AiActionApproval ? $approval : null,
        ];
    }

    /**
     * Prefer semantic inbox_reply; keyword heuristics only when the planner is unavailable.
     */
    private function wantsInboxReply(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $message,
    ): bool {
        $interpreted = $this->semanticPlanner->interpret($user, $organizationId, $message, $conversation);
        if (is_array($interpreted)) {
            $semantic = is_array($interpreted['semantic'] ?? null) ? $interpreted['semantic'] : [];

            return (bool) ($semantic['inbox_reply'] ?? false);
        }

        // Planner unavailable — emergency keyword heuristic only.
        return $this->intent->isInboxReplyRequest($message);
    }
}
