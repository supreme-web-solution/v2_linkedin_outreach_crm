<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2OutreachLead;
use Illuminate\Support\Carbon;

class NextBestActionFromPlanService
{
    /**
     * @return array{message:string, outreach_lead_id:int}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $leadId = (int) ($payload['outreach_lead_id'] ?? 0);
        $action = trim((string) ($payload['next_action'] ?? ''));

        if ($action === '') {
            throw new \RuntimeException('Next action text is empty.');
        }

        $lead = V2OutreachLead::query()
            ->whereKey($leadId)
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if (! $lead) {
            throw new \RuntimeException("Outreach lead #{$leadId} not found.");
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $meta['next_best_action'] = [
            'action' => $action,
            'reason' => $payload['reason'] ?? null,
            'classification' => $payload['classification'] ?? null,
            'set_at' => Carbon::now()->toIso8601String(),
            'approval_id' => $approval->id,
            'source' => 'command_center',
        ];

        $lead->update(['meta' => $meta]);

        return [
            'message' => 'Next best action saved on '.$lead->full_name.'.',
            'outreach_lead_id' => $lead->id,
        ];
    }
}
