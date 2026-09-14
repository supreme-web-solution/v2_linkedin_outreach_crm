<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Support\PlanLeadList;
use App\V2\Outreach\OutreachChannelRegistry;

/**
 * Execute a mixed-channel plan the way the card described it:
 * search each named discovery platform, one campaign per list,
 * email enrichment only on the LinkedIn side when the plan asked for email.
 */
class MultiChannelPlanLaunchService
{
    public function __construct(
        private readonly PlanChannelIntentService $intent,
        private readonly DiscoverProspectsService $discover,
        private readonly PlatformAllocationService $platforms,
        private readonly WorkspaceContextService $workspace,
        private readonly CampaignDraftFromPlanService $campaignDrafts,
        private readonly OutreachCampaignCommandService $campaignCommands,
        private readonly ActionApprovalService $approvals,
        private readonly SingleChannelOutreachService $singleChannel,
        private readonly AiEmployeeSettingsService $settings,
    ) {}

    /**
     * @return array{lines: list<string>, campaign_ids: list<int>}
     */
    public function launch(AiActionApproval $approval, User $user): array
    {
        $payload = is_array($approval->payload) ? $approval->payload : [];
        if (\App\V2\Ai\Support\SingleRecipientTurnGuard::matches($payload)
            || ! empty($payload['one_shot'])
            || strtolower(trim((string) ($payload['source'] ?? ''))) === 'cold_outbound'
        ) {
            throw new \RuntimeException(
                'Single-recipient / cold outbound plans cannot fan out into multi-channel discovery on Launch.'
            );
        }

        $organizationId = (int) $approval->organization_id;
        $goal = (string) ($payload['goal'] ?? 'First conversations');
        $target = max(1, min(100, (int) ($payload['target_count'] ?? 40)));
        $wanted = $this->intent->discoveryChannels($payload);
        $searchable = array_values(array_intersect($wanted, $this->platforms->searchableChannels($user)));
        $includeEmail = $this->intent->wantsEmail($payload);

        if ($searchable === []) {
            throw new MissingProspectAudienceException(
                'None of the plan channels can be searched yet.',
                ['Connect LinkedIn and/or Instagram on Integrations, then Launch again.'],
            );
        }

        $allocation = $this->platforms->split($target, $searchable);
        $settings = $this->settings->for($user, $organizationId);
        $icp = $this->workspace->storedIcp($settings);
        $hints = $this->workspace->discoverySearchHints($user, $organizationId, [
            'objective' => ['segment' => (string) ($payload['audience'] ?? '')],
        ]);

        $lists = [];
        $notes = [];
        foreach ($allocation as $channel => $count) {
            $query = $channel === 'instagram'
                ? $this->intent->instagramKeyword($payload, $icp)
                : (string) ($hints['query'] !== '' ? $hints['query'] : ($payload['audience'] ?? $goal));
            $title = $channel === 'linkedin' ? $hints['title'] : null;

            $result = $this->discover->discover(
                $user,
                $query,
                null,
                20,
                $count,
                true,
                $hints['geography'],
                null,
                $title,
                null,
                null,
                null,
                $channel,
            );

            $best = is_array($result['best_match'] ?? null) ? $result['best_match'] : null;
            $hash = trim((string) ($best['list_hash'] ?? ''));
            if ($hash === '') {
                $reason = (string) ($result['failure_reason'] ?? $channel.' search returned no people.');
                $notes[] = OutreachChannelRegistry::channelLabel($channel).': '.$reason;
                continue;
            }

            $src = trim((string) ($best['list_src'] ?? $this->intent->defaultListSrc($channel)));
            $live = app(ProspectAudienceResolverService::class)->liveLeadCount($user, $src, $hash);
            if ($live < 1) {
                $notes[] = OutreachChannelRegistry::channelLabel($channel)
                    .': search claimed people, but the list is empty. No campaign without people.';
                continue;
            }

            $lists[] = array_merge($best, [
                'primary_channel' => $channel,
                'platform' => $channel,
                'list_src' => $src,
                'total_leads' => $live,
            ]);
        }

        if ($lists === []) {
            throw new MissingProspectAudienceException(
                'I could not build a prospect list for this plan.',
                $notes !== [] ? $notes : ['Say Launch again after LinkedIn / Instagram search is working.'],
            );
        }

        $conversation = $approval->conversation;
        $lines = [
            "Launched plan #{$approval->id}: {$goal}",
            'Execution follows the plan — one search and one campaign per named channel.',
        ];
        $campaignIds = [];
        $first = true;

        foreach ($lists as $list) {
            $channel = (string) ($list['primary_channel'] ?? $list['platform'] ?? 'linkedin');
            $channelPayload = $this->payloadForChannel($payload, $list, $channel, $includeEmail);

            try {
                if ($first) {
                    $approval->update(['payload' => $channelPayload]);
                    $created = $this->campaignDrafts->createFromApproval($approval->fresh() ?? $approval, $user);
                    $first = false;
                } else {
                    $child = $this->approvals->createPendingWithoutSupersede(
                        $user,
                        $organizationId,
                        'draft_campaign_plan',
                        AiToolPermission::Prepare,
                        $channelPayload,
                        $conversation,
                        $approval->workflow_run_id,
                    );
                    $created = $this->campaignDrafts->createFromApproval($child, $user);
                }
            } catch (MissingProspectAudienceException $e) {
                $notes[] = OutreachChannelRegistry::channelLabel($channel).': '.$e->getMessage();
                continue;
            }

            $campaign = $created['campaign'];
            $activate = $this->campaignCommands->activate($user, $organizationId, $campaign->id);
            $label = OutreachChannelRegistry::channelLabel($channel);
            $lines[] = $label.' campaign #'.$campaign->id
                .' ('.(int) ($list['total_leads'] ?? 0).' prospects) — '
                .($activate['ok'] ? $activate['message'] : $activate['message']);
            $lines[] = $created['url'];
            $campaignIds[] = $campaign->id;
        }

        foreach ($notes as $note) {
            $lines[] = $note;
        }

        if ($includeEmail && in_array('linkedin', array_column($lists, 'primary_channel'), true)) {
            $lines[] = 'Email: enrichment runs on the LinkedIn list in waves of 25 (the daily gate). Email is a follow-up after the conversation opener — not a second blast.';
        }

        $missingSearch = array_diff($wanted, $searchable);
        foreach ($missingSearch as $channel) {
            $lines[] = ucfirst($channel).' was on the plan but is not searchable right now (connect it, or configure Instagram search).';
        }

        return [
            'lines' => $lines,
            'campaign_ids' => $campaignIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $list
     * @return array<string, mixed>
     */
    private function payloadForChannel(array $payload, array $list, string $channel, bool $includeEmail): array
    {
        $label = OutreachChannelRegistry::channelLabel($channel);
        $count = max(1, (int) ($list['total_leads'] ?? $payload['target_count'] ?? 1));
        $next = $this->singleChannel->applyPrimaryChannel(array_merge($payload, [
            'primary_channel' => $channel,
            'include_email' => $includeEmail && $channel === 'linkedin',
            'single_channel_only' => ! ($includeEmail && $channel === 'linkedin'),
            'target_count' => $count,
            'campaign_name' => ($payload['campaign_name'] ?? 'First conversations').' · '.$label,
            'sequence' => $this->sequenceForChannel($channel, $includeEmail && $channel === 'linkedin'),
            'steps' => $this->stepsForChannel($channel, $includeEmail && $channel === 'linkedin'),
        ]));

        return PlanLeadList::merge(
            $next,
            (string) $list['list_hash'],
            (string) ($list['list_src'] ?? $this->intent->defaultListSrc($channel)),
            (string) ($list['list_name'] ?? $label.' prospects'),
        );
    }

    /**
     * @return list<string>
     */
    private function sequenceForChannel(string $channel, bool $emailFollowUp): array
    {
        if ($channel === 'instagram') {
            return [
                'Instagram DM — personalized after research, no pitch',
                'Wait 4 days',
                'Light follow-up if no reply',
                'Pause on reply — handle in inbox',
            ];
        }

        $sequence = [
            'Send Invite (empty note)',
            'After acceptance',
            'Diagnostic / first message — earn a reply',
            'Wait 3 days',
            'Value follow-up',
        ];

        if ($emailFollowUp) {
            $sequence[] = 'Email follow-up if still no reply';
        }

        $sequence[] = 'Pause on reply — handle in inbox';

        return $sequence;
    }

    /**
     * @return list<string>
     */
    private function stepsForChannel(string $channel, bool $emailFollowUp): array
    {
        $steps = $channel === 'instagram'
            ? [
                'Find people matching this ICP on Instagram',
                'Conversation-first opener (no sales page, webinar, or calendar)',
                'Pause on reply — Soci qualifies, then page/webinar, then meeting',
            ]
            : [
                'Find people matching this ICP on LinkedIn',
                'Blank invite, then conversation-first opener after accept',
                'Pause on reply — Soci qualifies, then page/webinar, then meeting',
            ];

        if ($emailFollowUp) {
            $steps[] = 'Enrich emails in 25-person waves, then email only as a later follow-up';
        }

        return $steps;
    }
}
