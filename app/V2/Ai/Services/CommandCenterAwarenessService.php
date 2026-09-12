<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiWorkflowRun;
use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Live workspace snapshot injected into every Command Center turn so Soci
 * knows hot inbox threads, campaigns, and pending actions — not only unread chats.
 */
class CommandCenterAwarenessService
{
    public function __construct(
        private readonly AttentionQueueService $attention,
        private readonly NurtureQueueService $nurture,
        private readonly AiEmployeeSettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $user, int $organizationId): array
    {
        $attention = $this->attention->forUser($user, $organizationId, 8);
        $items = [];
        foreach ($attention['items'] ?? [] as $row) {
            $items[] = $this->enrichAttentionItem($row);
        }

        $pending = AiActionApproval::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (AiActionApproval $approval) => [
                'approval_id' => $approval->id,
                'tool' => $approval->tool,
                'type' => (string) (is_array($approval->payload) ? ($approval->payload['type'] ?? $approval->tool) : $approval->tool),
                'goal' => Str::limit((string) (is_array($approval->payload) ? ($approval->payload['goal'] ?? '') : ''), 120),
            ])
            ->all();

        $workflows = AiWorkflowRun::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['running', 'waiting'])
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(fn (AiWorkflowRun $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'outcome' => (string) (is_array($run->plan) ? ($run->plan['required_outcome'] ?? '') : ''),
            ])
            ->all();

        $settings = $this->settings->for($user, $organizationId);
        $autonomy = $this->settings->autonomy($settings)->label();

        return [
            'autonomy' => $autonomy,
            'inbox_brief' => $attention['inbox_brief'] ?? [],
            'awaiting_reply' => $items,
            'pending_approvals' => $pending,
            'workflows' => $workflows,
            'nurture' => $this->nurture->briefForUser($user),
        ];
    }

    public function promptBlock(User $user, int $organizationId): string
    {
        $snap = $this->snapshot($user, $organizationId);
        $lines = [
            '[Live workspace awareness — factual DB snapshot for this org. Use these facts; do not invent counts.',
            'Opening or reading an inbox thread does NOT remove it from attention while the prospect message is still latest.',
            'Propose draft_reply / LAUNCH / send_inbox_reply for hot items; execute only within autonomy policy.]',
            'Autonomy: '.($snap['autonomy'] ?? 'Assisted'),
        ];

        $brief = is_array($snap['inbox_brief'] ?? null) ? $snap['inbox_brief'] : [];
        $awaiting = (int) ($brief['need_you'] ?? 0);
        $hot = (int) ($brief['hot'] ?? 0);
        $aiHandled = (int) ($brief['ai_handled_estimate'] ?? 0);
        if ($awaiting > 0 || $hot > 0) {
            $lines[] = "Inbox: {$awaiting} need you ({$hot} hot). Soci handled ~{$aiHandled} recently.";
        } else {
            $lines[] = 'Inbox: no hot threads awaiting your reply right now.';
        }

        foreach ($snap['awaiting_reply'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $priority = strtoupper((string) ($row['priority'] ?? 'review'));
            $name = (string) ($row['prospect_name'] ?? 'Prospect');
            $email = trim((string) ($row['prospect_email'] ?? ''));
            $channel = (string) ($row['channel_label'] ?? $row['channel'] ?? 'inbox');
            $convId = (int) ($row['conversation_id'] ?? 0);
            $campaign = trim((string) ($row['campaign_name'] ?? ''));
            $stage = trim((string) ($row['conversion_stage'] ?? ''));
            $preview = Str::limit((string) ($row['preview'] ?? ''), 100);
            $readNote = ($row['is_unread'] ?? true) ? '' : ' [read but still awaiting your reply]';

            $identity = $email !== '' ? "{$name} <{$email}>" : $name;
            $tail = array_filter([
                $campaign !== '' ? "campaign \"{$campaign}\"" : null,
                $stage !== '' ? "stage {$stage}" : null,
                "conv #{$convId}",
            ]);

            $lines[] = "- {$priority} {$channel}: {$identity}{$readNote}"
                .($tail !== [] ? ' · '.implode(' · ', $tail) : '')
                .($preview !== '' ? " — \"{$preview}\"" : '');
        }

        $pending = $snap['pending_approvals'] ?? [];
        if ($pending !== []) {
            $lines[] = 'Pending Review & Launch:';
            foreach ($pending as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lines[] = '- Launch #'.($row['approval_id'] ?? '?').' '
                    .($row['type'] ?? 'plan')
                    .((($row['goal'] ?? '') !== '') ? ': '.$row['goal'] : '');
            }
        }

        foreach ($snap['workflows'] ?? [] as $run) {
            if (! is_array($run)) {
                continue;
            }
            $lines[] = 'Background workflow #'.($run['id'] ?? '?').' '
                .($run['status'] ?? 'running')
                .((($run['outcome'] ?? '') !== '') ? ' ('.$run['outcome'].')' : '');
        }

        $nurture = is_array($snap['nurture'] ?? null) ? $snap['nurture'] : [];
        if ((int) ($nurture['due_this_week'] ?? 0) > 0 || (int) ($nurture['overdue'] ?? 0) > 0) {
            $lines[] = 'Nurture: '.(int) ($nurture['due_this_week'] ?? 0).' due this week, '
                .(int) ($nurture['overdue'] ?? 0).' overdue.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function enrichAttentionItem(array $row): array
    {
        $convId = (int) ($row['conversation_id'] ?? 0);
        $campaignId = 0;
        $campaignName = '';
        $stage = '';
        $email = trim((string) ($row['prospect_email'] ?? ''));

        $conversation = $convId > 0
            ? \App\Models\V2Conversation::query()->find($convId)
            : null;

        if ($conversation) {
            $meta = is_array($conversation->meta) ? $conversation->meta : [];
            $campaignId = (int) (Arr::get($meta, 'outreach_campaign_id') ?? 0);
            $leadId = (int) (Arr::get($meta, 'outreach_lead_id') ?? 0);

            if ($leadId > 0) {
                $lead = V2OutreachLead::query()->find($leadId);
                if ($lead) {
                    if ($email === '') {
                        $email = trim((string) ($lead->email ?? ''));
                    }
                    $dossier = app(ProspectMemoryService::class)->dossier($lead);
                    $stage = trim((string) ($dossier['conversion_stage'] ?? ''));
                }
            }
        }

        if ($campaignId > 0) {
            $campaign = V2OutreachCampaign::query()->find($campaignId);
            $campaignName = trim((string) ($campaign?->name ?? ''));
        }

        return array_merge($row, array_filter([
            'prospect_email' => $email !== '' ? $email : null,
            'campaign_id' => $campaignId > 0 ? $campaignId : null,
            'campaign_name' => $campaignName !== '' ? $campaignName : null,
            'conversion_stage' => $stage !== '' ? $stage : null,
        ], fn ($v) => $v !== null));
    }
}
