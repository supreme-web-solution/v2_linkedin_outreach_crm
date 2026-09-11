<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2Campaign;
use App\Models\V2OutreachCampaign;

class PostExecutionVerifierService
{
    /**
     * @return array{ok:bool,warnings:list<string>,checks:array<string,mixed>}
     */
    public function verify(User $user, int $organizationId, array $plan, array $before, array $after): array
    {
        $warnings = [];
        $outcome = (string) ($plan['required_outcome'] ?? '');

        if ($outcome === 'find_only') {
            if (($after['outreach_campaigns'] ?? 0) > ($before['outreach_campaigns'] ?? 0)
                || ($after['linkedin_campaigns'] ?? 0) > ($before['linkedin_campaigns'] ?? 0)) {
                $warnings[] = 'Discovery-only request created campaign records unexpectedly.';
            }
        }

        if ($outcome === 'setup_only') {
            if (($after['external_activity_count'] ?? 0) > ($before['external_activity_count'] ?? 0)) {
                $warnings[] = 'Setup-only request triggered external send activity.';
            }
        }

        return [
            'ok' => $warnings === [],
            'warnings' => $warnings,
            'checks' => [
                'outcome' => $outcome,
                'before' => $before,
                'after' => $after,
            ],
        ];
    }

    /**
     * @return array<string,int>
     */
    public function snapshot(User $user, int $organizationId): array
    {
        $externalActions = ['campaign_activated', 'inbox_reply_sent', 'meeting_booked', 'campaign_paused'];
        $rows = app(AiActivityLogService::class)->queryForUser(
            $user,
            $organizationId,
            null,
            null,
            null,
            null,
            300
        );

        return [
            'outreach_campaigns' => V2OutreachCampaign::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->count(),
            'linkedin_campaigns' => V2Campaign::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->count(),
            'external_activity_count' => $rows !== [] ? count(array_filter(
                $rows,
                fn (array $row) => in_array((string) ($row['action'] ?? ''), $externalActions, true)
            )) : 0,
        ];
    }
}
