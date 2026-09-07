<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\Models\V2Lead;
use App\Models\V2LeadSource;
use App\V2\Ai\Support\IcpSearchFilterParser;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Services\LeadPipelineService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class LinkedInAudienceBuilderService
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly LeadPipelineService $leadPipeline,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return array{
     *     list_hash: string,
     *     list_src: string,
     *     list_name: string,
     *     total_leads: int,
     *     match_score: int,
     *     auto_sourced: bool
     * }|null
     */
    public function tryBuildFromPlan(User $user, int $organizationId, array $plan): ?array
    {
        $query = trim((string) ($plan['icp_notes'] ?? $plan['audience'] ?? $plan['goal'] ?? ''));
        if ($query === '') {
            return null;
        }

        $targetCount = isset($plan['target_count']) ? (int) $plan['target_count'] : null;
        $filters = IcpSearchFilterParser::fromGoal(
            $query,
            isset($plan['geography']) ? (string) $plan['geography'] : null,
            $targetCount !== null ? max(25, min(100, $targetCount)) : null,
        );

        if ($targetCount !== null) {
            $filters['limit'] = max(25, min(100, $targetCount));
            $filters['audience_name'] = Str::limit($query.' ('.$targetCount.')', 80, '');
        }

        return $this->searchAndPersist($user, $organizationId, $filters);
    }

    /**
     * @param  array{keywords?:string,title?:string,location?:string,limit?:int,audience_name?:string}  $filters
     * @return array{
     *     list_hash: string,
     *     list_src: string,
     *     list_name: string,
     *     total_leads: int,
     *     match_score: int,
     *     auto_sourced: bool
     * }|null
     */
    public function searchAndPersist(User $user, int $organizationId, array $filters): ?array
    {
        $accountId = V2IntegrationAccount::activeUnipileAccountId($user->id);
        if (! $accountId) {
            return null;
        }

        try {
            /** @var UnipileProvider $provider */
            $provider = $this->providerManager->search($this->providerManager->defaultProvider());
            $result = $provider->searchPeople($filters, [
                'account_id' => $accountId,
                'owner_id' => (string) $user->id,
                'organization_id' => $organizationId,
            ]);
        } catch (Throwable $e) {
            Log::warning('[Alex] LinkedIn audience search failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $elements = Arr::get($result, 'items', Arr::get($result, 'data.items', []));
        if (! is_array($elements) || $elements === []) {
            return null;
        }

        $audienceName = trim((string) ($filters['audience_name'] ?? 'LinkedIn Search'));
        if ($audienceName === '') {
            $audienceName = 'LinkedIn Search';
        }

        $slug = Str::slug(Str::limit($audienceName, 60, '')) ?: 'search';
        $listHash = 'search-'.$user->id.'-'.$slug;
        $stored = 0;

        foreach ($elements as $item) {
            if (! is_array($item)) {
                continue;
            }

            $profileId = (string) (
                Arr::get($item, 'provider_id')
                ?? Arr::get($item, 'id')
                ?? Arr::get($item, 'public_identifier')
                ?? ''
            );

            if ($profileId === '') {
                continue;
            }

            V2Lead::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider' => 'linkedin',
                    'provider_profile_id' => $profileId,
                ],
                [
                    'public_identifier' => Arr::get($item, 'public_identifier'),
                    'full_name' => Arr::get($item, 'full_name', Arr::get($item, 'name')),
                    'headline' => Arr::get($item, 'headline'),
                    'company_name' => Arr::get($item, 'company_name', Arr::get($item, 'current_company')),
                    'location' => Arr::get($item, 'location'),
                    'email' => Arr::get($item, 'email'),
                    'profile_data' => $item,
                ],
            );

            $lead = V2Lead::query()
                ->where('user_id', $user->id)
                ->where('provider_profile_id', $profileId)
                ->first();

            if ($lead) {
                V2LeadSource::query()->updateOrCreate(
                    [
                        'lead_id' => $lead->id,
                        'source_type' => 'sales_navigator',
                        'source_external_id' => $listHash,
                    ],
                    [
                        'source_payload' => [
                            'source_name' => $audienceName,
                            'imported_at' => now()->toIso8601String(),
                            'auto_sourced_by' => 'alex',
                        ],
                    ],
                );

                $this->leadPipeline->syncV2LeadToSnList($user, $lead, $listHash, $audienceName);
                $stored++;
            }
        }

        if ($stored === 0) {
            return null;
        }

        return [
            'list_hash' => $listHash,
            'list_src' => 'sn',
            'list_name' => $audienceName,
            'total_leads' => $stored,
            'match_score' => 95,
            'auto_sourced' => true,
        ];
    }
}
