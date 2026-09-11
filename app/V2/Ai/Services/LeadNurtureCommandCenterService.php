<?php

namespace App\V2\Ai\Services;

use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class LeadNurtureCommandCenterService
{
    /**
     * @return array{message:string, outreach_lead_id:int, follow_up_at:string, inbox_url:?string}
     */
    public function moveToNurture(
        User $user,
        ?int $outreachLeadId = null,
        ?int $conversationId = null,
        int $followUpDays = 90,
        ?string $reason = null,
    ): array {
        $lead = $this->resolveLead($user, $outreachLeadId, $conversationId);
        if ($lead === null) {
            throw new \RuntimeException('Outreach lead not found for nurture.');
        }

        $followUpDays = max(7, min(365, $followUpDays));
        $followUpAt = now()->addDays($followUpDays);
        $reason = trim((string) $reason);

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $meta['qualification'] = array_merge($meta['qualification'] ?? [], [
            'stage' => 'nurture',
            'notes' => $reason !== '' ? $reason : 'Moved to nurture by Soci.',
            'nurture_follow_up_at' => $followUpAt->toIso8601String(),
            'nurture_follow_up_days' => $followUpDays,
            'qualified_at' => Carbon::now()->toIso8601String(),
            'source' => 'command_center',
        ]);
        $meta['next_best_action'] = [
            'action' => 'Follow up after nurture period',
            'reason' => $reason !== '' ? $reason : 'Prospect asked to reconnect later.',
            'follow_up_at' => $followUpAt->toIso8601String(),
            'set_at' => Carbon::now()->toIso8601String(),
            'set_by' => 'Soci',
        ];

        $lead->update(['meta' => $meta]);

        $progress = V2OutreachLeadProgress::query()
            ->where('outreach_lead_id', $lead->id)
            ->first();

        if ($progress) {
            $progressMeta = is_array($progress->meta) ? $progress->meta : [];
            $progress->update([
                'next_run_at' => $followUpAt,
                'meta' => array_merge($progressMeta, [
                    'paused_reason' => 'nurture',
                    'paused_at' => now()->toIso8601String(),
                    'nurture_until' => $followUpAt->toIso8601String(),
                ]),
            ]);
        }

        $inboxUrl = null;
        $conversation = $conversationId
            ? V2Conversation::query()->where('user_id', $user->id)->whereKey($conversationId)->first()
            : null;

        if ($conversation) {
            $inboxUrl = url('/inbox/'.$conversation->provider.'/'.$conversation->id);
        }

        return [
            'message' => ($lead->full_name ?? 'Lead')." moved to {$followUpDays}-day nurture (follow up {$followUpAt->format('M j, Y')}).",
            'outreach_lead_id' => $lead->id,
            'follow_up_at' => $followUpAt->toIso8601String(),
            'inbox_url' => $inboxUrl,
        ];
    }

    /**
     * @return array{message:string, outreach_lead_id:int, inbox_url:?string}
     */
    public function resumeFromNurture(User $user, int $outreachLeadId): array
    {
        $lead = V2OutreachLead::query()
            ->with('campaign')
            ->whereKey($outreachLeadId)
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if ($lead === null) {
            throw new \RuntimeException('Outreach lead not found.');
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $stage = (string) Arr::get($meta, 'qualification.stage', '');

        if ($stage !== 'nurture') {
            throw new \RuntimeException('Lead is not in nurture.');
        }

        $meta['qualification'] = array_merge($meta['qualification'] ?? [], [
            'stage' => 'resumed',
            'resumed_at' => Carbon::now()->toIso8601String(),
            'previous_stage' => 'nurture',
        ]);
        $meta['next_best_action'] = [
            'action' => 'Resume outreach sequence',
            'reason' => 'Resumed from nurture queue',
            'set_at' => Carbon::now()->toIso8601String(),
            'set_by' => 'user',
        ];

        $lead->update(['meta' => $meta]);

        $progress = V2OutreachLeadProgress::query()
            ->where('outreach_lead_id', $lead->id)
            ->first();

        if ($progress) {
            $progressMeta = is_array($progress->meta) ? $progress->meta : [];
            unset($progressMeta['paused_reason'], $progressMeta['paused_at'], $progressMeta['nurture_until']);

            $runStatus = (int) $progress->run_status;
            if ($runStatus >= 9) {
                $runStatus = 1;
            }

            $progress->update([
                'next_run_at' => now(),
                'run_status' => $runStatus,
                'meta' => $progressMeta,
            ]);

            $campaign = $lead->campaign;
            if ($campaign && in_array($campaign->status, ['active', 'running'], true)) {
                ProcessOutreachLeadJob::dispatch($campaign->id, $lead->id);
            }
        }

        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forUnifiedInbox()
            ->where('meta->outreach_lead_id', $lead->id)
            ->first();

        return [
            'message' => ($lead->full_name ?? 'Lead').' resumed from nurture — outreach will continue.',
            'outreach_lead_id' => $lead->id,
            'inbox_url' => $conversation
                ? url('/inbox/'.$conversation->provider.'/'.$conversation->id)
                : null,
        ];
    }

    private function resolveLead(User $user, ?int $outreachLeadId, ?int $conversationId): ?V2OutreachLead
    {
        if ($outreachLeadId) {
            $lead = V2OutreachLead::query()
                ->whereKey($outreachLeadId)
                ->whereHas('campaign', fn ($q) => $q->where('user_id', $user->id))
                ->first();
            if ($lead) {
                return $lead;
            }
        }

        if ($conversationId) {
            $conversation = V2Conversation::query()
                ->where('user_id', $user->id)
                ->whereKey($conversationId)
                ->first();

            if ($conversation) {
                $leadId = (int) Arr::get($conversation->meta ?? [], 'outreach_lead_id', 0);
                if ($leadId > 0) {
                    return V2OutreachLead::query()
                        ->whereKey($leadId)
                        ->whereHas('campaign', fn ($q) => $q->where('user_id', $user->id))
                        ->first();
                }
            }
        }

        return null;
    }
}
