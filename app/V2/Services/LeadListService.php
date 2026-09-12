<?php

namespace App\V2\Services;

use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LeadListService
{
    /**
     * Audience + SN + imported CSV lists with lead counts via one GROUP BY per source.
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
            'source' => 'Sales Navigator',
            'src' => 'sn',
            'created_at' => optional($l->created_at)->toIso8601String(),
        ]);

        $csvLists = V2OutreachImportList::where('user_id', $userId)
            ->select('id', 'name', 'list_hash', 'lead_count', 'created_at')
            ->get();

        $csvIds = $csvLists->pluck('id')->filter()->values()->all();
        $csvCounts = $csvIds === []
            ? collect()
            : V2OutreachImportLead::query()
                ->whereIn('import_list_id', $csvIds)
                ->selectRaw('import_list_id, COUNT(*) as aggregate')
                ->groupBy('import_list_id')
                ->pluck('aggregate', 'import_list_id');

        $mappedCsv = $csvLists->map(function ($l) use ($csvCounts) {
            $name = (string) ($l->name ?: 'Imported list');
            $isInstagram = str_starts_with($name, 'IG:')
                || str_contains(Str::lower($name), 'instagram');

            return [
                'id' => $l->id,
                'list_id' => (string) $l->list_hash,
                'list_name' => $name,
                'total_leads' => (int) ($csvCounts[$l->id] ?? $l->lead_count ?? 0),
                'source' => $isInstagram ? 'Instagram' : 'Spreadsheet import',
                'channel' => $isInstagram ? 'instagram' : null,
                'src' => 'csv',
                'created_at' => optional($l->created_at)->toIso8601String(),
            ];
        });

        return $mappedAudiences->concat($mappedSn)->concat($mappedCsv)
            ->sortByDesc(fn (array $list) => $list['created_at'] ?? '')
            ->values();
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

        if ($src === 'csv') {
            $list = V2OutreachImportList::where('list_hash', $listId)->where('user_id', $userId)->first();
            if (! $list) {
                abort(404);
            }

            $query = V2OutreachImportLead::where('import_list_id', $list->id);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('linkedin_id', 'like', "%{$search}%")
                        ->orWhere('instagram_handle', 'like', "%{$search}%")
                        ->orWhere('telegram_handle', 'like', "%{$search}%")
                        ->orWhere('twitter_handle', 'like', "%{$search}%");
                });
            }

            return $query->latest()->paginate($perPage)
                ->through(fn (V2OutreachImportLead $row) => $this->transformCsvLead($row));
        }

        $list = SnLeadList::where('list_hash', $listId)->where('user_id', $userId)->first();
        if (! $list) {
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
            if ($listId === '' || ! in_array($src, ['aud', 'sn', 'csv'], true)) {
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
            ->filter(function (array $lead): bool {
                $name = trim((string) ($lead['name'] ?? ''));
                $email = trim((string) ($lead['email'] ?? ''));
                $phone = trim((string) ($lead['phone'] ?? ''));
                $profileid = trim((string) ($lead['profileid'] ?? ''));
                $profileUrl = trim((string) ($lead['profile_url'] ?? ''));

                return $name !== '' || $email !== '' || $phone !== '' || $profileid !== '' || $profileUrl !== '';
            })
            ->unique(fn (array $lead) => $this->leadIdentityKey($lead))
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

            if ($src === 'csv') {
                $list = V2OutreachImportList::where('list_hash', $listId)->where('user_id', $userId)->firstOrFail();

                return V2OutreachImportLead::where('import_list_id', $list->id)
                    ->latest()
                    ->get()
                    ->map(fn (V2OutreachImportLead $row) => $this->transformCsvLead($row));
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

        if ($src === 'csv') {
            $list = V2OutreachImportList::where('list_hash', $listId)->where('user_id', $userId)->firstOrFail();

            return V2OutreachImportLead::where('import_list_id', $list->id)
                ->whereIn('id', $leadIds)
                ->get()
                ->map(fn (V2OutreachImportLead $row) => $this->transformCsvLead($row));
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

    /**
     * @return array<string, mixed>
     */
    public function transformCsvLead(V2OutreachImportLead $row): array
    {
        $name = trim((string) ($row->full_name ?? ''));
        $profileid = trim((string) ($row->linkedin_id ?? $row->instagram_provider_id ?? $row->telegram_provider_id ?? ''));

        return [
            'id' => $row->id,
            'name' => $name !== '' ? $name : 'Unknown',
            'email' => $row->email,
            'phone' => $row->phone,
            'headline' => null,
            'location' => null,
            'profileid' => $profileid,
            'public_identifier' => $row->linkedin_id ?? $row->instagram_handle ?? $row->telegram_handle ?? $row->twitter_handle,
            'profile_url' => $row->profile_url,
            'instagram_handle' => $row->instagram_handle,
            'telegram_handle' => $row->telegram_handle,
            'twitter_handle' => $row->twitter_handle,
            'source' => 'csv',
        ];
    }

    /**
     * Stable cross-source dedupe key so non-LinkedIn imports are retained.
     */
    private function leadIdentityKey(array $lead): string
    {
        $profileid = strtolower(trim((string) ($lead['profileid'] ?? '')));
        $email = strtolower(trim((string) ($lead['email'] ?? '')));
        $phone = preg_replace('/\D+/', '', (string) ($lead['phone'] ?? '')) ?? '';
        $ig = strtolower(trim((string) ($lead['instagram_handle'] ?? '')));
        $tg = strtolower(trim((string) ($lead['telegram_handle'] ?? '')));
        $x = strtolower(trim((string) ($lead['twitter_handle'] ?? '')));
        $url = strtolower(trim((string) ($lead['profile_url'] ?? '')));
        $name = strtolower(trim((string) ($lead['name'] ?? '')));
        $src = strtolower(trim((string) ($lead['source'] ?? '')));
        $id = (string) ($lead['id'] ?? '');

        foreach ([$profileid, $email, $phone, $ig, $tg, $x, $url] as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $src.':'.$id.':'.$name;
    }
}
