<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2OutreachLead;
use Illuminate\Support\Carbon;

class PersonalizedMessageFromPlanService
{
    /**
     * @return array{message:string, outreach_lead_id:int, draft_text:string}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $leadId = (int) ($payload['outreach_lead_id'] ?? 0);
        $draft = trim((string) ($payload['draft_text'] ?? ''));

        if ($draft === '') {
            throw new \RuntimeException('Personalized message draft is empty.');
        }

        $lead = V2OutreachLead::query()
            ->whereKey($leadId)
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if (! $lead) {
            throw new \RuntimeException("Outreach lead #{$leadId} not found.");
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $meta['ai_personalized_draft'] = [
            'text' => $draft,
            'evidence' => $payload['evidence'] ?? [],
            'channel' => $payload['channel'] ?? null,
            'saved_at' => Carbon::now()->toIso8601String(),
            'approval_id' => $approval->id,
        ];

        $lead->update(['meta' => $meta]);

        return [
            'message' => 'Personalized message saved on '.$lead->full_name.'. Paste into your outreach step or inbox.',
            'outreach_lead_id' => $lead->id,
            'draft_text' => $draft,
        ];
    }
}
