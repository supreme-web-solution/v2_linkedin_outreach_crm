<?php

namespace App\Jobs\V2;

use App\Models\V2OutreachLead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ProcessNurtureDueLeadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $now = now();

        V2OutreachLead::query()
            ->where('meta->qualification->stage', 'nurture')
            ->whereNotNull('meta->qualification->nurture_follow_up_at')
            ->chunkById(200, function ($leads) use ($now) {
                foreach ($leads as $lead) {
                    $this->flagIfDue($lead, $now);
                }
            });
    }

    private function flagIfDue(V2OutreachLead $lead, Carbon $now): void
    {
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $qualification = is_array($meta['qualification'] ?? null) ? $meta['qualification'] : [];
        $followUpRaw = (string) ($qualification['nurture_follow_up_at'] ?? '');

        if ($followUpRaw === '') {
            return;
        }

        $followUpAt = Carbon::parse($followUpRaw);
        if ($followUpAt->isFuture()) {
            return;
        }

        if (! empty($qualification['nurture_due_flagged_at'])) {
            return;
        }

        $qualification['nurture_due_flagged_at'] = $now->toIso8601String();
        $qualification['nurture_due_status'] = 'due';
        $meta['qualification'] = $qualification;

        $lead->forceFill(['meta' => $meta])->save();
    }
}
