<?php

namespace App\V2\Ai\Services;

use App\Models\User;

/**
 * Clean, deterministic reply when background discovery workflow starts — no LLM URL rambling.
 */
class WorkflowDiscoveryAckService
{
    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly WorkspaceContextService $workspace,
        private readonly DiscoveryPlatformResolver $platformResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     */
    public function build(User $user, int $organizationId, array $plan): string
    {
        $requested = (int) (
            $plan['state_evaluation']['requested_quantity']
            ?? $plan['measurable_expectations']['target_count']
            ?? $plan['constraints']['target_count']
            ?? 0
        );

        $countPhrase = $requested > 0 ? "**{$requested} fresh prospects**" : '**prospects**';
        $criteria = trim((string) ($plan['objective']['criteria'] ?? ''));
        $multichannel = app(UserTurnIntentService::class)->wantsMultichannelDiscovery($criteria);
        $platform = $this->platformResolver->resolve($plan);
        if ($platform === 'auto' && ! $multichannel) {
            $channels = app(PlatformAllocationService::class)->searchableChannels($user);
            $platform = $channels[0] ?? 'linkedin';
        }
        $searchLine = match (true) {
            $multichannel => 'Searching your **connected platforms** now',
            $platform === 'instagram' => 'Searching **Instagram** now',
            $platform === 'linkedin' => 'Searching **LinkedIn** now',
            default => 'Searching for prospects now',
        };

        $outcome = (string) ($plan['required_outcome'] ?? '');
        $lines = [
            $outcome === 'setup_only'
                ? "I'm finding {$countPhrase} and staging a campaign for Review & Launch. {$searchLine} — nothing sends until you tap Launch."
                : "I'm finding {$countPhrase} for you. {$searchLine} — discovery runs in the background and I'll update you here when the list is saved.",
        ];

        $settings = $this->settingsService->for($user, $organizationId);
        $summary = trim((string) ($this->workspace->businessProfile($settings)['summary'] ?? ''));
        if ($summary !== '') {
            $lines[] = 'Using your business profile to guide the search: '.$summary.'.';
        }

        return implode("\n\n", $lines);
    }
}
