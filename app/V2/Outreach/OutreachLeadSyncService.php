<?php

namespace App\V2\Outreach;

use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachImportList;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\Models\V2OutreachList;

/**
 * Chunked copy of list members → outreach leads (never loads a full list into memory).
 */
class OutreachLeadSyncService
{
    public const CHUNK_SIZE = 250;

    public function __construct(
        private readonly OutreachLeadContactResolver $resolver,
    ) {}

    public function syncAllLists(V2OutreachCampaign $campaign): int
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
     * Sync one small page of source rows, then return so the queue can breathe.
     *
     * @return array{done: bool, list_index: int, after_id: int, added: int}
     */
    public function syncNextChunk(V2OutreachCampaign $campaign, int $listIndex = 0, int $afterId = 0): array
    {
        $lists = $campaign->outreachLists()->orderBy('id')->get();
        if ($lists->isEmpty() || $listIndex >= $lists->count()) {
            return ['done' => true, 'list_index' => $listIndex, 'after_id' => 0, 'added' => 0];
        }

        /** @var V2OutreachList $list */
        $list = $lists[$listIndex];
        $result = match ($list->list_src) {
            'csv' => $this->syncCsvChunk($campaign, $list, $afterId),
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

    public function initProgress(V2OutreachCampaign $campaign): void
    {
        $campaign->outreachLeads()->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($leads) use ($campaign) {
            foreach ($leads as $lead) {
                $this->ensureProgress($campaign, $lead);
            }
        });
    }

    private function ensureProgress(V2OutreachCampaign $campaign, V2OutreachLead $lead): void
    {
        V2OutreachLeadProgress::firstOrCreate(
            ['outreach_campaign_id' => $campaign->id, 'outreach_lead_id' => $lead->id],
            ['current_node_key' => 0, 'next_node_key' => 1, 'run_status' => 0, 'channel_state' => []]
        );
    }

    public function markSyncing(V2OutreachCampaign $campaign): void
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

    public function markSyncComplete(V2OutreachCampaign $campaign, int $added): void
    {
        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        unset($meta['lead_sync']);
        $campaign->forceFill([
            'status' => 'running',
            'meta' => $meta,
        ])->save();
    }

    public function markSyncFailed(V2OutreachCampaign $campaign, string $message): void
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
    private function syncCsvChunk(V2OutreachCampaign $campaign, V2OutreachList $list, int $afterId): array
    {
        $importList = V2OutreachImportList::query()
            ->where('user_id', $campaign->user_id)
            ->where('list_hash', $list->list_hash)
            ->first();

        if (! $importList) {
            return ['added' => 0, 'after_id' => 0, 'exhausted' => true];
        }

        $rows = $importList->leads()
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
            $contactRow = [
                'email' => trim((string) ($row->email ?? '')),
                'phone' => trim((string) ($row->phone ?? '')),
                'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
                'instagram_handle' => trim((string) ($row->instagram_handle ?? '')),
                'instagram_provider_id' => trim((string) ($row->instagram_provider_id ?? '')),
                'telegram_handle' => trim((string) ($row->telegram_handle ?? '')),
                'telegram_provider_id' => trim((string) ($row->telegram_provider_id ?? '')),
                'twitter_handle' => trim((string) ($row->twitter_handle ?? '')),
                'twitter_provider_id' => trim((string) ($row->twitter_provider_id ?? '')),
            ];
            $attrs = $this->resolver->toLeadAttributes($contactRow);
            $meta = array_merge(['list_hash' => $list->list_hash, 'import_list' => true], $attrs['meta']);
            $profileId = trim((string) ($row->linkedin_id ?? ''));

            $lead = V2OutreachLead::firstOrCreate(
                ['outreach_campaign_id' => $campaign->id, 'source_list_src' => 'csv', 'source_record_id' => $row->id],
                [
                    'provider_profile_id' => $profileId !== '' ? $profileId : null,
                    'email' => $attrs['email'],
                    'phone' => $attrs['phone'],
                    'full_name' => trim((string) ($row->full_name ?? '')) ?: 'Contact',
                    'headline' => null,
                    'profile_url' => $row->profile_url,
                    'status' => 'pending',
                    'meta' => $meta,
                ]
            );

            if (! $lead->wasRecentlyCreated) {
                $mergedMeta = array_merge($lead->meta ?? [], $meta);
                $lead->update(array_filter([
                    'email' => $lead->email ?: $attrs['email'],
                    'phone' => $lead->phone ?: $attrs['phone'],
                    'meta' => $mergedMeta,
                ]));
            }

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
    private function syncAudienceChunk(V2OutreachCampaign $campaign, V2OutreachList $list, int $afterId): array
    {
        $userId = (int) $campaign->user_id;
        $rows = AudienceList::query()
            ->where('audience_id', $list->list_hash)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE)
            ->get();

        if ($rows->isEmpty()) {
            return ['added' => 0, 'after_id' => 0, 'exhausted' => true];
        }

        $keys = $this->resolver->linkedinKeysFromRows($rows, 'aud');
        $overlays = $this->resolver->overlaysForKeys($userId, $keys);
        $added = 0;
        $lastId = $afterId;

        foreach ($rows as $row) {
            $lastId = (int) $row->id;
            $name = trim(($row->con_first_name ?? '').' '.($row->con_last_name ?? ''));
            $profileId = $row->con_public_identifier ?: $row->con_id;
            $linkedinKey = $this->resolver->normalizeLinkedinKey($profileId);
            $contactRow = $this->resolver->mergeRow([
                'email' => trim((string) ($row->con_email ?? '')),
                'phone' => trim((string) ($row->con_phone ?? '')),
                'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
                'instagram_handle' => '',
                'instagram_provider_id' => '',
                'telegram_handle' => '',
                'telegram_provider_id' => '',
                'twitter_handle' => '',
                'twitter_provider_id' => '',
            ], $overlays[strtolower($linkedinKey)] ?? null);
            $attrs = $this->resolver->toLeadAttributes($contactRow);
            $meta = array_merge(['list_hash' => $list->list_hash], $attrs['meta']);

            $lead = V2OutreachLead::firstOrCreate(
                ['outreach_campaign_id' => $campaign->id, 'source_list_src' => 'aud', 'source_record_id' => $row->id],
                [
                    'provider_profile_id' => $profileId,
                    'email' => $attrs['email'],
                    'phone' => $attrs['phone'],
                    'full_name' => $name !== '' ? $name : 'Unknown',
                    'headline' => $row->con_job_title,
                    'profile_url' => $row->con_public_identifier
                        ? 'https://www.linkedin.com/in/'.$row->con_public_identifier
                        : $row->con_profile_url,
                    'status' => 'pending',
                    'meta' => $meta,
                ]
            );

            if (! $lead->wasRecentlyCreated) {
                $updates = [];
                if (empty($lead->email) && ! empty($attrs['email'])) {
                    $updates['email'] = $attrs['email'];
                }
                if (empty($lead->phone) && ! empty($attrs['phone'])) {
                    $updates['phone'] = $attrs['phone'];
                }
                $mergedMeta = array_merge($lead->meta ?? [], $meta);
                if ($mergedMeta !== ($lead->meta ?? [])) {
                    $updates['meta'] = $mergedMeta;
                }
                if ($updates !== []) {
                    $lead->update($updates);
                }
            }

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
    private function syncSnChunk(V2OutreachCampaign $campaign, V2OutreachList $list, int $afterId): array
    {
        $userId = (int) $campaign->user_id;
        $rows = SnLead::query()
            ->where('sn_list_id', $list->list_hash)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE)
            ->get();

        if ($rows->isEmpty()) {
            return ['added' => 0, 'after_id' => 0, 'exhausted' => true];
        }

        $keys = $this->resolver->linkedinKeysFromRows($rows, 'sn');
        $overlays = $this->resolver->overlaysForKeys($userId, $keys);
        $added = 0;
        $lastId = $afterId;

        foreach ($rows as $row) {
            $lastId = (int) $row->id;
            $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));
            $linkedinKey = $this->resolver->normalizeLinkedinKey($row->lid ?: $row->sn_lid);
            $contactRow = $this->resolver->mergeRow([
                'email' => trim((string) ($row->email ?? '')),
                'phone' => trim((string) ($row->phone ?? '')),
                'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
                'instagram_handle' => trim((string) ($row->instagram_handle ?? '')),
                'instagram_provider_id' => trim((string) ($row->instagram_provider_id ?? '')),
                'telegram_handle' => trim((string) ($row->telegram_handle ?? '')),
                'telegram_provider_id' => trim((string) ($row->telegram_provider_id ?? '')),
                'twitter_handle' => trim((string) ($row->twitter_handle ?? '')),
                'twitter_provider_id' => trim((string) ($row->twitter_provider_id ?? '')),
            ], $overlays[strtolower($linkedinKey)] ?? null);
            $attrs = $this->resolver->toLeadAttributes($contactRow);
            $meta = array_merge(['list_hash' => $list->list_hash], $attrs['meta']);

            $lead = V2OutreachLead::firstOrCreate(
                ['outreach_campaign_id' => $campaign->id, 'source_list_src' => 'sn', 'source_record_id' => $row->id],
                [
                    'provider_profile_id' => $row->lid,
                    'email' => $attrs['email'],
                    'phone' => $attrs['phone'],
                    'full_name' => $name !== '' ? $name : 'Unknown',
                    'headline' => $row->headline,
                    'profile_url' => $row->lid ? 'https://www.linkedin.com/in/'.$row->lid : null,
                    'status' => 'pending',
                    'meta' => $meta,
                ]
            );

            if (! $lead->wasRecentlyCreated) {
                $updates = [];
                if (empty($lead->email) && ! empty($attrs['email'])) {
                    $updates['email'] = $attrs['email'];
                }
                if (empty($lead->phone) && ! empty($attrs['phone'])) {
                    $updates['phone'] = $attrs['phone'];
                }
                $mergedMeta = array_merge($lead->meta ?? [], $meta);
                if ($mergedMeta !== ($lead->meta ?? [])) {
                    $updates['meta'] = $mergedMeta;
                }
                if ($updates !== []) {
                    $lead->update($updates);
                }
            }

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
