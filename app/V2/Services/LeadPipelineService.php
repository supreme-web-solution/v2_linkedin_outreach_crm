<?php

namespace App\V2\Services;

use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2Lead;
use App\Models\V2LeadSource;
use Illuminate\Support\Arr;

class LeadPipelineService
{
    /**
     * Mirror a V2 lead into the legacy sn_leads list so web Leads + Campaigns stay in sync.
     */
    public function syncV2LeadToSnList(User $user, V2Lead $lead, string $listHash, ?string $listName = null): SnLead
    {
        $list = SnLeadList::query()->firstOrCreate(
            [
                'list_hash' => $listHash,
                'user_id' => $user->id,
            ],
            [
                'name' => $listName ?: 'Imported list',
            ]
        );

        if ($listName && $list->name !== $listName) {
            $list->forceFill(['name' => $listName])->save();
        }

        V2LeadSource::query()->updateOrCreate(
            [
                'lead_id' => $lead->id,
                'source_type' => 'sales_navigator',
                'source_external_id' => $listHash,
            ],
            [
                'source_payload' => [
                    'source_name' => $listName ?: $list->name,
                    'imported_at' => now()->toIso8601String(),
                ],
            ]
        );

        [$firstName, $lastName] = $this->splitName((string) $lead->full_name);
        $profileData = is_array($lead->profile_data) ? $lead->profile_data : [];
        $lid = (string) ($lead->public_identifier ?: $lead->provider_profile_id ?: Arr::get($profileData, 'public_identifier', ''));

        return SnLead::query()->updateOrCreate(
            [
                'sn_list_id' => $list->list_hash,
                'lid' => $lid !== '' ? $lid : ('v2-'.$lead->id),
            ],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'headline' => $lead->headline,
                'email' => $lead->email,
                'sn_lid' => $lead->provider_profile_id,
                'geolocation' => $lead->location,
                'degree' => Arr::get($profileData, 'network_distance'),
                'object_urn' => Arr::get($profileData, 'object_urn'),
            ]
        );
    }

    /**
     * @return array{0: int, 1: string}
     */
    public function splitName(string $fullName): array
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $fullName, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
