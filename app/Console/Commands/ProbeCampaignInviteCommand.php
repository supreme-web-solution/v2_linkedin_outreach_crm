<?php

namespace App\Console\Commands;

use App\Jobs\V2\ProcessCampaignLeadJob;
use App\Models\V2CampaignLead;
use App\Models\V2CampaignLeadProgress;
use App\V2\Campaign\CampaignLeadProfileService;
use App\V2\Campaign\CampaignSequenceResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class ProbeCampaignInviteCommand extends Command
{
    protected $signature = 'campaigns:probe-invite
        {lead_id : v2_campaign_leads.id (e.g. 24 for Joseph)}
        {--apply : If Unipile says connected, mark accepted and continue the sequence}';

    protected $description = 'Probe Unipile for Invite Accepted? on one classic campaign lead';

    public function handle(CampaignLeadProfileService $profiles, CampaignSequenceResolver $resolver): int
    {
        $lead = V2CampaignLead::query()->find((int) $this->argument('lead_id'));
        if (! $lead) {
            $this->error('Lead not found.');

            return self::FAILURE;
        }

        $campaign = $lead->campaign;
        if (! $campaign) {
            $this->error('Campaign missing.');

            return self::FAILURE;
        }

        $this->info("Lead #{$lead->id} {$lead->full_name}");
        $this->line('provider_profile_id: '.(string) $lead->provider_profile_id);
        $this->line('profile_url: '.(string) $lead->profile_url);

        if (! method_exists($profiles, 'checkLiveConnection')) {
            $this->error('CampaignLeadProfileService::checkLiveConnection missing — this release does not have the invite-check fix.');

            return self::FAILURE;
        }

        $live = $profiles->checkLiveConnection($campaign, $lead);

        $this->newLine();
        $this->info('Unipile live check');
        $this->line('connected: '.(($live['connected'] ?? false) ? 'YES' : 'NO'));
        $this->line('network_distance: '.json_encode($live['network_distance'] ?? null));
        $this->line('source: '.json_encode($live['source'] ?? null));
        $this->line('error: '.json_encode($live['error'] ?? null));
        $this->line('is_relationship: '.json_encode(Arr::get($live['profile'] ?? [], 'is_relationship')));
        $this->line('profile.network_distance: '.json_encode(Arr::get($live['profile'] ?? [], 'network_distance')));

        if (! ($live['connected'] ?? false)) {
            $this->warn('Not detected as 1st-degree by Unipile. Not applying.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->comment('Connected. Re-run with --apply to mark accepted and advance.');

            return self::SUCCESS;
        }

        $progress = V2CampaignLeadProgress::query()->firstOrCreate(
            ['campaign_id' => $campaign->id, 'campaign_lead_id' => $lead->id],
            ['current_node_key' => 0, 'next_node_key' => 1, 'run_status' => 0]
        );

        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $nodeKey = (int) ($progress->next_node_key ?: $progress->current_node_key ?: 2);
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

        if ($nextKey !== null) {
            ProcessCampaignLeadJob::dispatch($campaign->id, $lead->id)->delay(now()->addSeconds(2));
        }

        $this->info("Applied. acceptance_status=true, next_node_key=".json_encode($nextKey));

        return self::SUCCESS;
    }
}
