<?php

namespace App\V2\Outreach;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachNodeEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Truthful send/outreach metrics from v2_outreach_node_events — not inferred from requests.
 */
class OutreachExecutionMetricsService
{
    /** @var list<string> */
    private const SUCCESS_STATUSES = ['completed', 'sent'];

    /** @var list<string> */
    private const FAILURE_STATUSES = ['failed', 'error'];

    /**
     * @return array{attempted: int, successful: int, failed: int, skipped: int}
     */
    public function forCampaign(V2OutreachCampaign $campaign): array
    {
        $counts = V2OutreachNodeEvent::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $successful = $this->sumStatuses($counts, self::SUCCESS_STATUSES);
        $failed = $this->sumStatuses($counts, self::FAILURE_STATUSES);
        $skipped = (int) ($counts['skipped'] ?? 0);
        $attempted = $successful + $failed;

        return [
            'attempted' => $attempted,
            'successful' => $successful,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{attempted: int, successful: int, failed: int, skipped: int}
     */
    public function forUser(User $user, int $organizationId): array
    {
        $campaignIds = V2OutreachCampaign::query()
            ->where(function (Builder $q) use ($user, $organizationId) {
                if ($organizationId > 0) {
                    $q->where('organization_id', $organizationId);
                } else {
                    $q->where('user_id', $user->id);
                }
            })
            ->pluck('id');

        if ($campaignIds->isEmpty()) {
            return ['attempted' => 0, 'successful' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $counts = V2OutreachNodeEvent::query()
            ->whereIn('outreach_campaign_id', $campaignIds)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $successful = $this->sumStatuses($counts, self::SUCCESS_STATUSES);
        $failed = $this->sumStatuses($counts, self::FAILURE_STATUSES);
        $skipped = (int) ($counts['skipped'] ?? 0);

        return [
            'attempted' => $successful + $failed,
            'successful' => $successful,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, int|string>  $counts
     * @param  list<string>  $statuses
     */
    private function sumStatuses(array $counts, array $statuses): int
    {
        $total = 0;
        foreach ($statuses as $status) {
            $total += (int) ($counts[$status] ?? 0);
        }

        return $total;
    }
}
