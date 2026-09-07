<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2OutreachLead;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class QualifyLeadCommandCenterService
{
    public function __construct(
        private readonly InboxClassificationService $classifier,
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settingsService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function stage(
        User $user,
        int $organizationId,
        \App\Models\AiConversation $conversation,
        ?int $outreachLeadId,
        ?int $v2ConversationId,
        string $stage,
        ?int $score,
        ?string $notes,
        string $surface = 'web',
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);

        if ($this->settingsService->isBlocked($settings)) {
            return ['blocked' => true, 'message' => 'AI Employee is currently disabled for this workspace.'];
        }

        [$lead, $v2Conversation, $classification] = $this->resolveLead($user, $outreachLeadId, $v2ConversationId);

        if (! $lead) {
            throw new \RuntimeException('Outreach lead not found.');
        }

        $stage = Str::snake(trim($stage));
        $allowed = ['mql', 'sql', 'qualified', 'disqualified', 'nurture', 'meeting_booked'];
        if (! in_array($stage, $allowed, true)) {
            throw new \RuntimeException('stage must be one of: '.implode(', ', $allowed));
        }

        $plan = [
            'type' => 'qualification',
            'goal' => 'Qualify '.($lead->full_name ?? 'lead').' as '.$stage,
            'outreach_lead_id' => $lead->id,
            'conversation_id' => $v2Conversation?->id,
            'prospect_name' => $lead->full_name,
            'stage' => $stage,
            'score' => $score,
            'notes' => trim((string) $notes) !== '' ? trim((string) $notes) : null,
            'evidence' => $classification,
            'status' => 'awaiting_review',
        ];

        return $this->finalizeStage($user, $organizationId, $conversation, $plan, $settings, $surface);
    }

    /**
     * @return array<string, mixed>
     */
    private function finalizeStage(
        User $user,
        int $organizationId,
        \App\Models\AiConversation $conversation,
        array $plan,
        \App\Models\AiEmployeeSetting $settings,
        string $surface,
    ): array {
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
            'qualify_lead',
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        return [
            'approval' => $approval,
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $this->commandCenter->formatPlanCard($plan, $approval->id, $surface),
        ];
    }

    /**
     * @return array{0: V2OutreachLead|null, 1: V2Conversation|null, 2: array<string,mixed>|null}
     */
    private function resolveLead(User $user, ?int $outreachLeadId, ?int $v2ConversationId): array
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
            $v2Conversation = V2Conversation::query()->where('user_id', $user->id)->whereKey($v2ConversationId)->first();
            if ($v2Conversation && ! $lead) {
                $leadId = (int) (Arr::get($v2Conversation->meta ?? [], 'outreach_lead_id') ?? 0);
                if ($leadId > 0) {
                    $lead = V2OutreachLead::query()->find($leadId);
                }
            }

            $latest = V2Message::query()
                ->where('conversation_id', $v2Conversation?->id)
                ->where('direction', 'inbound')
                ->orderByDesc('id')
                ->first();

            if ($latest && trim((string) $latest->body) !== '') {
                $classification = $this->classifier->classify((string) $latest->body);
            }
        }

        return [$lead, $v2Conversation, $classification];
    }
}
