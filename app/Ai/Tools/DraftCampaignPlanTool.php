<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ActionApprovalService;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\PlanContentService;
use App\V2\Ai\Support\PlanLeadList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class DraftCampaignPlanTool extends GatedTool
{
    public function toolName(): string
    {
        return 'draft_campaign_plan';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Draft a full outreach campaign plan (audience, channels, sequence, follow-up) for Review & Launch. Does not send messages yet.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()->required(),
            'audience' => $schema->string()->required(),
            'target_count' => $schema->integer()->min(1)->nullable(),
            'channels' => $schema->string()->nullable()->description('Default: LinkedIn + Email. When user asks: Instagram, Telegram, WhatsApp (or combos).'),
            'follow_up_days' => $schema->integer()->min(1)->max(90)->nullable(),
            'source' => $schema->string()->nullable()->description('e.g. competitor audiences, LinkedIn search'),
            'pause_on_reply' => $schema->boolean()->nullable()->description('Default true. When a prospect replies, pause the sequence so Alex/human can reply in inbox chat context.'),
            'auto_reply_enabled' => $schema->boolean()->nullable()->description('Default false. Only enable when the user wants campaign auto-replies from AI context without waiting for Alex.'),
            'ai_context' => $schema->string()->nullable()->description('Short product/offer context for inbox AI replies on this campaign.'),
            'sequence' => $schema->array()->nullable()->description(
                'Ordered prose steps chosen for THIS goal. LinkedIn example: '
                .'["Send Invite (empty note)","After acceptance","Diagnostic LinkedIn message","Wait 3 days","Value follow-up","Pause on reply — handle in inbox"]. '
                .'Add has_replied/no_reply only when the graph must branch. Launch builds invite_accepted + pause_on_reply.',
            ),
            'sequence_steps' => $schema->array()->nullable()->description(
                'Optional structured steps. Actions: linkedin=visit_profile|send_invite|send_message|like_post|endorse; '
                .'email=send_email; whatsapp/instagram/telegram/twitter=send_message. Never use connect — use send_invite. '
                .'Conditions (type=condition): linkedin invite_accepted|has_replied|no_reply; email email_replied|no_reply|email_opened|email_bounced; '
                .'messaging message_replied|no_reply. Use branches.accepted / branches.not_accepted. '
                .'Prefer invite_accepted after send_invite; prefer pause_on_reply over stuffing reply handling into the graph.',
            ),
            'list_hash' => $schema->string()->nullable(),
            'list_src' => $schema->string()->enum(['aud', 'sn', 'csv'])->nullable(),
            'list_name' => $schema->string()->nullable(),
            'network_degree' => $schema->string()->nullable()->description('If audience is 1st/2nd/3rd degree — shapes sequence (1st = no invites).'),
            'first_degree_only' => $schema->boolean()->nullable()->description('true when list is connections-only; Launch builds DM-only LinkedIn sequence.'),
        ];
    }

    protected function run(Request $request): array
    {
        $channels = (string) ($request['channels'] ?? app(\App\V2\Ai\Services\AiChannelPolicyService::class)->defaultChannelsLabel());
        $days = (int) ($request['follow_up_days'] ?? 21);
        $explicitTarget = array_key_exists('target_count', $request->all());
        $count = (int) ($request['target_count'] ?? 500);
        $hasList = trim((string) ($request['list_hash'] ?? '')) !== '';
        $firstDegree = (bool) ($request['first_degree_only'] ?? false)
            || in_array(strtolower(trim((string) ($request['network_degree'] ?? ''))), ['1st', 'first', 'f', '1'], true);

        $sequence = $request['sequence'] ?? null;
        if (! is_array($sequence) || $sequence === []) {
            $sequence = $firstDegree
                ? [
                    'LinkedIn message (already connected — no invite)',
                    'Wait 3 days',
                    'Value follow-up message',
                    'Wait 5 days',
                    'Professional close',
                    'Pause on reply — handle in inbox',
                ]
                : [
                    'Send Invite (empty note)',
                    'After acceptance',
                    'Diagnostic / first LinkedIn message',
                    'Wait 3 days',
                    'Value follow-up message',
                    'Wait 5 days',
                    'Professional close',
                    'Pause on reply — handle in inbox',
                ];
        }

        $plan = [
            'type' => 'campaign',
            'goal' => (string) $request['goal'],
            'audience' => (string) $request['audience'],
            'icp_notes' => (string) $request['audience'],
            'target_count' => $count,
            'preferred_channels' => $channels,
            'channels' => $channels,
            'follow_up_days' => $days,
            'source' => $request['source'] ?? 'LinkedIn search + existing lists',
            'prefer_fresh_audience' => $explicitTarget && ! $hasList,
            'pause_on_reply' => array_key_exists('pause_on_reply', $request->all())
                ? (bool) $request['pause_on_reply']
                : true,
            'auto_reply_enabled' => array_key_exists('auto_reply_enabled', $request->all())
                ? (bool) $request['auto_reply_enabled']
                : false,
            'ai_context' => trim((string) ($request['ai_context'] ?? '')),
            'network_degree' => $request['network_degree'] ?? null,
            'first_degree_only' => $firstDegree,
            'sequence' => array_values(array_map('strval', $sequence)),
            'sequence_steps' => is_array($request['sequence_steps'] ?? null) ? $request['sequence_steps'] : null,
            'steps' => [
                'Confirm audience: '.$request['audience'],
                'Source prospects ('.$count.' est.)',
                'Enrich contacts',
                "Channel sequence: {$channels}",
                $firstDegree
                    ? '1st-degree: LinkedIn DMs only (no invites); pause on reply'
                    : "Follow up for {$days} days; pause on reply and handle in inbox",
                'Qualify interested prospects and book meetings',
            ],
            'status' => 'awaiting_review',
        ];

        $plan = app(PlanContentService::class)->enrichCampaign($plan);
        $plan = PlanLeadList::merge(
            $plan,
            $request['list_hash'] ?? null,
            $request['list_src'] ?? null,
            $request['list_name'] ?? null,
        );
        $plan = app(\App\V2\Ai\Services\ProspectAudienceResolverService::class)
            ->enrichPlanWithAudience($this->context->user, $plan);
        $plan = app(\App\V2\Ai\Services\PlanFunnelService::class)
            ->attachToPlan($plan, $this->context->user);

        if ($this->context->autonomy()->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval_id' => null,
                'plan' => $plan,
                'card' => app(CommandCenterService::class)->formatPlanCard($plan, null, $this->context->channel),
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
            'card' => app(CommandCenterService::class)->formatPlanCard($plan, $approval->id, $this->context->channel),
            'cta' => 'User should Review & Launch.',
        ];
    }
}
