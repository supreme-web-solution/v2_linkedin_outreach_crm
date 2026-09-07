<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\V2\Services\OutreachChannelInboxSettingsService;

class CampaignInboxAiFromPlanService
{
    /**
     * @return array{message:string, campaign_url:string}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $campaignId = (int) ($payload['campaign_id'] ?? 0);
        $channel = trim((string) ($payload['channel'] ?? ''));

        if ($campaignId <= 0 || $channel === '') {
            throw new \InvalidArgumentException('Missing campaign or channel in inbox AI plan.');
        }

        $campaign = V2OutreachCampaign::query()
            ->where('id', $campaignId)
            ->where('user_id', $user->id)
            ->first();

        if (! $campaign) {
            throw new \InvalidArgumentException('Campaign not found.');
        }

        $inboxSettings = app(OutreachChannelInboxSettingsService::class);
        $inboxSettings->saveCampaignChannel($campaign, $channel, [
            'ai_context' => (string) ($payload['ai_context'] ?? ''),
            'auto_reply_enabled' => (bool) ($payload['auto_reply_enabled'] ?? false),
            'pause_on_reply' => (bool) ($payload['pause_on_reply'] ?? true),
        ]);

        $auto = ($payload['auto_reply_enabled'] ?? false) ? 'on' : 'off';

        return [
            'message' => "Saved inbox AI for {$campaign->name} ({$channel}): auto-reply {$auto}.",
            'campaign_url' => url('/outreach/'.$campaign->id),
        ];
    }
}
