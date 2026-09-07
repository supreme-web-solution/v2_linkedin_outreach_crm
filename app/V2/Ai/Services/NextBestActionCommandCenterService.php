<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2OutreachLead;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use Illuminate\Support\Arr;

class NextBestActionCommandCenterService
{
    public function __construct(
        private readonly InboxClassificationService $classifier,
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settingsService,
    ) {}

    /**
     * @return array{
     *     approval: AiActionApproval|null,
     *     plan: array<string,mixed>,
     *     card: string,
     *     approval_id: int|null,
     *     blocked?: bool,
     *     message?: string
     * }
     */
    public function stage(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        ?int $outreachLeadId,
        ?int $v2ConversationId,
        string $action,
        ?string $reason,
        string $surface = 'web',
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);

        if ($this->settingsService->isBlocked($settings)) {
            return [
                'approval' => null,
                'approval_id' => null,
                'plan' => [],
                'card' => '',
                'blocked' => true,
                'message' => 'AI Employee is currently disabled for this workspace.',
            ];
        }

        [$lead, $v2Conversation, $classification] = $this->resolveContext(
            $user,
            $outreachLeadId,
            $v2ConversationId,
        );

        if (! $lead) {
            throw new \RuntimeException('Outreach lead not found. Pass outreach_lead_id or conversation_id.');
        }

        $action = trim($action);
        if ($action === '') {
            $action = (string) ($classification['recommended_action'] ?? 'Review lead and decide next step.');
        }

        $reason = trim((string) $reason);
        if ($reason === '' && $classification) {
            $reason = 'Based on intent: '.($classification['intent'] ?? 'unknown')
                .' ('.($classification['priority'] ?? 'needs_judgment').')';
        }

        $prospectName = trim((string) ($lead->full_name ?? 'Prospect'));

        $plan = [
            'type' => 'next_best_action',
            'goal' => 'Set next best action for '.$prospectName,
            'outreach_lead_id' => $lead->id,
            'conversation_id' => $v2Conversation?->id,
            'prospect_name' => $prospectName,
            'next_action' => $action,
            'reason' => $reason !== '' ? $reason : null,
            'classification' => $classification,
            'status' => 'awaiting_review',
        ];

        $autonomy = AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
        $card = $this->commandCenter->formatPlanCard($plan, null, $surface);

        if ($autonomy->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval' => null,
                'approval_id' => null,
                'plan' => $plan,
                'card' => $card,
                'message' => 'Copilot mode: recommendation only.',
            ];
        }

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'set_next_best_action',
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        $card = $this->commandCenter->formatPlanCard($plan, $approval->id, $surface);

        return [
            'approval' => $approval,
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $card,
        ];
    }

    /**
     * @return array{0: V2OutreachLead|null, 1: V2Conversation|null, 2: array<string,mixed>|null}
     */
    private function resolveContext(User $user, ?int $outreachLeadId, ?int $v2ConversationId): array
    {
        $lead = null;
        $v2Conversation = null;
        $classification = null;

        if ($outreachLeadId) {
            $candidate = V2OutreachLead::query()->find($outreachLeadId);
            if ($candidate && $candidate->campaign()->where('user_id', $user->id)->exists()) {
                $lead = $candidate;
            }
        }

        if ($v2ConversationId) {
            $v2Conversation = V2Conversation::query()
                ->where('user_id', $user->id)
                ->whereKey($v2ConversationId)
                ->first();

            if ($v2Conversation) {
                $meta = is_array($v2Conversation->meta) ? $v2Conversation->meta : [];
                if (! $lead) {
                    $leadId = (int) (Arr::get($meta, 'outreach_lead_id') ?? 0);
                    if ($leadId > 0) {
                        $lead = V2OutreachLead::query()->find($leadId);
                    }
                }

                $latestInbound = V2Message::query()
                    ->where('conversation_id', $v2Conversation->id)
                    ->where('direction', 'inbound')
                    ->orderByDesc('received_at')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();

                $body = trim((string) ($latestInbound?->body ?? ''));
                if ($body !== '') {
                    $classification = $this->classifier->classify($body);
                }
            }
        }

        if ($lead && ! $lead->campaign()->where('user_id', $user->id)->exists()) {
            $lead = null;
        }

        return [$lead, $v2Conversation, $classification];
    }
}
