<?php

namespace App\Jobs\V2;

use App\Models\V2OutreachCampaign;
use App\V2\Ai\Services\CampaignFirstTouchPersonalizationService;
use App\V2\Outreach\OutreachActivityLogger;
use App\V2\Outreach\OutreachLeadSyncService;
use App\V2\Outreach\OutreachRunDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PersonalizeCampaignFirstTouchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public readonly int $outreachCampaignId,
        public readonly int $limit = 40,
        public readonly ?int $organizationId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        CampaignFirstTouchPersonalizationService $personalizer,
        OutreachActivityLogger $logger,
        OutreachRunDispatcher $dispatcher,
        OutreachLeadSyncService $sync,
    ): void {
        $campaign = V2OutreachCampaign::query()->find($this->outreachCampaignId);
        if (! $campaign) {
            return;
        }

        $personalized = 0;
        try {
            $result = $personalizer->personalizeCampaign($campaign, $this->limit);
            $personalized = (int) ($result['personalized'] ?? 0);
        } catch (Throwable $e) {
            report($e);
            if (V2OutreachCampaign::query()->whereKey($campaign->id)->exists()) {
                try {
                    $logger->log(
                        $campaign->id,
                        null,
                        null,
                        null,
                        'info',
                        'First-touch personalization failed — launching with template copy: '.$e->getMessage(),
                    );
                } catch (Throwable $logError) {
                    report($logError);
                }
            }
        }

        if ($personalized > 0 && V2OutreachCampaign::query()->whereKey($campaign->id)->exists()) {
            try {
                $logger->log(
                    $campaign->id,
                    null,
                    null,
                    null,
                    'info',
                    "Personalized first-touch for {$personalized} lead(s).",
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->startRun($campaign, $dispatcher, $sync, $logger);
    }

    public function failed(?Throwable $e): void
    {
        $campaign = V2OutreachCampaign::query()->find($this->outreachCampaignId);
        if (! $campaign || ! in_array($campaign->status, ['preparing', 'running', 'active'], true)) {
            return;
        }

        try {
            app(OutreachActivityLogger::class)->log(
                $campaign->id,
                null,
                null,
                null,
                'info',
                'First-touch personalization exhausted retries — launching with template copy.',
            );
        } catch (Throwable $logError) {
            report($logError);
        }

        $this->startRun(
            $campaign,
            app(OutreachRunDispatcher::class),
            app(OutreachLeadSyncService::class),
            app(OutreachActivityLogger::class),
        );
    }

    private function startRun(
        V2OutreachCampaign $campaign,
        OutreachRunDispatcher $dispatcher,
        OutreachLeadSyncService $sync,
        OutreachActivityLogger $logger,
    ): void {
        $fresh = $campaign->fresh() ?? $campaign;
        if (! in_array($fresh->status, ['preparing', 'running', 'active'], true)) {
            return;
        }

        // Avoid double-start if sync already queued a run (legacy parallel path) or a prior attempt.
        $alreadyStarted = \App\Models\V2OutreachRun::query()
            ->where('outreach_campaign_id', $fresh->id)
            ->where('status', 'running')
            ->exists();
        if ($alreadyStarted) {
            // Wake pending leads in case Process jobs were lost after the run row was created.
            $this->wakePendingLeads($fresh);

            return;
        }

        $result = $dispatcher->dispatch($fresh, $this->organizationId ?? $fresh->organization_id);

        if ($result['blocked'] ?? false) {
            $sync->markSyncFailed($fresh->fresh() ?? $fresh, 'Required channels are not connected.');
            try {
                $logger->log(
                    $fresh->id,
                    null,
                    null,
                    null,
                    'failed',
                    'Outreach blocked — required channels are not connected.',
                );
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function wakePendingLeads(V2OutreachCampaign $campaign): void
    {
        $leads = \App\Models\V2OutreachLead::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'running'])
            ->limit(50)
            ->get(['id']);

        $stagger = max(5, (int) config('services.unipile_pacing.outreach_lead_stagger_seconds', 60));

        foreach ($leads as $index => $lead) {
            $runAt = now()->addSeconds($index * $stagger);
            \App\Models\V2OutreachLeadProgress::query()
                ->where('outreach_campaign_id', $campaign->id)
                ->where('outreach_lead_id', $lead->id)
                ->where(function ($q) {
                    $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
                })
                ->update(['next_run_at' => $runAt]);

            ProcessOutreachLeadJob::dispatch($campaign->id, $lead->id)
                ->delay($runAt);
        }
    }
}
