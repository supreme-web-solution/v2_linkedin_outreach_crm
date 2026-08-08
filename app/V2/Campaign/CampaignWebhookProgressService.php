<?php

namespace App\V2\Campaign;

use App\Jobs\V2\ProcessCampaignLeadJob;
use App\Models\V2Campaign;
use App\Models\V2CampaignLead;
use App\Models\V2CampaignLeadProgress;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class CampaignWebhookProgressService
{
    public function __construct(
        private readonly CampaignActivityLogger $logger = new CampaignActivityLogger(),
        private readonly CampaignSequenceResolver $resolver = new CampaignSequenceResolver(),
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleInvitationAccepted(int $userId, array $payload): void
    {
        $profileId = $this->extractProfileId($payload);
        if ($profileId === '') {
            return;
        }

        $leads = V2CampaignLead::query()
            ->whereHas('campaign', fn ($q) => $q
                ->where('user_id', $userId)
                ->whereIn('status', ['active', 'running']))
            ->whereNotIn('status', ['done', 'skipped'])
            ->where(function ($q) use ($profileId) {
                $q->where('provider_profile_id', $profileId)
                    ->orWhere('profile_url', 'like', '%/'.$profileId.'%')
                    ->orWhere('provider_profile_id', 'like', $profileId);
            })
            ->get();

        foreach ($leads as $lead) {
            $this->markInviteAccepted($lead);
        }
    }

    public function markInviteAccepted(V2CampaignLead $lead): void
    {
        $campaign = $lead->campaign;
        if (! $campaign || ! in_array($campaign->status, ['active', 'running'], true)) {
            return;
        }

        if (in_array($lead->status, ['done', 'skipped'], true)) {
            return;
        }

        $progress = V2CampaignLeadProgress::query()->firstOrCreate(
            ['campaign_id' => $campaign->id, 'campaign_lead_id' => $lead->id],
            [
                'current_node_key' => 0,
                'next_node_key' => 1,
                'run_status' => 0,
            ]
        );

        if ($progress->acceptance_status === true) {
            $this->tryAdvanceWaitingCondition($campaign, $lead, $progress);

            return;
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $meta['network_distance'] = $meta['network_distance'] ?? 'FIRST_DEGREE';
        $meta['invite_accepted_at'] = now()->toIso8601String();
        $lead->forceFill(['meta' => $meta])->save();

        $progress->forceFill([
            'acceptance_status' => true,
            'next_run_at' => null,
            'run_status' => max((int) $progress->run_status, 1),
        ])->save();

        $this->logger->log(
            $campaign->id,
            $lead->id,
            null,
            null,
            'condition_met',
            sprintf('%s accepted your LinkedIn invite.', $lead->full_name ?: 'Lead'),
            ['condition' => 'invite_accepted', 'source' => 'unipile_webhook'],
        );

        Log::info('[Campaign] Invite accepted via webhook', [
            'campaign_id' => $campaign->id,
            'lead_id' => $lead->id,
        ]);

        $this->tryAdvanceWaitingCondition($campaign, $lead->fresh() ?? $lead, $progress->fresh() ?? $progress);
    }

    private function tryAdvanceWaitingCondition(
        V2Campaign $campaign,
        V2CampaignLead $lead,
        V2CampaignLeadProgress $progress,
    ): void {
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $nodeKey = (int) ($progress->next_node_key ?: $progress->current_node_key);
        if ($nodeKey <= 0) {
            return;
        }

        $node = $this->resolver->findNodeByKey($nodes, $nodeKey);
        if (! $node || (string) ($node['type'] ?? '') !== 'condition') {
            return;
        }

        ProcessCampaignLeadJob::dispatch($campaign->id, $lead->id)->delay(now()->addSeconds(2));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractProfileId(array $payload): string
    {
        $inner = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        // Unipile new_relation payload uses user_provider_id / user_public_identifier.
        foreach ([
            'user_provider_id',
            'user_public_identifier',
            'profile_id',
            'invitee_id',
            'provider_id',
            'public_identifier',
            'linkedin_id',
        ] as $key) {
            $value = trim((string) (Arr::get($inner, $key) ?? Arr::get($payload, $key) ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $profile = Arr::get($inner, 'profile');
        if (is_array($profile)) {
            foreach (['provider_id', 'public_identifier', 'id'] as $key) {
                $value = trim((string) ($profile[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}
