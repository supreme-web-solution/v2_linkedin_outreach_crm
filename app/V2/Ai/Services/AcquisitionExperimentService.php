<?php

namespace App\V2\Ai\Services;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Tracks the SociFusion-on-SociFusion validation experiment (or any niche test).
 */
class AcquisitionExperimentService
{
    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly \App\V2\Services\AcquisitionFunnelService $funnel,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function activeExperiment(User $user, int $organizationId): ?array
    {
        $settings = $this->settingsService->for($user, $organizationId);
        $meta = is_array($settings->meta) ? $settings->meta : [];
        $experiment = is_array($meta['acquisition_experiment'] ?? null) ? $meta['acquisition_experiment'] : null;

        if ($experiment === null || empty($experiment['active'])) {
            return null;
        }

        $funnel = $this->funnel->forUser($user, (string) ($experiment['period'] ?? 'all'));

        return array_merge($experiment, [
            'funnel' => $funnel,
            'progress_pct' => $this->progressPercent($funnel, $experiment),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function startExperiment(
        User $user,
        int $organizationId,
        string $niche,
        int $targetProspects = 500,
        ?string $messageAngle = null,
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);
        $meta = is_array($settings->meta) ? $settings->meta : [];

        $meta['acquisition_experiment'] = [
            'active' => true,
            'niche' => trim($niche),
            'target_prospects' => max(50, min(2000, $targetProspects)),
            'message_angle' => $messageAngle ? trim($messageAngle) : null,
            'started_at' => Carbon::now()->toIso8601String(),
            'period' => 'all',
            'goal' => 'Measure qualified conversations per 100 targeted prospects — not message volume.',
        ];

        $settings->update(['meta' => $meta]);

        return $meta['acquisition_experiment'];
    }

    /**
     * @param  array<string, mixed>  $funnel
     * @param  array<string, mixed>  $experiment
     */
    private function progressPercent(array $funnel, array $experiment): float
    {
        $target = max(1, (int) ($experiment['target_prospects'] ?? 500));
        $current = (int) ($funnel['summary']['targeted'] ?? 0);

        return round(min(100, ($current / $target) * 100), 1);
    }
}
