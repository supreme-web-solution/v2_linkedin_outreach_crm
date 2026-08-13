<?php

namespace App\Console\Commands;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\Models\V2OutreachNodeEvent;
use App\V2\Outreach\OutreachConcurrencyLimiter;
use App\V2\Outreach\OutreachSequenceResolver;
use App\V2\Services\UnipileDailyActionLimiter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseOutreachCampaignCommand extends Command
{
    protected $signature = 'outreach:diagnose {campaign_id : Outreach campaign id}';

    protected $description = 'Summarize outreach campaign health: status, stuck leads, quotas, last activity';

    public function handle(
        OutreachSequenceResolver $resolver,
        UnipileDailyActionLimiter $quota,
        OutreachConcurrencyLimiter $concurrency,
    ): int {
        $campaign = V2OutreachCampaign::query()->find((int) $this->argument('campaign_id'));
        if ($campaign === null) {
            $this->error('Campaign not found.');

            return self::FAILURE;
        }

        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $userId = (int) $campaign->user_id;

        $this->info("Campaign #{$campaign->id}: {$campaign->name}");
        $this->line("Status: {$campaign->status}");
        $this->line('User id: '.$userId);

        $lastEvent = V2OutreachNodeEvent::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->latest('id')
            ->first(['executed_at', 'message', 'status']);

        if ($lastEvent) {
            $this->line('Last activity: '.$lastEvent->executed_at?->toDateTimeString().' — ['.$lastEvent->status.'] '.$lastEvent->message);
        } else {
            $this->warn('No activity events logged yet.');
        }

        $this->newLine();
        $this->info('Daily LinkedIn quotas (today)');
        foreach ([UnipileDailyActionLimiter::ACTION_INVITES, UnipileDailyActionLimiter::ACTION_MESSAGES] as $action) {
            $snap = $quota->snapshot($userId, $action);
            $this->line(sprintf(
                '  %s: %d/%d used (%d left)',
                $action,
                $snap['used'],
                $snap['limit'],
                max(0, $snap['limit'] - $snap['used']),
            ));
        }

        $inflight = $concurrency->snapshot($userId);
        $this->line(sprintf(
            'Concurrency: %d/%d in flight (%d slots free)',
            $inflight['in_flight'],
            $inflight['limit'],
            $inflight['available'],
        ));

        $this->newLine();
        $this->info('Lead status counts');
        $byStatus = V2OutreachLead::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        foreach ($byStatus as $status => $total) {
            $this->line("  {$status}: {$total}");
        }

        $now = now();
        $stuckDeferred = V2OutreachLeadProgress::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->where('run_status', '<', 4)
            ->whereHas('lead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
            ->count();

        $orphaned = V2OutreachLeadProgress::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->whereNull('next_run_at')
            ->where('run_status', '<', 4)
            ->where('next_node_key', '>', 0)
            ->whereHas('lead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
            ->count();

        $this->newLine();
        $this->info('Stuck / orphaned progress rows');
        $this->line("  Past next_run_at (should have run): {$stuckDeferred}");
        $this->line("  No next_run_at but not finished: {$orphaned}");

        if (! in_array($campaign->status, ['active', 'running'], true)) {
            $this->newLine();
            $this->warn('Campaign is not active/running — ProcessOutreachLeadJob exits immediately until you Resume.');
        }

        $this->newLine();
        $this->info('Leads by current step (top 10)');
        $progressRows = V2OutreachLeadProgress::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->with('lead:id,full_name,status')
            ->get();

        $byStep = [];
        foreach ($progressRows as $row) {
            $key = (int) $row->next_node_key;
            $node = $resolver->findNodeByKey($nodes, $key);
            $label = $node ? $resolver->nodeLabel($node) : ('node '.$key);
            $byStep[$label] = ($byStep[$label] ?? 0) + 1;
        }
        arsort($byStep);
        foreach (array_slice($byStep, 0, 10, true) as $label => $count) {
            $this->line("  {$label}: {$count}");
        }

        $this->newLine();
        $this->comment('Wake stuck leads: php artisan outreach:dispatch-due --force --limit=200');
        $this->comment('Recover queue: php artisan queue:recover --release-stale --retry-failed');

        return self::SUCCESS;
    }
}
