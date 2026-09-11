<?php

namespace App\Jobs\V2;

use App\Models\V2OutreachCampaign;
use App\V2\Ai\Services\CampaignFirstTouchPersonalizationService;
use App\V2\Outreach\OutreachActivityLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PersonalizeCampaignFirstTouchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public readonly int $outreachCampaignId,
        public readonly int $limit = 40,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        CampaignFirstTouchPersonalizationService $personalizer,
        OutreachActivityLogger $logger,
    ): void {
        $campaign = V2OutreachCampaign::query()->find($this->outreachCampaignId);
        if (! $campaign) {
            return;
        }

        $result = $personalizer->personalizeCampaign($campaign, $this->limit);
        if ($result['personalized'] > 0 && V2OutreachCampaign::query()->whereKey($campaign->id)->exists()) {
            try {
                $logger->log(
                    $campaign->id,
                    null,
                    null,
                    null,
                    'info',
                    "Personalized first-touch for {$result['personalized']} lead(s).",
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
