<?php

namespace App\V2\Outreach;

use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\Models\V2OutreachRun;
use Illuminate\Support\Facades\Log;

class OutreachRunDispatcher
{
    public function __construct(
        private readonly OutreachActivityLogger $logger,
        private readonly OutreachCompletionService $completion,
        private readonly OutreachChannelGuard $guard,
    ) {}

    /**
     * @return array{run_id: int, queued_leads: int, blocked?: bool, missing_channels?: array<int, string>}
     */
    public function dispatch(V2OutreachCampaign $campaign, ?int $organizationId = null): array
    {
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $missing = $this->guard->missingChannels((int) $campaign->user_id, $nodes);

        if ($missing !== []) {
            Log::warning('[Outreach] Dispatch blocked — channels not connected', [
                'campaign_id' => $campaign->id,
                'missing' => $missing,
            ]);

            return [
                'run_id' => 0,
                'queued_leads' => 0,
                'blocked' => true,
                'missing_channels' => $missing,
            ];
        }

        $run = V2OutreachRun::query()->create([
            'user_id' => $campaign->user_id,
            'outreach_campaign_id' => $campaign->id,
            'status' => 'running',
            'started_at' => now(),
            'meta' => array_filter([
                'organization_id' => $organizationId ?? $campaign->organization_id,
            ]),
        ]);

        $campaign->forceFill(['status' => 'running'])->save();

        $this->logger->log(
            $campaign->id,
            null,
            $run->id,
            null,
            'started',
            "Outreach \"{$campaign->name}\" started — queuing leads.",
        );

        $leads = V2OutreachLead::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'running'])
            ->get();

        $queued = 0;
        foreach ($leads as $index => $lead) {
            V2OutreachLeadProgress::query()->firstOrCreate(
                ['outreach_campaign_id' => $campaign->id, 'outreach_lead_id' => $lead->id],
                ['current_node_key' => 0, 'next_node_key' => 1, 'run_status' => 0, 'channel_state' => []]
            );

            ProcessOutreachLeadJob::dispatch($campaign->id, $lead->id, $run->id)
                ->delay(now()->addSeconds($index * 5));

            $queued++;
        }

        if ($queued === 0) {
            $this->completion->maybeFinish($campaign, $run);
        }

        return ['run_id' => $run->id, 'queued_leads' => $queued];
    }
}
