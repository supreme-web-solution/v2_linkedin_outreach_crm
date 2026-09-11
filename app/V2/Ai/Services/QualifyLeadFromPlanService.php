<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2OutreachLead;
use Illuminate\Support\Carbon;

class QualifyLeadFromPlanService
{
    /**
     * @return array{message:string, outreach_lead_id:int, stage:string}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $leadId = (int) ($payload['outreach_lead_id'] ?? 0);
        $stage = (string) ($payload['stage'] ?? '');

        $lead = V2OutreachLead::query()
            ->whereKey($leadId)
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if (! $lead) {
            throw new \RuntimeException("Outreach lead #{$leadId} not found.");
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $meta['qualification'] = [
            'stage' => $stage,
            'score' => $payload['score'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'evidence' => $payload['evidence'] ?? null,
            'qualified_at' => Carbon::now()->toIso8601String(),
            'approval_id' => $approval->id,
            'source' => 'command_center',
        ];

        $updates = ['meta' => $meta];
        if ($stage === 'disqualified') {
            $updates['status'] = 'skipped';
        } elseif (in_array($stage, ['sql', 'qualified', 'meeting_booked', 'customer'], true) && $lead->status === 'replied') {
            $updates['status'] = 'replied';
        }

        if ($stage === 'customer') {
            $meta['conversion'] = [
                'stage' => 'customer',
                'converted_at' => Carbon::now()->toIso8601String(),
                'source' => 'command_center',
            ];
            $updates['meta'] = $meta;
        }

        $lead->update($updates);

        return [
            'message' => ($lead->full_name ?? 'Lead')." marked as {$stage}.",
            'outreach_lead_id' => $lead->id,
            'stage' => $stage,
        ];
    }
}
