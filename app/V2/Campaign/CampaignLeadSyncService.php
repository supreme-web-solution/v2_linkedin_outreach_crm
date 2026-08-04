<?php

namespace App\V2\Campaign;

use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\V2Campaign;
use App\Models\V2CampaignLead;
use App\Models\V2CampaignLeadProgress;
use App\Models\V2CampaignList;

/**
 * Chunked copy of list members → classic campaign leads.
 */
class CampaignLeadSyncService
{
    public const CHUNK_SIZE = 250;

    public function syncAllLists(V2Campaign $campaign): int
    {
        $added = 0;
        $listIndex = 0;
        $afterId = 0;

        while (true) {
            $chunk = $this->syncNextChunk($campaign, $listIndex, $afterId);
            $added += $chunk['added'];
            if ($chunk['done']) {
                break;
            }
            $listIndex = $chunk['list_index'];
            $afterId = $chunk['after_id'];
        }

        return $added;
    }

    /**
     * @return array{done: bool, list_index: int, after_id: int, added: int}
     */
    public function syncNextChunk(V2Campaign $campaign, int $listIndex = 0, int $afterId = 0): array
    {
        $lists = $campaign->campaignLists()->orderBy('id')->get();
        if ($lists->isEmpty() || $listIndex >= $lists->count()) {
            return ['done' => true, 'list_index' => $listIndex, 'after_id' => 0, 'added' => 0];
        }

        /** @var V2CampaignList $list */
        $list = $lists[$listIndex];
        $result = match ($list->list_src) {
            'aud' => $this->syncAudienceChunk($campaign, $list, $afterId),
            'sn' => $this->syncSnChunk($campaign, $list, $afterId),
            default => ['added' => 0, 'after_id' => 0, 'exhausted' => true],
        };

        if ($result['exhausted']) {
            $nextIndex = $listIndex + 1;
            if ($nextIndex >= $lists->count()) {
                return ['done' => true, 'list_index' => $nextIndex, 'after_id' => 0, 'added' => $result['added']];
            }

            return [
                'done' => false,
                'list_index' => $nextIndex,
                'after_id' => 0,
                'added' => $result['added'],
            ];
        }

        return [
            'done' => false,
            'list_index' => $listIndex,
            'after_id' => $result['after_id'],
            'added' => $result['added'],
        ];
    }

    public function initProgress(V2Campaign $campaign): void
    {
        $campaign->campaignLeads()->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($leads) use ($campaign) {
            foreach ($leads as $lead) {
                $this->ensureProgress($campaign, $lead);
            }
        });
    }

    private function ensureProgress(V2Campaign $campaign, V2CampaignLead $lead): void
    {
        V2CampaignLeadProgress::firstOrCreate(
            ['campaign_id' => $campaign->id, 'campaign_lead_id' => $lead->id],
            [
                'current_node_key' => 0,
                'next_node_key' => 1,
                'run_status' => 0,
            ]
        );
    }

    public function markSyncing(V2Campaign $campaign): void
    {
        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $meta['lead_sync'] = [
            'status' => 'syncing',
            'started_at' => now()->toIso8601String(),
            'message' => 'Preparing leads from your lists…',
        ];
        $campaign->forceFill([
            'status' => 'preparing',
            'meta' => $meta,
        ])->save();
    }

    public function markSyncComplete(V2Campaign $campaign): void
    {
        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        unset($meta['lead_sync']);
        $campaign->forceFill([
            'status' => 'running',
            'meta' => $meta,
        ])->save();
    }

    public function markSyncFailed(V2Campaign $campaign, string $message): void
    {
        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $meta['lead_sync'] = [
            'status' => 'failed',
            'message' => $message,
            'failed_at' => now()->toIso8601String(),
        ];
        $campaign->forceFill([
            'status' => 'paused',
            'meta' => $meta,
        ])->save();
    }

    /**
     * @return array{added: int, after_id: int, exhausted: bool}
     */
    private function syncAudienceChunk(V2Campaign $campaign, V2CampaignList $list, int $afterId): array
    {
        $rows = AudienceList::query()
            ->where('audience_id', $list->list_hash)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE)
            ->get();

        if ($rows->isEmpty()) {
            return ['added' => 0, 'after_id' => 0, 'exhausted' => true];
        }

        $added = 0;
        $lastId = $afterId;
        foreach ($rows as $row) {
            $lastId = (int) $row->id;
            $name = trim(($row->con_first_name ?? '').' '.($row->con_last_name ?? ''));
            $profileId = $row->con_public_identifier ?: $row->con_id;
            $profileUrl = $row->con_public_identifier
                ? 'https://www.linkedin.com/in/'.$row->con_public_identifier
                : $row->con_profile_url;

            $lead = V2CampaignLead::firstOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'source_list_src' => 'aud',
                    'source_record_id' => $row->id,
                ],
                [
                    'provider_profile_id' => $profileId,
                    'full_name' => $name !== '' ? $name : 'Unknown',
                    'headline' => $row->con_job_title,
                    'profile_url' => $profileUrl,
                    'status' => 'pending',
                    'meta' => [
                        'list_hash' => $list->list_hash,
                        'member_urn' => $row->con_member_urn,
                        'tracking_id' => $row->con_tracking_id,
                        'network_distance' => $row->con_distance,
                    ],
                ]
            );

            $this->ensureProgress($campaign, $lead);

            if ($lead->wasRecentlyCreated) {
                $added++;
            }
        }

        return [
            'added' => $added,
            'after_id' => $lastId,
            'exhausted' => $rows->count() < self::CHUNK_SIZE,
        ];
    }

    /**
     * @return array{added: int, after_id: int, exhausted: bool}
     */
    private function syncSnChunk(V2Campaign $campaign, V2CampaignList $list, int $afterId): array
    {
        $rows = SnLead::query()
            ->where('sn_list_id', $list->list_hash)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE)
            ->get();

        if ($rows->isEmpty()) {
            return ['added' => 0, 'after_id' => 0, 'exhausted' => true];
        }

        $added = 0;
        $lastId = $afterId;
        foreach ($rows as $row) {
            $lastId = (int) $row->id;
            $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));
            $profileUrl = $row->lid ? 'https://www.linkedin.com/in/'.$row->lid : null;

            $lead = V2CampaignLead::firstOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'source_list_src' => 'sn',
                    'source_record_id' => $row->id,
                ],
                [
                    'provider_profile_id' => $row->lid,
                    'full_name' => $name !== '' ? $name : 'Unknown',
                    'headline' => $row->headline,
                    'profile_url' => $profileUrl,
                    'status' => 'pending',
                    'meta' => [
                        'list_hash' => $list->list_hash,
                        'member_urn' => $row->object_urn,
                        'network_distance' => $row->degree,
                    ],
                ]
            );

            $this->ensureProgress($campaign, $lead);

            if ($lead->wasRecentlyCreated) {
                $added++;
            }
        }

        return [
            'added' => $added,
            'after_id' => $lastId,
            'exhausted' => $rows->count() < self::CHUNK_SIZE,
        ];
    }
}
