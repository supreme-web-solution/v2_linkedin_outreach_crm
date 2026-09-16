<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Arr;

/**
 * After onboarding, stage one 40-prospect conversation-first experiment.
 * Owner only has to Launch — no second "find customers" prompt required.
 */
class FirstExperimentOnboardingService
{
    public const TARGET_COUNT = 40;

    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly WorkspaceContextService $workspace,
        private readonly CommandCenterService $commandCenter,
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterPushService $push,
        private readonly AcquisitionExperimentService $experiments,
        private readonly PlatformAllocationService $platforms,
        private readonly PlanFunnelService $funnel,
    ) {}

    /**
     * @return array{staged: bool, approval_id: int|null, blocked_reason: string|null}
     */
    public function stageAfterComplete(User $user, int $organizationId): array
    {
        $settings = $this->settingsService->for($user, $organizationId);
        $meta = is_array($settings->meta) ? $settings->meta : [];
        if (! empty($meta['first_experiment_staged_at'])) {
            return ['staged' => false, 'approval_id' => null, 'blocked_reason' => 'already_staged'];
        }

        $searchable = $this->platforms->searchableChannels($user);
        $discoveryChannels = $searchable !== [] ? $searchable : ['linkedin'];
        $icp = $this->workspace->storedIcp($settings);
        $profile = $this->workspace->businessProfile($settings);
        $niche = trim((string) (Arr::get($icp, 'industry') ?: Arr::get($profile, 'summary') ?: 'ideal customers'));
        $audience = $this->audienceLine($icp, $profile);
        $channels = $this->channelLabel($searchable);

        $this->experiments->startExperiment(
            $user,
            $organizationId,
            $niche !== '' ? $niche : 'First conversations',
            self::TARGET_COUNT,
            'acquisition',
        );

        $plan = [
            'type' => 'campaign',
            'goal' => 'First experiment: find ~'.self::TARGET_COUNT.' ICP prospects and start conversation-first outreach.',
            'campaign_name' => 'First '.self::TARGET_COUNT.' conversations',
            'audience' => $audience,
            'icp_notes' => $audience,
            'target_count' => self::TARGET_COUNT,
            'preferred_channels' => $channels,
            'channels' => $channels,
            'discovery_channels' => $discoveryChannels,
            'include_email' => true,
            'follow_up_days' => 14,
            'source' => 'Onboarding ICP',
            'prefer_fresh_audience' => true,
            'pause_on_reply' => true,
            'auto_reply_enabled' => false,
            'first_experiment' => true,
            'sequence' => [
                'Send Invite (empty note)',
                'After acceptance',
                'Diagnostic / first message — earn a reply',
                'Wait 3 days',
                'Value follow-up',
                'Pause on reply — handle in inbox',
            ],
            'steps' => [
                'Find ~'.self::TARGET_COUNT.' people matching your ICP',
                'Conversation-first opener (no sales page, webinar, or calendar)',
                'Pause on reply — Soci qualifies, then page/webinar, then meeting',
            ],
            'status' => 'awaiting_review',
        ];

        $plan = $this->funnel->attachToPlan($plan, $user);

        $conversation = $this->commandCenter->conversation($user, $organizationId);
        $approval = $this->approvals->createPendingWithoutSupersede(
            $user,
            $organizationId,
            'draft_campaign_plan',
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        $meta = is_array($settings->fresh()?->meta) ? $settings->fresh()->meta : $meta;
        $meta['first_experiment_staged_at'] = now()->toIso8601String();
        $meta['first_experiment_approval_id'] = $approval->id;
        $settings->update(['meta' => $meta]);

        $blocked = $searchable === [] ? 'no_searchable_channel' : null;
        $card = $this->commandCenter->formatPlanCard($plan, $approval->id, 'web');
        $intro = $blocked === 'no_searchable_channel'
            ? "You're set. I staged your first **".self::TARGET_COUNT."-person** experiment — but I cannot find people until a **search platform** is connected (LinkedIn, Instagram, …).\n\n"
                .url('/integrations')."\n\nConnect one, then Launch plan #{$approval->id}."
            : "You're set. I staged your first **".self::TARGET_COUNT."-person** conversation experiment from your ICP. Review & Launch when you are ready — no extra prompt needed.";

        $this->push->postAssistant(
            $user,
            $organizationId,
            $intro."\n\n".$card,
            ['source' => 'first_experiment', 'first_experiment' => true],
            $approval->id,
        );

        return [
            'staged' => true,
            'approval_id' => $approval->id,
            'blocked_reason' => $blocked,
        ];
    }

    /**
     * @param  array<string, mixed>  $icp
     * @param  array<string, mixed>  $profile
     */
    private function audienceLine(array $icp, array $profile): string
    {
        $parts = array_filter([
            Arr::get($icp, 'search_query'),
            Arr::get($icp, 'who_we_sell_to'),
            Arr::get($icp, 'decision_maker'),
            Arr::get($icp, 'industry'),
            Arr::get($icp, 'likely_pain') ? 'pain: '.Arr::get($icp, 'likely_pain') : null,
            Arr::get($profile, 'summary'),
        ], fn ($v) => is_string($v) && trim($v) !== '');

        return $parts !== [] ? implode(' · ', $parts) : 'ideal customers from your onboarding profile';
    }

    /**
     * @param  list<string>  $searchable
     */
    private function channelLabel(array $searchable): string
    {
        if ($searchable === []) {
            return 'LinkedIn + Email';
        }

        $labels = array_map(
            fn (string $key) => OutreachChannelRegistry::channelLabel($key),
            $searchable,
        );
        if (! in_array('email', $searchable, true)) {
            $labels[] = 'Email';
        }

        return implode(' + ', $labels);
    }
}
