<?php

namespace App\V2\Ai\Services;

/**
 * Resolves discover_prospects platform from turn plan + user message intent.
 * No platform is preferred unless the plan or user message names one — otherwise auto (connected search).
 */
class DiscoveryPlatformResolver
{
    public function __construct(
        private readonly UserTurnIntentService $intent,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $arguments
     */
    public function resolve(
        array $plan,
        array $arguments = [],
        ?string $query = null,
        bool $wantsOutreach = false,
    ): string {
        unset($wantsOutreach);

        $explicit = strtolower(trim((string) ($arguments['platform'] ?? '')));
        if ($explicit !== '' && $explicit !== 'auto') {
            return $this->normalizePlatform($explicit) ?? $explicit;
        }

        foreach ($this->planChannelHints($plan) as $channel) {
            $normalized = $this->normalizePlatform($channel);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $message = trim((string) ($plan['objective']['criteria'] ?? ''));
        $intentSource = $message !== '' ? $message : trim((string) ($query ?? ''));

        if ($intentSource !== '') {
            if ($this->intent->wantsMultichannelDiscovery($intentSource)) {
                return 'auto';
            }

            $fromMessage = $this->intent->explicitDiscoveryChannel($intentSource);
            if ($fromMessage !== null) {
                return $fromMessage;
            }
        }

        return 'auto';
    }

    private function normalizePlatform(string $channel): ?string
    {
        $channel = strtolower(trim($channel));

        return match ($channel) {
            'ig' => 'instagram',
            'instagram', 'linkedin' => $channel,
            'all', 'parallel', 'multi', 'multichannel' => 'auto',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private function planChannelHints(array $plan): array
    {
        $hints = [];
        $constraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];
        $semantic = is_array($plan['semantic'] ?? null) ? $plan['semantic'] : [];

        foreach ([
            $constraints['preferred_channel'] ?? null,
            $semantic['preferred_channel'] ?? null,
        ] as $channel) {
            $normalized = strtolower(trim((string) $channel));
            if ($normalized !== '') {
                $hints[] = $normalized;
            }
        }

        if ((bool) ($constraints['instagram_requested'] ?? false)) {
            $hints[] = 'instagram';
        }

        return $hints;
    }
}
