<?php

namespace App\V2\Services;

use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\SnLeadList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class LeadListService
{
    /**
     * Audience + SN lists with lead counts via one GROUP BY per source (not correlated subselects).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listsForUser(int $userId): Collection
    {
        $audiences = Audience::where('user_id', $userId)
            ->select('id', 'audience_name', 'audience_id', 'source', 'created_at')
            ->get();

        $audienceIds = $audiences->pluck('audience_id')->filter()->values()->all();
        $audienceCounts = $audienceIds === []
            ? collect()
            : AudienceList::query()
                ->whereIn('audience_id', $audienceIds)
                ->selectRaw('audience_id, COUNT(*) as aggregate')
                ->groupBy('audience_id')
                ->pluck('aggregate', 'audience_id');

        $mappedAudiences = $audiences->map(fn ($a) => [
            'id' => $a->id,
            'list_id' => (string) $a->audience_id,
            'list_name' => $a->audience_name ?: 'Untitled audience',
            'total_leads' => (int) ($audienceCounts[$a->audience_id] ?? 0),
            'source' => 'Audience',
            'src' => 'aud',
            'created_at' => optional($a->created_at)->toIso8601String(),
        ]);

        $snLists = SnLeadList::where('user_id', $userId)
            ->select('id', 'name', 'list_hash', 'created_at')
            ->get();

        $listHashes = $snLists->pluck('list_hash')->filter()->values()->all();
        $snCounts = $listHashes === []
            ? collect()
            : SnLead::query()
                ->whereIn('sn_list_id', $listHashes)
                ->selectRaw('sn_list_id, COUNT(*) as aggregate')
                ->groupBy('sn_list_id')
                ->pluck('aggregate', 'sn_list_id');

        $mappedSn = $snLists->map(fn ($l) => [
            'id' => $l->id,
            'list_id' => (string) $l->list_hash,
            'list_name' => $l->name ?: 'Untitled list',
            'total_leads' => (int) ($snCounts[$l->list_hash] ?? 0),
            'source' => 'Audience',
            'src' => 'sn',
            'created_at' => optional($l->created_at)->toIso8601String(),
        ]);

        return $mappedAudiences->concat($mappedSn)->sortBy('list_name')->values();
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateLeads(int $userId, string $listId, string $src, string $search = '', int $perPage = 20): LengthAwarePaginator
    {
        if ($src === 'aud') {
            $audience = Audience::where('audience_id', $listId)->where('user_id', $userId)->first();
            if (!$audience) {
                abort(404);
            }

            $query = AudienceList::where('audience_id', $listId);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('con_first_name', 'like', "%{$search}%")
                        ->orWhere('con_last_name', 'like', "%{$search}%")
                        ->orWhere('con_job_title', 'like', "%{$search}%")
                        ->orWhere('con_location', 'like', "%{$search}%")
                        ->orWhere('con_email', 'like', "%{$search}%");
                });
            }

            return $query->latest()->paginate($perPage)
                ->through(fn (AudienceList $row) => $this->transformAudLead($row));
        }

        $list = SnLeadList::where('list_hash', $listId)->where('user_id', $userId)->first();
        if (!$list) {
            abort(404);
        }

        $query = SnLead::where('sn_list_id', $listId);
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('headline', 'like', "%{$search}%")
                    ->orWhere('geolocation', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage)
            ->through(fn (SnLead $row) => $this->transformSnLead($row));
    }

    /**
     * @param  list<array{list_id: string, src: string, select_all?: bool, lead_ids?: array<int>}>  $lists
     * @return Collection<int, array<string, mixed>>
     */
    public function resolveLeadsFromLists(int $userId, array $lists): Collection
    {
        $merged = collect();

        foreach ($lists as $list) {
            $listId = trim((string) ($list['list_id'] ?? ''));
            $src = trim((string) ($list['src'] ?? ''));
            if ($listId === '' || !in_array($src, ['aud', 'sn'], true)) {
                continue;
            }

            $leads = $this->resolveLeads(
                $userId,
                $listId,
                $src,
                array_map('intval', $list['lead_ids'] ?? []),
                (bool) ($list['select_all'] ?? false),
            );

            $merged = $merged->concat($leads);
        }

        return $merged
            ->filter(fn (array $lead) => trim((string) ($lead['profileid'] ?? '')) !== '')
            ->unique(fn (array $lead) => trim((string) ($lead['profileid'] ?? '')))
            ->values();
    }

    /**
     * @param  array<int, int>  $leadIds
     * @return Collection<int, array<string, mixed>>
     */
    public function resolveLeads(int $userId, string $listId, string $src, array $leadIds = [], bool $selectAll = false): Collection
    {
        if ($selectAll) {
            if ($src === 'aud') {
                Audience::where('audience_id', $listId)->where('user_id', $userId)->firstOrFail();

                return AudienceList::where('audience_id', $listId)
                    ->latest()
                    ->get()
                    ->map(fn (AudienceList $row) => $this->transformAudLead($row));
            }

            SnLeadList::where('list_hash', $listId)->where('user_id', $userId)->firstOrFail();

            return SnLead::where('sn_list_id', $listId)
                ->latest()
                ->get()
                ->map(fn (SnLead $row) => $this->transformSnLead($row));
        }

        if ($leadIds === []) {
            return collect();
        }

        if ($src === 'aud') {
            Audience::where('audience_id', $listId)->where('user_id', $userId)->firstOrFail();

            return AudienceList::where('audience_id', $listId)
                ->whereIn('id', $leadIds)
                ->get()
                ->map(fn (AudienceList $row) => $this->transformAudLead($row));
        }

        SnLeadList::where('list_hash', $listId)->where('user_id', $userId)->firstOrFail();

        return SnLead::where('sn_list_id', $listId)
            ->whereIn('id', $leadIds)
            ->get()
            ->map(fn (SnLead $row) => $this->transformSnLead($row));
    }

    /**
     * @return array<string, mixed>
     */
    public function transformAudLead(AudienceList $row): array
    {
        $name = trim(($row->con_first_name ?? '').' '.($row->con_last_name ?? ''));

        return [
            'id' => $row->id,
            'name' => $name !== '' ? $name : 'Unknown',
            'email' => $row->con_email,
            'headline' => $row->con_job_title,
            'location' => $row->con_location,
            'profileid' => $row->con_id,
            'public_identifier' => $row->con_public_identifier,
            'profile_url' => $row->con_public_identifier
                ? 'https://www.linkedin.com/in/'.$row->con_public_identifier
                : $row->con_profile_url,
            'source' => 'aud',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transformSnLead(SnLead $row): array
    {
        $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));

        return [
            'id' => $row->id,
            'name' => $name !== '' ? $name : 'Unknown',
            'email' => $row->email,
            'headline' => $row->headline,
            'location' => $row->geolocation,
            'profileid' => $row->lid,
            'public_identifier' => $row->lid,
            'profile_url' => $row->lid ? 'https://www.linkedin.com/in/'.$row->lid : null,
            'source' => 'sn',
        ];
    }
}
