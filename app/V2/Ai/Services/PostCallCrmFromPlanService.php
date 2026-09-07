<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Lead;
use Illuminate\Support\Carbon;

class PostCallCrmFromPlanService
{
    /**
     * @return array{message:string, call_id:int}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $callId = (int) ($payload['call_id'] ?? 0);
        $outcome = (string) ($payload['outcome'] ?? '');

        $call = V2Call::query()
            ->whereKey($callId)
            ->where('user_id', $user->id)
            ->where('organization_id', (int) $approval->organization_id)
            ->first();

        if (! $call) {
            throw new \RuntimeException("Call #{$callId} not found.");
        }

        $postCall = [
            'outcome' => $outcome,
            'summary' => $payload['summary'] ?? null,
            'next_steps' => $payload['next_steps'] ?? null,
            'crm_notes' => $payload['crm_notes'] ?? null,
            'recorded_at' => Carbon::now()->toIso8601String(),
            'approval_id' => $approval->id,
        ];

        $meta = is_array($call->meta) ? $call->meta : [];
        $meta['post_call_crm'] = $postCall;
        $call->update(['meta' => $meta]);

        if ($call->lead_id) {
            $lead = V2Lead::query()->find($call->lead_id);
            if ($lead) {
                $leadMeta = is_array($lead->meta) ? $lead->meta : [];
                $leadMeta['last_call_outcome'] = $postCall;
                $lead->update(['meta' => $leadMeta]);
            }
        }

        return [
            'message' => 'Post-call CRM update saved for '.($call->prospect_name ?? 'prospect').'.',
            'call_id' => $call->id,
        ];
    }
}
