<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\AiWorkflowRun;
use App\Models\User;

/**
 * Launch or stage campaigns from WhatsApp/web without LLM clarification loops.
 */
class CampaignOutreachPreflightService
{
    public function __construct(
        private readonly UserTurnIntentService $intent,
        private readonly CommandCenterService $commandCenter,
        private readonly WorkflowPrepareOutreachStepHandler $prepareOutreach,
    ) {}

    /**
     * @return array{handled:bool, reply?:string, approval?:AiActionApproval|null}|null
     */
    public function tryHandle(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $message,
    ): ?array {
        if (! $this->intent->isCampaignActionRequest($message)) {
            return null;
        }

        $pending = $this->commandCenter->pendingApprovals($user, $organizationId)->first();
        if ($pending instanceof AiActionApproval) {
            return $this->handlePendingApproval($user, $organizationId, $pending, $message);
        }

        return $this->stageFromRecentDiscovery($user, $organizationId, $conversation, $message);
    }

    /**
     * @return array{handled:bool, reply:string, approval?:AiActionApproval|null}
     */
    private function handlePendingApproval(
        User $user,
        int $organizationId,
        AiActionApproval $approval,
        string $message,
    ): array {
        $wantsSend = ! $this->intent->wantsCampaignSetupOnly($message)
            && (bool) preg_match('/\b(send|launch|start|activate|run)\b/i', $message);

        if ($wantsSend) {
            $result = $this->commandCenter->handleControlCommand($user, $organizationId, 'LAUNCH '.$approval->id);

            return [
                'handled' => (bool) ($result['handled'] ?? false),
                'reply' => (string) ($result['reply'] ?? 'Launching your campaign…'),
                'approval' => $result['approval'] ?? $approval,
            ];
        }

        $card = $this->commandCenter->formatPlanCard(
            $approval->payload ?? [],
            $approval->id,
            'whatsapp',
        );

        return [
            'handled' => true,
            'reply' => trim("Your campaign is ready for Review & Launch.\n\n".$card),
            'approval' => $approval,
        ];
    }

    /**
     * @return array{handled:bool, reply:string, approval?:AiActionApproval|null}|null
     */
    private function stageFromRecentDiscovery(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $message,
    ): ?array {
        $run = AiWorkflowRun::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('status', ['completed', 'waiting', 'running'])
            ->orderByDesc('id')
            ->first();

        if (! $run) {
            return null;
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $lists = is_array($meta['discovery_channel_lists'] ?? null) ? $meta['discovery_channel_lists'] : [];
        if ($lists === [] && ! empty($meta['discovery_list_hash'])) {
            $lists = [[
                'list_hash' => (string) $meta['discovery_list_hash'],
                'list_src' => (string) ($meta['discovery_list_src'] ?? 'sn'),
                'list_name' => (string) ($meta['discovery_list_name'] ?? 'Recent list'),
                'channel' => is_array($meta['platforms_searched'] ?? null)
                    ? (string) ($meta['platforms_searched'][0] ?? '')
                    : '',
            ]];
        }

        if ($lists === []) {
            return null;
        }

        $plan = is_array($run->plan) ? $run->plan : [];
        $plan['required_outcome'] = $this->intent->wantsCampaignSetupOnly($message) ? 'setup_only' : 'send_now';
        $plan['state_evaluation'] = is_array($meta['latest_state'] ?? null) ? $meta['latest_state'] : [];

        $first = $lists[0];
        try {
            $result = $this->prepareOutreach->execute(
                $user,
                $organizationId,
                $plan,
                [
                    'list_hash' => (string) ($first['list_hash'] ?? ''),
                    'list_src' => (string) ($first['list_src'] ?? 'sn'),
                    'list_name' => (string) ($first['list_name'] ?? 'Prospects'),
                    'discovery_lists' => count($lists) > 1 ? $lists : [],
                    'setup_only' => $plan['required_outcome'] === 'setup_only',
                ],
                (int) $run->id,
                (int) $conversation->id,
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $approvalId = (int) ($result['approval_id'] ?? 0);
        $approval = $approvalId > 0
            ? AiActionApproval::query()->find($approvalId)
            : null;

        if ($approval) {
            $card = $this->commandCenter->formatPlanCard(
                $approval->payload ?? [],
                $approval->id,
                'whatsapp',
            );

            return [
                'handled' => true,
                'reply' => trim(
                    "✅ Campaign staged for your recent prospect list.\n\n"
                    .$card
                    ."\n\nTap **Launch** below when you're ready — nothing sends until you approve."
                ),
                'approval' => $approval,
            ];
        }

        return [
            'handled' => true,
            'reply' => 'Campaign staging is in progress — I\'ll post Review & Launch here when ready.',
        ];
    }
}
