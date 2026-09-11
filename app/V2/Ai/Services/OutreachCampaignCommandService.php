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
     * @param  list<int>  $campaignIds
     * @return array{ok:bool, message:string, activated:list<int>, failed:list<string>}
     */
    public function activateMany(User $user, int $organizationId, array $campaignIds): array
    {
        $activated = [];
        $failed = [];
        foreach (array_unique(array_map('intval', $campaignIds)) as $id) {
            if ($id <= 0) {
                continue;
            }
            $result = $this->activate($user, $organizationId, $id);
            if ($result['ok'] ?? false) {
                $activated[] = $id;
            } else {
                $failed[] = '#'.$id.': '.($result['message'] ?? 'failed');
            }
        }

        return [
            'ok' => $activated !== [],
            'message' => ($activated !== [] ? 'Activated '.count($activated).' campaign(s).' : 'No campaigns activated.')
                .($failed !== [] ? ' Failed: '.implode('; ', $failed) : ''),
            'activated' => $activated,
            'failed' => $failed,
        ];
    }

    /**
     * @param  list<int>  $campaignIds
     * @return array{ok:bool, message:string, paused:list<int>, failed:list<string>}
     */
    public function pauseMany(User $user, int $organizationId, array $campaignIds): array
    {
        $paused = [];
        $failed = [];
        foreach (array_unique(array_map('intval', $campaignIds)) as $id) {
            if ($id <= 0) {
                continue;
            }
            $result = $this->pause($user, $organizationId, $id);
            if ($result['ok'] ?? false) {
                $paused = array_merge($paused, $result['paused'] ?? [$id]);
            } else {
                $failed[] = '#'.$id.': '.($result['message'] ?? 'failed');
            }
        }

        return [
            'ok' => $paused !== [],
            'message' => ($paused !== [] ? 'Paused '.count($paused).' campaign(s).' : 'No campaigns paused.')
                .($failed !== [] ? ' Failed: '.implode('; ', $failed) : ''),
            'paused' => $paused,
            'failed' => $failed,
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

            $previous = (string) $campaign->status;
            $campaign->update(['status' => 'paused']);

            return [
                'ok' => true,
                'message' => "Paused campaign #{$campaignId}: {$campaign->name}.",
                'paused' => [$campaign->id],
                'previous_statuses' => [$campaign->id => $previous],
            ];
        }

        $active = V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'running', 'preparing'])
            ->get();

        if ($active->isEmpty()) {
            return [
                'ok' => true,
                'message' => 'No active outreach campaigns to pause.',
                'paused' => [],
                'previous_statuses' => [],
            ];
        }

        $previousStatuses = $active->mapWithKeys(
            fn (V2OutreachCampaign $c) => [$c->id => (string) $c->status]
        )->all();
        $ids = $active->pluck('id')->all();
        V2OutreachCampaign::query()->whereIn('id', $ids)->update(['status' => 'paused']);

        return [
            'ok' => true,
            'message' => 'Paused '.count($ids).' active outreach campaign(s).',
            'paused' => $ids,
            'previous_statuses' => $previousStatuses,
        ];
    }

    /**
     * Resume paused campaigns (used by Soci action undo).
     *
     * @param  list<int>  $campaignIds
     * @param  array<int|string, string>  $previousStatuses
     * @return array{ok:bool, message:string, resumed: list<int>}
     */
    public function resumePaused(User $user, int $organizationId, array $campaignIds, array $previousStatuses = []): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $campaignIds),
            fn (int $id) => $id > 0,
        )));

        if ($ids === []) {
            return ['ok' => false, 'message' => 'No campaigns to resume.', 'resumed' => []];
        }

        $campaigns = V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->get();

        $resumed = [];
        foreach ($campaigns as $campaign) {
            if ($campaign->status !== 'paused') {
                continue;
            }

            $restore = (string) ($previousStatuses[$campaign->id] ?? $previousStatuses[(string) $campaign->id] ?? 'active');
            if (! in_array($restore, ['active', 'running', 'preparing'], true)) {
                $restore = 'active';
            }

            $campaign->update(['status' => $restore]);
            $resumed[] = $campaign->id;
        }

        if ($resumed === []) {
            return ['ok' => false, 'message' => 'No paused campaigns were resumed.', 'resumed' => []];
        }

        return [
            'ok' => true,
            'message' => 'Resumed '.count($resumed).' campaign(s).',
            'resumed' => $resumed,
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
