<?php

namespace App\V2\Ai\Services;

use App\Models\User;

/**
 * Executes discovery steps on behalf of the workflow runtime.
 * Wraps DiscoverProspectsService — tools do not self-orchestrate.
 */
class WorkflowDiscoveryStepHandler
{
    public function __construct(
        private readonly DiscoverProspectsService $discovery,
        private readonly DiscoveryPlatformResolver $platformResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(User $user, array $plan, array $arguments): array
    {
        $targetCount = max(1, min(100, (int) ($arguments['target_count'] ?? 1)));
        $segment = trim((string) ($arguments['segment'] ?? $plan['objective']['segment'] ?? 'prospects'));
        $criteria = trim((string) ($plan['objective']['criteria'] ?? ''));
        $orgId = (int) ($user->current_organization_id ?? 0);
        $rawQuery = $criteria !== '' ? $criteria : ($segment !== '' ? $segment : 'prospects');
        $query = app(WorkspaceContextService::class)->enrichDiscoveryQuery($user, $orgId, $rawQuery);
        $query = $this->normalizeDiscoveryQuery($user, $orgId, $query);

        $preferFresh = (bool) ($arguments['new_only'] ?? $plan['constraints']['new_only'] ?? false);
        $platform = $this->platformResolver->resolve($plan, $arguments, $query);

        $result = $this->discovery->discover(
            user: $user,
            query: $query,
            limit: min(20, $targetCount),
            targetCount: $targetCount,
            preferFresh: $preferFresh,
            platform: $platform,
        );

        if (($result['mode'] ?? '') === 'parallel') {
            return $this->buildParallelStepResult($result, $targetCount, $platform);
        }

        $lists = is_array($result['lists'] ?? null) ? $result['lists'] : [];
        $best = $this->freshBestMatch($result, $lists);
        $saved = $this->freshSavedCount($result, $best);
        $samples = $this->collectSamples($result, $best);

        return [
            'step_type' => 'discover',
            'requested_count' => $targetCount,
            'provider_returned' => $saved,
            'saved_reported' => $saved,
            'platform' => $result['platform'] ?? $platform,
            'platforms_searched' => $result['platforms_searched'] ?? [$platform],
            'list_hash' => $best['list_hash'] ?? null,
            'list_src' => $best['list_src'] ?? null,
            'list_name' => $best['list_name'] ?? null,
            'sample_profiles' => $samples,
            'discovery_lists' => $best !== null ? [$best] : [],
            'discovery_result' => [
                'auto_sourced' => (bool) ($result['auto_sourced'] ?? false),
                'search_failed' => (bool) ($result['search_failed'] ?? false),
                'mode' => $result['mode'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function buildParallelStepResult(array $result, int $targetCount, string $platform): array
    {
        $lists = [];
        $saved = 0;
        $samples = [];

        foreach ($result['channel_results'] ?? [] as $channel => $channelResult) {
            if (! is_array($channelResult)) {
                continue;
            }

            $best = $this->freshBestMatch($channelResult, is_array($channelResult['lists'] ?? null) ? $channelResult['lists'] : []);
            if ($best === null) {
                continue;
            }

            $lists[] = array_merge($best, ['primary_channel' => (string) $channel]);
            $saved += (int) ($best['total_leads'] ?? 0);
            $samples = array_merge($samples, $this->collectSamples($channelResult, $best));
        }

        $samples = array_slice($samples, 0, 5);

        return [
            'step_type' => 'discover',
            'requested_count' => $targetCount,
            'provider_returned' => $saved,
            'saved_reported' => $saved,
            'platform' => $platform,
            'platforms_searched' => is_array($result['platforms_searched'] ?? null)
                ? $result['platforms_searched']
                : array_keys($result['channel_results'] ?? []),
            'list_hash' => count($lists) === 1 ? ($lists[0]['list_hash'] ?? null) : null,
            'list_src' => count($lists) === 1 ? ($lists[0]['list_src'] ?? null) : null,
            'list_name' => count($lists) === 1 ? ($lists[0]['list_name'] ?? null) : null,
            'sample_profiles' => $samples,
            'discovery_lists' => $lists,
            'discovery_result' => [
                'auto_sourced' => true,
                'search_failed' => $lists === [],
                'mode' => 'parallel',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $lists
     * @return array<string, mixed>|null
     */
    private function freshBestMatch(array $result, array $lists): ?array
    {
        $best = is_array($result['best_match'] ?? null) ? $result['best_match'] : null;
        if ($best !== null && ($best['auto_sourced'] ?? false)) {
            return $best;
        }

        $freshLists = array_values(array_filter(
            $lists,
            fn (array $row) => (bool) ($row['auto_sourced'] ?? false),
        ));

        if ($freshLists === []) {
            return $best;
        }

        return collect($freshLists)->sortByDesc(fn (array $row) => (int) ($row['total_leads'] ?? 0))->first();
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $best
     */
    private function freshSavedCount(array $result, ?array $best): int
    {
        if ($best !== null && ($best['auto_sourced'] ?? false)) {
            return (int) ($best['total_leads'] ?? 0);
        }

        if (($result['auto_sourced'] ?? false) && is_array($result['best_match'] ?? null)) {
            return (int) ($result['best_match']['total_leads'] ?? 0);
        }

        return (int) ($result['total_leads_in_matches'] ?? $best['total_leads'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $best
     * @return list<array<string, mixed>>
     */
    private function collectSamples(array $result, ?array $best): array
    {
        $samples = is_array($result['sample_profiles'] ?? null) ? $result['sample_profiles'] : [];
        if ($samples === [] && is_array($best['sample_profiles'] ?? null)) {
            $samples = $best['sample_profiles'];
        }

        return is_array($samples) ? $samples : [];
    }

    private function normalizeDiscoveryQuery(User $user, int $organizationId, string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            $query = 'prospects';
        }

        if (! preg_match('/^(prospects?|customers?|clients?|leads?|my business)$/i', $query)) {
            return $query;
        }

        $settings = app(AiEmployeeSettingsService::class)->for($user, $organizationId);
        $profile = app(WorkspaceContextService::class)->businessProfile($settings);
        $summary = trim((string) ($profile['summary'] ?? ''));
        $icp = app(WorkspaceContextService::class)->storedIcp($settings);
        $icpLine = trim(collect([
            $icp['industry'] ?? null,
            $icp['decision_maker'] ?? null,
        ])->filter()->implode(' '));

        return $summary !== '' ? $summary : ($icpLine !== '' ? $icpLine : 'B2B decision makers');
    }
}
