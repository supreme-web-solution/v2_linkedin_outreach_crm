<?php

namespace App\V2\Outreach;

use App\Models\V2OutreachNodeEvent;

class OutreachActivityLogger
{
    public function __construct(
        private readonly OutreachSequenceResolver $resolver = new OutreachSequenceResolver(),
    ) {}

    /**
     * @param  array<string, mixed>|null  $node
     * @param  array<string, mixed>  $payload
     */
    public function log(
        int $campaignId,
        ?int $leadId,
        ?int $runId,
        ?array $node,
        string $status,
        string $message,
        array $payload = [],
    ): V2OutreachNodeEvent {
        return V2OutreachNodeEvent::query()->create([
            'outreach_campaign_id' => $campaignId,
            'outreach_lead_id' => $leadId,
            'outreach_run_id' => $runId,
            'node_key' => $node ? (int) ($node['key'] ?? 0) : null,
            'channel' => $node['channel'] ?? null,
            'action' => $node['action'] ?? ($node ? $this->resolver->stepType($node) : null),
            'status' => $status,
            'message' => $message,
            'payload' => $payload ?: null,
            'executed_at' => now(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentForCampaign(int $campaignId, int $limit = 50, ?int $afterId = null, ?int $leadId = null): array
    {
        $query = V2OutreachNodeEvent::query()
            ->where('outreach_campaign_id', $campaignId)
            ->with(['lead:id,full_name,headline'])
            ->latest('id')
            ->limit($limit);

        if ($leadId !== null) {
            $query->where('outreach_lead_id', $leadId);
        }

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        return $query->get()->map(fn (V2OutreachNodeEvent $event) => [
            'id' => $event->id,
            'outreach_lead_id' => $event->outreach_lead_id,
            'lead_name' => $event->lead?->full_name,
            'node_key' => $event->node_key,
            'channel' => $event->channel,
            'action' => $event->action,
            'status' => $event->status,
            'message' => $event->message,
            'payload' => $event->payload,
            'executed_at' => $event->executed_at?->toIso8601String(),
        ])->values()->all();
    }
}
