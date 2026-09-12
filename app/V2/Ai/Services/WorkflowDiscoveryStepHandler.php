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
        $query = $segment !== '' ? $segment : 'prospects';

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

        $lists = is_array($result['lists'] ?? null) ? $result['lists'] : [];
        $best = is_array($result['best_match'] ?? null) ? $result['best_match'] : null;
        if ($best === null && $lists !== []) {
            $best = collect($lists)->sortByDesc(fn (array $row) => (int) ($row['total_leads'] ?? 0))->first();
        }
        $saved = (int) ($result['total_leads_in_matches'] ?? $best['total_leads'] ?? 0);
        $samples = is_array($result['sample_profiles'] ?? null) ? $result['sample_profiles'] : [];
        if ($samples === [] && is_array($best['sample_profiles'] ?? null)) {
            $samples = $best['sample_profiles'];
        }

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
            'discovery_lists' => $lists,
            'discovery_result' => [
                'auto_sourced' => (bool) ($result['auto_sourced'] ?? false),
                'search_failed' => (bool) ($result['search_failed'] ?? false),
                'mode' => $result['mode'] ?? null,
            ],
        ];
    }
}
