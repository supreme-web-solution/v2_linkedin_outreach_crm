<?php

namespace App\V2\Campaign;

use App\Jobs\V2\ProcessCampaignLeadJob;
use App\Models\V2Campaign;
use App\Models\V2CampaignLead;
use App\Models\V2CampaignLeadProgress;
use App\Models\V2CampaignRun;
use Illuminate\Support\Facades\Log;

class CampaignRunDispatcher
{
    public function __construct(
        private readonly CampaignActivityLogger $logger = new CampaignActivityLogger(),
        private readonly CampaignCompletionService $completion = new CampaignCompletionService(),
    ) {}

    /**
     * Queue execution for all pending/running leads in a campaign.
     *
     * @return array{run_id: int, queued_leads: int, inflight_limit: int, blocked?: bool}
     */
    public function dispatch(V2Campaign $campaign, ?int $organizationId = null, ?CampaignLinkedInGuard $linkedInGuard = null): array
    {
        $linkedInGuard ??= app(CampaignLinkedInGuard::class);
        $inflightLimit = app(CampaignConcurrencyLimiter::class)->maxInFlight();

        if ($linkedInGuard->isUserDisconnected((int) $campaign->user_id)) {
            Log::warning('[Campaign] Dispatch blocked — LinkedIn disconnected', [
                'campaign_id' => $campaign->id,
                'user_id' => $campaign->user_id,
            ]);
            $linkedInGuard->handleDisconnect(
                (int) $campaign->user_id,
                $organizationId ?? $campaign->organization_id,
                'LinkedIn disconnected — campaign dispatch blocked.',
            );

            return [
                'run_id' => 0,
                'queued_leads' => 0,
                'inflight_limit' => $inflightLimit,
                'blocked' => true,
            ];
        }

        $run = V2CampaignRun::query()->create([
            'user_id' => $campaign->user_id,
            'legacy_campaign_id' => $campaign->id,
            'lead_id' => null,
            'status' => 'queued',
            'meta' => array_filter([
                'organization_id' => $organizationId ?? $campaign->organization_id,
                'source' => 'campaign_dispatch',
            ]),
        ]);

        $run->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'current_step_key' => 'dispatch',
        ])->save();

        $campaign->forceFill(['status' => 'running'])->save();

        $this->logger->log(
            $campaign->id,
            null,
            $run->id,
            null,
            'started',
            "Campaign \"{$campaign->name}\" started — queuing leads (up to {$inflightLimit} run at once).",
        );

        $leads = V2CampaignLead::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'running'])
            ->get();

        Log::info('[Campaign] Dispatching campaign run', [
            'campaign_id' => $campaign->id,
            'run_id' => $run->id,
            'lead_count' => $leads->count(),
        ]);

        $queued = 0;

        foreach ($leads as $index => $lead) {
            V2CampaignLeadProgress::query()->firstOrCreate(
                ['campaign_id' => $campaign->id, 'campaign_lead_id' => $lead->id],
                [
                    'current_node_key' => 0,
                    'next_node_key' => 1,
                    'run_status' => 0,
                ]
            );

            ProcessCampaignLeadJob::dispatch($campaign->id, $lead->id, $run->id)
                ->delay(now()->addSeconds($index * max(5, (int) config('services.unipile_pacing.campaign_lead_stagger_seconds', 60))));

            Log::debug('[Campaign] Queued ProcessCampaignLeadJob', [
                'campaign_id' => $campaign->id,
                'lead_id' => $lead->id,
                'delay_seconds' => $index * max(5, (int) config('services.unipile_pacing.campaign_lead_stagger_seconds', 60)),
            ]);

            $queued++;
        }

        if ($queued === 0) {
            Log::warning('[Campaign] No pending leads to queue', ['campaign_id' => $campaign->id]);
            $this->logger->log(
                $campaign->id,
                null,
                $run->id,
                null,
                'info',
                'No pending leads to process.',
            );

            if ($this->completion->maybeFinish($campaign, $run)) {
                Log::info('[Campaign] All leads already finished — campaign completed', [
                    'campaign_id' => $campaign->id,
                ]);
            }
        } else {
            $this->logger->log(
                $campaign->id,
                null,
                $run->id,
                null,
                'info',
                "Queued {$queued} lead(s) for processing — up to {$inflightLimit} run at once.",
            );
        }

        return [
            'run_id' => $run->id,
            'queued_leads' => $queued,
            'inflight_limit' => $inflightLimit,
        ];
    }
}
