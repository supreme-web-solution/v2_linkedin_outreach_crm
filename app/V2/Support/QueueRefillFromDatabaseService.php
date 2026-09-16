<?php

namespace App\V2\Support;

use App\Jobs\V2\ContinueWorkflowRunJob;
use App\Jobs\V2\PublishV2ContentPostJob;
use App\Jobs\V2\SyncCampaignLeadsAndRunJob;
use App\Jobs\V2\SyncOutreachLeadsAndRunJob;
use App\Models\AiWorkflowRun;
use App\Models\V2Campaign;
use App\Models\V2ContentPost;
use App\Models\V2OutreachCampaign;
use App\V2\Campaign\CampaignConcurrencyLimiter;
use App\V2\Campaign\CampaignDueLeadDispatcher;
use App\V2\Outreach\OutreachConcurrencyLimiter;
use App\V2\Outreach\OutreachDueLeadDispatcher;
use App\V2\Services\CallOrchestrationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Re-hydrate Redis queue work from durable MySQL state.
 *
 * Redis / Horizon remain the fast execution path. After horizon:terminate, Redis flush,
 * or worker loss, this refill reads schedules (next_run_at, scheduled_at, preparing, …)
 * and pushes jobs back onto Redis so work is not permanently stranded.
 */
class QueueRefillFromDatabaseService
{
    /** @var list<string> */
    private const WORKFLOW_TERMINAL = ['completed', 'failed', 'blocked', 'cancelled'];

    /**
     * @return array<string, int|bool>
     */
    public function refill(int $limit = 100, bool $force = false): array
    {
        $limit = max(1, min($limit, 500));

        $outreach = app(OutreachDueLeadDispatcher::class)->dispatchDue($limit, $force);
        $campaigns = app(CampaignDueLeadDispatcher::class)->dispatchDue($limit, $force);
        $calls = app(CallOrchestrationService::class)->dispatchDue();
        $posts = $this->refillScheduledContentPosts($limit);
        $workflows = $this->refillActiveWorkflows($limit);
        $preparingOutreach = $this->refillPreparingOutreach($limit);
        $preparingCampaigns = $this->refillPreparingCampaigns($limit);
        $leases = $this->recoverConcurrencyLeases();

        $summary = [
            'outreach_leads' => (int) ($outreach['dispatched'] ?? 0),
            'campaign_leads' => (int) ($campaigns['dispatched'] ?? 0),
            'call_messages' => (int) ($calls['messages_sent'] ?? 0),
            'call_reminders' => (int) ($calls['reminders_sent'] ?? 0),
            'content_posts' => $posts,
            'workflows' => $workflows,
            'preparing_outreach' => $preparingOutreach,
            'preparing_campaigns' => $preparingCampaigns,
            'leases_freed' => $leases,
            'force' => $force,
        ];

        if (array_sum(array_filter($summary, 'is_int')) > 0) {
            Log::info('[Queue] Refilled jobs from database', $summary);
        }

        return $summary;
    }

    private function refillScheduledContentPosts(int $limit): int
    {
        $posts = V2ContentPost::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get(['id']);

        $dispatched = 0;
        foreach ($posts as $post) {
            $lockKey = 'queue:refill:content-post:'.$post->id;
            if (! Cache::add($lockKey, 1, now()->addMinutes(10))) {
                continue;
            }
            PublishV2ContentPostJob::dispatch((int) $post->id);
            $dispatched++;
        }

        return $dispatched;
    }

    private function refillActiveWorkflows(int $limit): int
    {
        $runs = AiWorkflowRun::query()
            ->whereNotIn('status', self::WORKFLOW_TERMINAL)
            ->where('status', '!=', 'waiting')
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get(['id']);

        $dispatched = 0;
        foreach ($runs as $run) {
            $lockKey = 'queue:refill:workflow:'.$run->id;
            if (! Cache::add($lockKey, 1, now()->addMinutes(5))) {
                continue;
            }
            ContinueWorkflowRunJob::dispatch((int) $run->id);
            $dispatched++;
        }

        return $dispatched;
    }

    private function refillPreparingOutreach(int $limit): int
    {
        $campaigns = V2OutreachCampaign::query()
            ->where('status', 'preparing')
            ->where('updated_at', '<=', now()->subMinutes(3))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get(['id', 'organization_id']);

        $dispatched = 0;
        foreach ($campaigns as $campaign) {
            $lockKey = 'queue:refill:outreach-sync:'.$campaign->id;
            if (! Cache::add($lockKey, 1, now()->addMinutes(10))) {
                continue;
            }
            SyncOutreachLeadsAndRunJob::dispatch(
                (int) $campaign->id,
                $campaign->organization_id ? (int) $campaign->organization_id : null,
            );
            $dispatched++;
        }

        return $dispatched;
    }

    private function refillPreparingCampaigns(int $limit): int
    {
        $campaigns = V2Campaign::query()
            ->where('status', 'preparing')
            ->where('updated_at', '<=', now()->subMinutes(3))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get(['id', 'organization_id']);

        $dispatched = 0;
        foreach ($campaigns as $campaign) {
            $lockKey = 'queue:refill:campaign-sync:'.$campaign->id;
            if (! Cache::add($lockKey, 1, now()->addMinutes(10))) {
                continue;
            }
            SyncCampaignLeadsAndRunJob::dispatch(
                (int) $campaign->id,
                $campaign->organization_id ? (int) $campaign->organization_id : null,
            );
            $dispatched++;
        }

        return $dispatched;
    }

    private function recoverConcurrencyLeases(): int
    {
        $outreach = app(OutreachConcurrencyLimiter::class)->recoverAll();
        $campaign = app(CampaignConcurrencyLimiter::class)->recoverAll();

        return (int) ($outreach['leases_freed'] ?? 0) + (int) ($campaign['leases_freed'] ?? 0);
    }
}
