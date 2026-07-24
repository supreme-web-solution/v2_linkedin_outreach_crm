<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\Models\V2Lead;
use App\Models\V2LeadSource;
use App\V2\DTO\LeadSearchRequestData;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Services\LeadPipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly LeadPipelineService $leadPipeline,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $leads = V2Lead::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $leads]);
    }

    public function search(Request $request): JsonResponse
    {
        $user           = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data           = LeadSearchRequestData::fromRequest($request);

        Log::info('[Search] Incoming search request', [
            'user_id'  => $user->id,
            'org_id'   => $organizationId,
            'params'   => array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []),
        ]);

        // ── Guard: need a connected LinkedIn account ───────────────────────────
        $unipileAccountId = V2IntegrationAccount::activeUnipileAccountId($user->id);
        if (!$unipileAccountId) {
            Log::warning('[Search] No active Unipile account for user '.$user->id);
            return response()->json([
                'message'    => 'No connected LinkedIn account found. Please connect your LinkedIn account first via the extension Settings or the Integrations page.',
                'error_code' => 'no_linkedin_account',
            ], 422);
        }

        Log::info('[Search] Using Unipile account '.$unipileAccountId.' for user '.$user->id);

        $providerKey = $this->providerManager->defaultProvider();
        /** @var UnipileProvider $provider */
        $provider = $this->providerManager->search($providerKey);

        // ── Route: single profile import by URL ──────────────────────────────
        $profileUrl = $data['profile_url'] ?? null;
        if ($profileUrl) {
            Log::info('[Search] Profile-URL import', ['url' => $profileUrl]);
            try {
                $profileData = $provider->getProfileByUrl($profileUrl, $unipileAccountId);
                $elements = [$profileData];
                $result = ['items' => $elements, 'mode' => 'profile_import'];
            } catch (\Throwable $e) {
                Log::error('[Search] Profile-URL import failed', ['error' => $e->getMessage()]);
                return response()->json(['message' => 'Profile lookup failed: '.$e->getMessage()], 422);
            }
        }

        // ── Route: URL-based search ───────────────────────────────────────────
        elseif (!empty($data['linkedin_url'])) {
            if (! UnipileProvider::isValidSearchUrl($data['linkedin_url'])) {
                return response()->json([
                    'message' => 'This LinkedIn page is not a search URL. Open a people search on LinkedIn '
                        .'(e.g. /search/results/people or Sales Navigator search), copy that URL, then import.',
                    'error_code' => 'invalid_search_url',
                ], 422);
            }

            Log::info('[Search] URL-based search', ['url' => $data['linkedin_url']]);
            try {
                $result = $provider->searchFromUrl(
                    $data['linkedin_url'],
                    $unipileAccountId,
                    (int) ($data['limit'] ?? 20)
                );
            } catch (\Throwable $e) {
                Log::error('[Search] URL-based search failed', ['error' => $e->getMessage()]);
                return response()->json(['message' => 'URL search failed: '.$e->getMessage()], 422);
            }
            $elements = Arr::get($result, 'items', Arr::get($result, 'data.items', []));
        }

        // ── Route: filter-based search ────────────────────────────────────────
        else {
            try {
                $result = $provider->searchPeople($data, [
                    'account_id'      => $unipileAccountId,
                    'owner_id'        => (string) $user->id,
                    'organization_id' => $organizationId,
                ]);
            } catch (\Throwable $e) {
                Log::error('[Search] Filter search failed', ['error' => $e->getMessage()]);
                $message = 'Search failed: '.$e->getMessage();
                if ($e instanceof \App\V2\Integrations\Unipile\UnipileException) {
                    $hint = $e->context['hint'] ?? null;
                    if ($hint) {
                        $message .= ' '.$hint;
                    }
                }

                return response()->json(['message' => $message], 422);
            }
            $elements = Arr::get($result, 'items', Arr::get($result, 'data.items', []));
        }

        if (!is_array($elements)) {
            $elements = [];
        }

        $requestedLimit = isset($data['limit'])
            ? max(1, min(100, (int) $data['limit']))
            : null;

        if ($requestedLimit !== null && count($elements) > $requestedLimit) {
            $elements = array_slice(array_values($elements), 0, $requestedLimit);
            if (isset($result['items']) && is_array($result['items'])) {
                $result['items'] = $elements;
            } elseif (isset($result['data']['items']) && is_array($result['data']['items'])) {
                $result['data']['items'] = $elements;
            }
        }

        Log::info('[Search] Results ready', [
            'count'   => count($elements),
            'requested_limit' => $requestedLimit,
            'persist' => ($data['persist_results'] ?? true),
        ]);

        // ── Persist results ───────────────────────────────────────────────────
        $stored = 0;
        $audience = $this->resolveAudienceMeta($data, $user);
        if (($data['persist_results'] ?? true) === true) {
            foreach ($elements as $item) {
                if (!is_array($item)) {
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
                        'user_id'             => $user->id,
                        'provider'            => 'linkedin',
                        'provider_profile_id' => $profileId,
                    ],
                    [
                        'public_identifier' => Arr::get($item, 'public_identifier'),
                        'full_name'         => Arr::get($item, 'full_name', Arr::get($item, 'name')),
                        'headline'          => Arr::get($item, 'headline'),
                        'company_name'      => Arr::get($item, 'company_name', Arr::get($item, 'current_company')),
                        'location'          => Arr::get($item, 'location'),
                        'email'             => Arr::get($item, 'email'),
                        'profile_data'      => $item,
                    ]
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
                            'source_external_id' => $audience['list_hash'],
                        ],
                        [
                            'source_payload' => [
                                'source_name' => $audience['name'],
                                'imported_at' => now()->toIso8601String(),
                            ],
                        ]
                    );

                    $this->leadPipeline->syncV2LeadToSnList(
                        $user,
                        $lead,
                        $audience['list_hash'],
                        $audience['name']
                    );
                }
                $stored++;
            }
        }

        Log::info('[Search] Stored '.$stored.' leads for user '.$user->id);

        return response()->json([
            'data'         => $result,
            'stored_count' => $stored,
            'audience_name' => $audience['name'],
            'source_external_id' => $audience['list_hash'],
            'meta'         => [
                'organization_id' => $organizationId,
                'provider'        => $providerKey,
                'account_id'      => $unipileAccountId,
                'mode'            => !empty($data['profile_url']) ? 'profile' : (!empty($data['linkedin_url']) ? 'url' : 'filter'),
                'audience_name'   => $audience['name'],
                'source_external_id' => $audience['list_hash'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, list_hash: string}
     */
    private function resolveAudienceMeta(array $data, User $user): array
    {
        $name = trim((string) ($data['audience_name'] ?? $data['source_name'] ?? ''));
        if ($name === '') {
            $name = 'LinkedIn Search';
        }

        $slug = Str::slug(Str::limit($name, 60, '')) ?: 'search';
        $listHash = 'search-'.$user->id.'-'.$slug;

        return [
            'name' => $name,
            'list_hash' => $listHash,
        ];
    }

    public function listSnSources(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');

        $sourceMeta = V2LeadSource::query()
            ->whereHas('lead', fn ($query) => $query->where('user_id', $user->id))
            ->selectRaw('source_external_id, COUNT(*) as leads_count, MAX(created_at) as last_imported_at')
            ->groupBy('source_external_id')
            ->get()
            ->keyBy('source_external_id');

        $lists = SnLeadList::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get();

        $rows = [];
        $seen = [];

        foreach ($lists as $list) {
            $listHash = (string) $list->list_hash;
            if ($listHash === '') {
                continue;
            }

            $seen[$listHash] = true;
            $meta = $sourceMeta->get($listHash);
            $payload = [];
            if ($meta) {
                $sample = V2LeadSource::query()
                    ->where('source_external_id', $listHash)
                    ->first();
                $payload = is_array($sample?->source_payload) ? $sample->source_payload : [];
            }

            $rows[] = [
                'source_external_id' => $listHash,
                'source_name' => $payload['source_name'] ?? $list->name,
                'leads_count' => SnLead::query()->where('sn_list_id', $listHash)->count(),
                'last_imported_at' => $meta?->last_imported_at ?? $list->updated_at,
            ];
        }

        foreach ($sourceMeta as $externalId => $meta) {
            if (isset($seen[$externalId])) {
                continue;
            }

            $sample = V2LeadSource::query()
                ->where('source_external_id', $externalId)
                ->first();
            $payload = is_array($sample?->source_payload) ? $sample->source_payload : [];

            $rows[] = [
                'source_external_id' => $externalId,
                'source_name' => $payload['source_name'] ?? $externalId,
                'leads_count' => (int) $meta->leads_count,
                'last_imported_at' => $meta->last_imported_at,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp((string) ($b['last_imported_at'] ?? ''), (string) ($a['last_imported_at'] ?? '')));

        return response()->json(['data' => array_values($rows)]);
    }

    public function listSnImportedLeads(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $sourceExternalId = (string) $request->query('source_external_id', '');

        $query = V2Lead::query()->where('user_id', $user->id);

        if ($sourceExternalId !== '') {
            $snProfileIds = SnLead::query()
                ->where('sn_list_id', $sourceExternalId)
                ->whereNotNull('sn_lid')
                ->where('sn_lid', '!=', '')
                ->pluck('sn_lid');

            $query->where(function ($leadQuery) use ($sourceExternalId, $snProfileIds) {
                $leadQuery->whereHas('sources', function ($sourceQuery) use ($sourceExternalId) {
                    $sourceQuery->where('source_external_id', $sourceExternalId);
                });

                if ($snProfileIds->isNotEmpty()) {
                    $leadQuery->orWhereIn('provider_profile_id', $snProfileIds);
                }
            });
        } else {
            $listHashes = SnLeadList::query()
                ->where('user_id', $user->id)
                ->pluck('list_hash')
                ->filter()
                ->values();

            $query->where(function ($leadQuery) use ($listHashes) {
                $leadQuery->whereHas('sources');

                if ($listHashes->isNotEmpty()) {
                    $snProfileIds = SnLead::query()
                        ->whereIn('sn_list_id', $listHashes)
                        ->whereNotNull('sn_lid')
                        ->where('sn_lid', '!=', '')
                        ->pluck('sn_lid');

                    if ($snProfileIds->isNotEmpty()) {
                        $leadQuery->orWhereIn('provider_profile_id', $snProfileIds);
                    }
                }
            });
        }

        return response()->json(['data' => $query->latest('id')->limit(
            min(500, max(1, (int) $request->query('limit', 200)))
        )->get()]);
    }

    public function importSnLeads(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'source_external_id' => ['required', 'string', 'max:191'],
            'source_name' => ['nullable', 'string', 'max:191'],
            'keywords' => ['nullable', 'string', 'max:255'],
            'persist_results' => ['nullable', 'boolean'],
            'leads' => ['nullable', 'array'],
            'leads.*.id' => ['nullable', 'string', 'max:191'],
            'leads.*.public_identifier' => ['nullable', 'string', 'max:191'],
            'leads.*.full_name' => ['nullable', 'string', 'max:191'],
            'leads.*.headline' => ['nullable', 'string', 'max:255'],
            'leads.*.company_name' => ['nullable', 'string', 'max:191'],
            'leads.*.location' => ['nullable', 'string', 'max:191'],
            'leads.*.email' => ['nullable', 'string', 'max:191'],
        ]);

        $persist = (bool) ($data['persist_results'] ?? true);
        $elements = [];

        if (!empty($data['leads']) && is_array($data['leads'])) {
            $elements = $data['leads'];
        } else {
            $providerKey = $this->providerManager->defaultProvider();
            $providerResult = $this->providerManager->search($providerKey)->searchPeople([
                'keywords' => $data['keywords'] ?? null,
                'limit' => 100,
            ], [
                'owner_id' => (string) $user->id,
                'organization_id' => $organizationId,
                'source' => 'sales_navigator',
                'source_external_id' => $data['source_external_id'],
            ]);

            $elements = Arr::get($providerResult, 'items', Arr::get($providerResult, 'data.items', []));
            if (!is_array($elements)) {
                $elements = [];
            }
        }

        $stored = 0;
        foreach ($elements as $item) {
            if (!is_array($item)) {
                continue;
            }

            $profileId = (string) Arr::get($item, 'id', '');
            if ($profileId === '') {
                continue;
            }

            if ($persist) {
                $lead = V2Lead::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'provider' => 'linkedin',
                        'provider_profile_id' => $profileId,
                    ],
                    [
                        'public_identifier' => Arr::get($item, 'public_identifier'),
                        'full_name' => Arr::get($item, 'full_name', Arr::get($item, 'name')),
                        'headline' => Arr::get($item, 'headline'),
                        'company_name' => Arr::get($item, 'company_name'),
                        'location' => Arr::get($item, 'location'),
                        'email' => Arr::get($item, 'email'),
                        'profile_data' => $item,
                    ]
                );

                V2LeadSource::query()->updateOrCreate(
                    [
                        'lead_id' => $lead->id,
                        'source_type' => 'sales_navigator',
                        'source_external_id' => (string) $data['source_external_id'],
                    ],
                    [
                        'source_payload' => [
                            'source_name' => $data['source_name'] ?? null,
                            'keywords' => $data['keywords'] ?? null,
                            'imported_at' => now()->toIso8601String(),
                        ],
                    ]
                );

                $this->leadPipeline->syncV2LeadToSnList(
                    $user,
                    $lead,
                    (string) $data['source_external_id'],
                    $data['source_name'] ?? null
                );

                $stored++;
            }
        }

        return response()->json([
            'data' => [
                'source_external_id' => $data['source_external_id'],
                'source_name' => $data['source_name'] ?? null,
                'received' => count($elements),
                'stored' => $stored,
            ],
            'meta' => [
                'organization_id' => $organizationId,
                'source_type' => 'sales_navigator',
            ],
        ], 201);
    }
}
