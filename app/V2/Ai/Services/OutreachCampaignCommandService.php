<?php

namespace App\V2\Ai\Services;

use App\Jobs\V2\SyncOutreachLeadsAndRunJob;
use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\V2\Outreach\OutreachChannelGuard;
use App\V2\Outreach\OutreachLeadSyncService;
use App\V2\Outreach\OutreachRunDispatcher;
use Illuminate\Support\Collection;

class OutreachCampaignCommandService
{
    public function __construct(
        private readonly OutreachChannelGuard $guard,
        private readonly OutreachLeadSyncService $leadSync,
        private readonly OutreachRunDispatcher $dispatcher,
    ) {}

    public function findOwned(User $user, int $organizationId, int $campaignId): ?V2OutreachCampaign
    {
        return V2OutreachCampaign::query()
            ->where('id', $campaignId)
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', '!=', 'template')
            ->first();
    }

    /**
     * @return array{ok:bool, message:string, campaign_id?:int, preparing?:bool}
     */
    public function activate(User $user, int $organizationId, int $campaignId): array
    {
        $campaign = $this->findOwned($user, $organizationId, $campaignId);
        if (! $campaign) {
            return ['ok' => false, 'message' => "Outreach campaign #{$campaignId} not found."];
        }

        $missing = $this->guard->missingChannels($user->id, is_array($campaign->node_model) ? $campaign->node_model : []);
        if ($missing !== []) {
            return [
                'ok' => false,
                'message' => 'Connect '.implode(', ', $missing).' on Integrations before activating.',
            ];
        }

        if ($campaign->outreachLists()->count() === 0) {
            return [
                'ok' => false,
                'message' => "Campaign #{$campaignId} has no lead lists. Attach a list in the outreach builder first.",
            ];
        }

        $this->queueLeadSyncAndRun($campaign, $organizationId);

        return [
            'ok' => true,
            'message' => "Campaign #{$campaignId} is preparing leads — outreach will start automatically when sync finishes.",
            'campaign_id' => $campaign->id,
            'preparing' => true,
        ];
    }

    /**
     * @return array{ok:bool, message:string, paused: list<int>}
     */
    public function pause(User $user, int $organizationId, ?int $campaignId = null): array
    {
        if ($campaignId) {
            $campaign = $this->findOwned($user, $organizationId, $campaignId);
            if (! $campaign) {
                return ['ok' => false, 'message' => "Campaign #{$campaignId} not found.", 'paused' => []];
            }

            if (! in_array($campaign->status, ['active', 'running', 'preparing'], true)) {
                return [
                    'ok' => false,
                    'message' => "Campaign #{$campaignId} is already {$campaign->status}.",
                    'paused' => [],
                ];
            }

            $campaign->update(['status' => 'paused']);

            return [
                'ok' => true,
                'message' => "Paused campaign #{$campaignId}: {$campaign->name}.",
                'paused' => [$campaign->id],
            ];
        }

        $active = V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'running', 'preparing'])
            ->get();

        if ($active->isEmpty()) {
            return ['ok' => true, 'message' => 'No active outreach campaigns to pause.', 'paused' => []];
        }

        $ids = $active->pluck('id')->all();
        V2OutreachCampaign::query()->whereIn('id', $ids)->update(['status' => 'paused']);

        return [
            'ok' => true,
            'message' => 'Paused '.count($ids).' active outreach campaign(s).',
            'paused' => $ids,
        ];
    }

    /**
     * @return Collection<int, V2OutreachCampaign>
     */
    public function activeCampaigns(User $user, int $organizationId): Collection
    {
        return V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', '!=', 'template')
            ->whereIn('status', ['active', 'running', 'preparing', 'draft'])
            ->latest('id')
            ->limit(20)
            ->get();
    }

    private function queueLeadSyncAndRun(V2OutreachCampaign $campaign, int $organizationId): void
    {
        $this->leadSync->markSyncing($campaign);
        SyncOutreachLeadsAndRunJob::dispatch($campaign->id, $organizationId);
    }
}
