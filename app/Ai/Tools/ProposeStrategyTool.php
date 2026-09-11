<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ActionApprovalService;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\DiscoverProspectsService;
use App\V2\Ai\Services\MultiChannelCampaignStagingService;
use App\V2\Ai\Services\PlanContentService;
use App\V2\Ai\Support\PlanLeadList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProposeStrategyTool extends GatedTool
{
    public function toolName(): string
    {
        return 'propose_strategy';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Draft an outreach campaign plan for Review & Launch. ONLY when the user explicitly wants outreach/messaging/campaigns. '
            .'Never call for find/save-only requests ("find prospect details", "get me leads") — use discover_prospects instead.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()->required(),
            'geography' => $schema->string()->nullable(),
            'icp_notes' => $schema->string()->nullable(),
            'preferred_channels' => $schema->string()->nullable()->description('Default: LinkedIn + Email. Set Instagram, Telegram, or WhatsApp as primary when user asks for that channel.'),
            'target_count' => $schema->integer()->min(1)->nullable(),
            'follow_up_days' => $schema->integer()->min(1)->max(90)->nullable(),
            'list_hash' => $schema->string()->nullable()->description('Lead list id from find_prospects / discover_prospects'),
            'list_src' => $schema->string()->enum(['aud', 'sn', 'csv'])->nullable(),
            'list_name' => $schema->string()->nullable(),
            'one_shot' => $schema->boolean()->nullable()->description('true for a single greeting/email/DM — no multi-day waits.'),
            'message' => $schema->string()->nullable()->description('Exact message body when one_shot.'),
            'profile_url' => $schema->string()->nullable()->description('Exact LinkedIn profile URL for a one-person send.'),
        ];
    }

    protected function run(Request $request): array
    {
        $ledger = app(\App\V2\Ai\Services\TurnExecutionLedger::class);
        if ($ledger->ownsTurnResult()) {
            return [
                'blocked' => true,
                'already_executed' => true,
                'report' => $ledger->report(),
                'instruction' => 'This request is already executed across platforms. Do not propose another plan. The user reply is the execution report.',
            ];
        }

        $explicitTarget = array_key_exists('target_count', $request->all());
        $hasList = trim((string) ($request['list_hash'] ?? '')) !== '';
        $goal = (string) $request['goal'];
        $userMessage = $this->latestUserMessage();
        $intentSource = $userMessage !== '' ? $userMessage : $goal;
        $oneShot = (bool) ($request['one_shot'] ?? false)
            || (bool) preg_match('/one[-\s]?time|one[-\s]?shot|greeting|just (a )?message|single (email|message)|message (him|her|them)/i', $goal);
        $profileUrl = trim((string) ($request['profile_url'] ?? ''));
        if ($profileUrl === '' && preg_match('#https?://(?:www\.)?linkedin\.com/in/[\w%-]+/?#i', $goal, $m)) {
            $profileUrl = $m[0];
            $oneShot = true;
        }

        $intent = app(\App\V2\Ai\Services\UserTurnIntentService::class);
        if ($intent->isDiscoveryOnly($intentSource)) {
            return [
                'blocked' => true,
                'discovery_only' => true,
                'instruction' => 'User asked to find/save prospects only. Do NOT draft a campaign. Call discover_prospects instead, or if discovery already ran, reply with the saved leads report.',
            ];
        }
        if (! $oneShot && ! $hasList && $this->isDirectAcquisitionGoal($goal)
            && ! $intent->isInformational($intentSource)
            && $intent->isOutreachCommand($intentSource)) {
            $userCount = app(DiscoverProspectsService::class)->inferCountFromQuery($intentSource);
            $discovery = app(DiscoverProspectsService::class)->discover(
                user: $this->context->user,
                query: $goal,
                targetCount: $userCount ?? (isset($request['target_count']) ? (int) $request['target_count'] : null),
                preferFresh: $explicitTarget || $userCount !== null,
                platform: $intent->wantsInstagramDiscovery($intentSource) ? 'auto' : 'linkedin',
            );

            if (($discovery['mode'] ?? '') === 'parallel' && ! empty($discovery['lists'])) {
                $staged = app(MultiChannelCampaignStagingService::class)->stage(
                    $this->context->user,
                    $this->context->organizationId,
                    $goal,
                    is_array($discovery['lists']) ? $discovery['lists'] : [],
                    $this->context->conversation,
                );

                if ($staged !== []) {
                    $ledger->recordOutreach(
                        $goal,
                        is_array($discovery['allocation'] ?? null) ? $discovery['allocation'] : [],
                        is_array($discovery['channel_results'] ?? null) ? $discovery['channel_results'] : [],
                        $staged,
                    );

                    return [
                        'executed' => true,
                        'staged_campaigns' => $staged,
                        'execution_report' => $ledger->report(),
                        'instruction' => 'Execution is complete for this turn. Reply with the execution report only; do not propose another strategy.',
                    ];
                }
            }
        }

        $plan = [
            'type' => 'strategy',
            'goal' => $goal,
            'geography' => $request['geography'] ?? null,
            'icp_notes' => $request['icp_notes'] ?? null,
            'preferred_channels' => $request['preferred_channels'] ?? app(\App\V2\Ai\Services\AiChannelPolicyService::class)->defaultChannelsLabel(),
            'target_count' => $oneShot ? 1 : ($request['target_count'] ?? 500),
            'follow_up_days' => $oneShot ? 1 : ($request['follow_up_days'] ?? 21),
            'prefer_fresh_audience' => ($explicitTarget && ! $hasList) || ($oneShot && $profileUrl !== ''),
            'pause_on_reply' => true,
            'one_shot' => $oneShot,
            'one_time' => $oneShot,
            'message' => trim((string) ($request['message'] ?? '')),
            'profile_url' => $profileUrl,
            'linkedin_url' => $profileUrl,
            'steps' => $oneShot
                ? [
                    'Confirm the exact recipient',
                    'Send one message only (no Wait N days / follow-ups)',
                    'Deliver via outreach queue for limits + tracking',
                ]
                : [
                    'Clarify / confirm ICP',
                    'Find matching companies and decision makers',
                    'Enrich contact information',
                    'Draft personalized outreach campaign',
                    'Follow up until reply; pause automation on reply (Soci replies in inbox)',
                    'Qualify and book meetings',
                ],
            'status' => 'awaiting_review',
        ];

        $plan = app(PlanContentService::class)->enrichStrategy($plan);
        if (! ($oneShot && $profileUrl !== '')) {
            $plan = PlanLeadList::merge(
                $plan,
                $request['list_hash'] ?? null,
                $request['list_src'] ?? null,
                $request['list_name'] ?? null,
            );
        }
        $plan = app(\App\V2\Ai\Services\ProspectAudienceResolverService::class)
            ->enrichPlanWithAudience($this->context->user, $plan);
        $plan = app(\App\V2\Ai\Services\PlanFunnelService::class)
            ->attachToPlan($plan, $this->context->user);

        return $this->stagePlan($plan);
    }

    private function isDirectAcquisitionGoal(string $goal): bool
    {
        return (bool) preg_match('/\b(get|find|acquire|bring)\b.{0,40}\b(client|customer|prospect|lead)s?\b/i', $goal);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function stagePlan(array $plan): array
    {
        $formatter = app(CommandCenterService::class);

        if ($this->context->autonomy()->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval_id' => null,
                'plan' => $plan,
                'card' => $formatter->formatPlanCard($plan, null, $this->context->channel),
                'cta' => 'Copilot mode: recommendation only.',
            ];
        }

        $approval = app(ActionApprovalService::class)->createPending(
            $this->context->user,
            $this->context->organizationId,
            $this->toolName(),
            $this->permission(),
            $plan,
            $this->context->conversation,
        );

        return [
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $formatter->formatPlanCard($plan, $approval->id, $this->context->channel),
            'cta' => 'Ask the user to Review & Launch (LAUNCH '.$approval->id.' on WhatsApp).',
        ];
    }

    private function latestUserMessage(): string
    {
        $conversation = $this->context->conversation;
        if (! $conversation?->id) {
            return '';
        }

        $message = \App\Models\AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->value('content');

        return trim((string) $message);
    }
}
