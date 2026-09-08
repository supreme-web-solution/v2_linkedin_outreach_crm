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
     *     auto_sourced: bool,
     *     search_filters?: array<string, mixed>
     * }|null
     */
    public function tryBuildFromPlan(User $user, int $organizationId, array $plan): ?array
    {
        $query = trim((string) ($plan['icp_notes'] ?? $plan['audience'] ?? $plan['goal'] ?? ''));
        if ($query === '') {
            return null;
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountId($user->id);
        if (! $accountId) {
            Log::warning('[Alex] LinkedIn audience search skipped — no connected Unipile LinkedIn account', [
                'user_id' => $user->id,
            ]);

            return null;
        }

        $targetCount = isset($plan['target_count']) ? (int) $plan['target_count'] : null;
        $limit = $targetCount !== null ? max(10, min(100, $targetCount)) : null;
        $variants = IcpSearchFilterParser::searchVariants(
            $query,
            isset($plan['geography']) ? (string) $plan['geography'] : null,
            $limit,
        );

        if ($targetCount !== null) {
            foreach ($variants as $i => $variant) {
                $variants[$i]['limit'] = max(10, min(100, $targetCount));
                $variants[$i]['audience_name'] = Str::limit(
                    ($variant['audience_name'] ?? 'LinkedIn Search').' ('.$targetCount.')',
                    80,
                    '',
                );
            }
        }

        $attempts = [];
        foreach ($variants as $index => $filters) {
            $built = $this->searchAndPersist($user, $organizationId, $filters);
            $attempts[] = [
                'attempt' => $index + 1,
                'keywords' => $filters['keywords'] ?? null,
                'title' => $filters['title'] ?? null,
                'location' => $filters['location'] ?? null,
                'stored' => $built['total_leads'] ?? 0,
            ];

            if ($built !== null) {
                Log::info('[Alex] LinkedIn audience search succeeded', [
                    'user_id' => $user->id,
                    'attempt' => $index + 1,
                    'filters' => $filters,
                    'stored' => $built['total_leads'],
                    'list_hash' => $built['list_hash'],
                ]);

                $built['search_filters'] = $filters;
                $built['search_attempts'] = $attempts;

                return $built;
            }
        }

        Log::warning('[Alex] LinkedIn audience search returned no profiles after variants', [
            'user_id' => $user->id,
            'query' => Str::limit($query, 200),
            'attempts' => $attempts,
        ]);

        return null;
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
                'filters' => $filters,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $elements = Arr::get($result, 'items', Arr::get($result, 'data.items', []));
        if (! is_array($elements) || $elements === []) {
            Log::info('[Alex] LinkedIn search empty for filters', [
                'user_id' => $user->id,
                'filters' => $filters,
            ]);

            return null;
        }

        $audienceName = trim((string) ($filters['audience_name'] ?? 'LinkedIn Search'));
        if ($audienceName === '') {
            $audienceName = 'LinkedIn Search';
        }

        // Unique list per fetch so new campaigns don't collide with prior search hashes.
        $slug = Str::slug(Str::limit($audienceName, 40, '')) ?: 'search';
        $listHash = 'search-'.$user->id.'-'.$slug.'-'.now()->format('YmdHis');
        $stored = 0;
        $skippedNoId = 0;

        foreach ($elements as $item) {
            if (! is_array($item)) {
                continue;
            }

            $profileId = $this->resolveProfileId($item);
            if ($profileId === '') {
                $skippedNoId++;

                continue;
            }

            V2Lead::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider' => 'linkedin',
                    'provider_profile_id' => $profileId,
                ],
                [
                    'public_identifier' => Arr::get($item, 'public_identifier', Arr::get($item, 'publicIdentifier')),
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
                            'search_filters' => [
                                'keywords' => $filters['keywords'] ?? null,
                                'title' => $filters['title'] ?? null,
                                'location' => $filters['location'] ?? null,
                            ],
                        ],
                    ],
                );

                $this->leadPipeline->syncV2LeadToSnList($user, $lead, $listHash, $audienceName);
                $stored++;
            }
        }

        if ($stored === 0) {
            Log::warning('[Alex] LinkedIn search returned items but none could be saved', [
                'user_id' => $user->id,
                'raw_count' => count($elements),
                'skipped_no_id' => $skippedNoId,
                'sample_keys' => array_keys(is_array($elements[0] ?? null) ? $elements[0] : []),
                'filters' => $filters,
            ]);

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

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveProfileId(array $item): string
    {
        foreach ([
            'provider_id',
            'id',
            'public_identifier',
            'publicIdentifier',
            'member_urn',
            'entity_urn',
            'profile_id',
        ] as $key) {
            $value = trim((string) Arr::get($item, $key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
