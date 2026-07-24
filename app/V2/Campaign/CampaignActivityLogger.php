<?php

namespace App\V2\Campaign;

use App\Models\V2CampaignNodeEvent;

class CampaignActivityLogger
{
    public function __construct(
        private readonly CampaignSequenceResolver $resolver = new CampaignSequenceResolver(),
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(
        int $campaignId,
        ?int $campaignLeadId,
        ?int $campaignRunId,
        ?array $node,
        string $status,
        string $message,
        array $payload = [],
    ): V2CampaignNodeEvent {
        $nodeKey = $node ? (int) ($node['key'] ?? 0) : null;
        $nodeLabel = $node ? $this->resolver->nodeLabel($node) : null;
        $stepType = $node ? $this->resolver->stepType($node) : null;

        return V2CampaignNodeEvent::query()->create([
            'campaign_id' => $campaignId,
            'campaign_lead_id' => $campaignLeadId,
            'campaign_run_id' => $campaignRunId,
            'node_key' => $nodeKey ?: null,
            'node_label' => $nodeLabel,
            'step_type' => $stepType,
            'status' => $status,
            'message' => $message,
            'payload' => $payload ?: null,
            'executed_at' => now(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentForCampaign(
        int $campaignId,
        int $limit = 50,
        ?int $afterId = null,
        ?int $campaignLeadId = null,
    ): array {
        $query = V2CampaignNodeEvent::query()
            ->where('campaign_id', $campaignId)
            ->with(['campaignLead:id,full_name,headline'])
            ->latest('id')
            ->limit($limit);

        if ($campaignLeadId !== null) {
            $query->where('campaign_lead_id', $campaignLeadId);
        }

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        return $query->get()->map(fn (V2CampaignNodeEvent $event) => [
            'id' => $event->id,
            'campaign_lead_id' => $event->campaign_lead_id,
            'lead_name' => $event->campaignLead?->full_name,
            'node_key' => $event->node_key,
            'node_label' => $event->node_label,
            'step_type' => $event->step_type,
            'status' => $event->status,
            'message' => $event->message,
            'payload' => $event->payload,
            'executed_at' => $event->executed_at?->toIso8601String(),
        ])->values()->all();
    }
}
