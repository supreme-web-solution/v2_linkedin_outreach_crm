<?php

namespace App\Console\Commands;

use App\Jobs\V2\ProcessCampaignLeadJob;
use App\Models\V2CampaignLeadProgress;
use App\V2\Campaign\CampaignActivityLogger;
use App\V2\Campaign\CampaignLeadProfileService;
use App\V2\Campaign\CampaignSequenceResolver;
use Illuminate\Console\Command;

/**
 * App-wide Invite Accepted? recheck for classic campaigns (not one-off Joseph).
 */
class RecheckCampaignInvitesCommand extends Command
{
    protected $signature = 'campaigns:recheck-invites
        {--campaign= : Limit to one campaign id}
        {--limit=100 : Max leads to check}
        {--apply : Mark accepted + advance when Unipile reports 1st-degree}
        {--queue : After apply, also re-queue leads still waiting}';

    protected $description = 'Recheck classic-campaign Invite Accepted? waits via Unipile for all matching leads';

    public function handle(
        CampaignLeadProfileService $profiles,
        CampaignSequenceResolver $resolver,
        CampaignActivityLogger $logger,
    ): int {
        $limit = max(1, min((int) $this->option('limit'), 500));
        $apply = (bool) $this->option('apply');
        $queue = (bool) $this->option('queue');
        $campaignId = $this->option('campaign');

        $query = V2CampaignLeadProgress::query()
            ->with(['campaignLead', 'campaign'])
            ->whereNull('acceptance_status')
            ->where('run_status', '>=', 1)
            ->where('run_status', '<', 4)
            ->whereHas('campaign', fn ($q) => $q->whereIn('status', ['active', 'running']))
            ->whereHas('campaignLead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
            ->orderBy('id')
            ->limit($limit);

        if ($campaignId !== null && $campaignId !== '') {
            $query->where('campaign_id', (int) $campaignId);
        }

        $rows = $query->get();
        $this->info("Checking {$rows->count()} waiting lead(s)...");

        $connected = 0;
        $stillWaiting = 0;
        $errors = 0;
        $stagger = 0;

        foreach ($rows as $progress) {
            $lead = $progress->campaignLead;
            $campaign = $progress->campaign;
            if (! $lead || ! $campaign) {
                continue;
            }

            try {
                $live = $profiles->checkLiveConnection($campaign, $lead);
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("#{$lead->id} {$lead->full_name}: error {$e->getMessage()}");

                continue;
            }

            $distance = $live['network_distance'] ?? null;
            $isConnected = (bool) ($live['connected'] ?? false);

            if ($isConnected) {
                $connected++;
                $this->line("<info>YES</info> #{$lead->id} {$lead->full_name} — {$distance} ({$live['source']})");

                if ($apply) {
                    $this->applyAccepted($campaign, $lead, $progress, $resolver, $logger, $live, $stagger);
                    $stagger++;
                }
            } else {
                $stillWaiting++;
                $err = $live['error'] ?? null;
                $this->line("<comment>NO</comment>  #{$lead->id} {$lead->full_name} — ".json_encode($distance).($err ? " [{$err}]" : ''));

                if ($queue) {
                    $progress->forceFill(['next_run_at' => null])->save();
                    ProcessCampaignLeadJob::dispatch((int) $campaign->id, (int) $lead->id)
                        ->delay(now()->addSeconds($stagger * 45));
                    $stagger++;
                }
            }

            // Gentle pacing between Unipile profile lookups.
            usleep(400000);
        }

        $this->newLine();
        $this->info("Connected: {$connected}. Still waiting: {$stillWaiting}. Errors: {$errors}.");
        if ($connected > 0 && ! $apply) {
            $this->comment('Re-run with --apply to mark accepted and continue their sequences.');
        }
        $this->comment('After every deploy: php artisan horizon:terminate  (workers must reload new code)');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $live
     */
    private function applyAccepted(
        $campaign,
        $lead,
        V2CampaignLeadProgress $progress,
        CampaignSequenceResolver $resolver,
        CampaignActivityLogger $logger,
        array $live,
        int $staggerIndex,
    ): void {
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $nodeKey = (int) ($progress->next_node_key ?: $progress->current_node_key ?: 2);
        $node = $resolver->findNodeByKey($nodes, $nodeKey);
        $nextKey = $resolver->resolveNextNodeKey($nodes, $nodeKey, true);

        $completed = is_array($progress->completed_keys) ? $progress->completed_keys : [];
        $completed[] = $nodeKey;

        $progress->forceFill([
            'acceptance_status' => true,
            'current_node_key' => $nodeKey,
            'next_node_key' => $nextKey,
            'completed_keys' => array_values(array_unique($completed)),
            'next_run_at' => null,
            'run_status' => 2,
        ])->save();

        $logger->log(
            (int) $campaign->id,
            (int) $lead->id,
            null,
            is_array($node) ? $node : null,
            'condition_met',
            "Detected 1st-degree connection for {$lead->full_name} — invite accepted.",
            [
                'network_distance' => $live['network_distance'] ?? null,
                'source' => $live['source'] ?? 'recheck_invites',
            ],
        );

        if ($nextKey !== null) {
            ProcessCampaignLeadJob::dispatch((int) $campaign->id, (int) $lead->id)
                ->delay(now()->addSeconds(2 + ($staggerIndex * 5)));
        }
    }
}
