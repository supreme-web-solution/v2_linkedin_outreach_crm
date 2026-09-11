<?php

namespace App\V2\Ai\Services;

class IntentGoalResolverService
{
    /**
     * @return array{
     *   goal:string,
     *   required_outcome:string,
     *   side_effect_budget:string,
     *   constraints:array<string,mixed>
     * }
     */
    public function resolve(string $message): array
    {
        $intent = app(UserTurnIntentService::class);
        $count = app(DiscoverProspectsService::class)->inferCountFromQuery($message);

        if ($intent->isInformational($message)) {
            return [
                'goal' => 'reporting',
                'required_outcome' => 'status_only',
                'side_effect_budget' => 'read_only',
                'constraints' => ['target_count' => $count],
            ];
        }

        if ($intent->wantsCampaignSetupOnly($message)) {
            return [
                'goal' => 'outreach',
                'required_outcome' => 'setup_only',
                'side_effect_budget' => 'prepare_only',
                'constraints' => [
                    'target_count' => $count,
                    'reuse_first' => true,
                ],
            ];
        }

        if ($intent->isOutreachCommand($message)) {
            return [
                'goal' => 'outreach',
                'required_outcome' => 'send_now',
                'side_effect_budget' => 'external_send_allowed',
                'constraints' => [
                    'target_count' => $count,
                    'reuse_first' => true,
                    'instagram_requested' => $intent->wantsInstagramDiscovery($message),
                ],
            ];
        }

        if ($intent->isDiscoveryOnly($message) || $intent->isProspectDiscoveryRequest($message)) {
            return [
                'goal' => 'discovery',
                'required_outcome' => 'find_only',
                'side_effect_budget' => 'read_only',
                'constraints' => [
                    'target_count' => $count,
                    'new_only' => $intent->wantsFreshProspectPull($message),
                    'instagram_requested' => $intent->wantsInstagramDiscovery($message),
                ],
            ];
        }

        return [
            'goal' => 'management',
            'required_outcome' => 'general_assist',
            'side_effect_budget' => 'mutate_allowed',
            'constraints' => ['target_count' => $count],
        ];
    }
}
