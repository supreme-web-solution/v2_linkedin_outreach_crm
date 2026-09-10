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
            'goal' => $schema->string()->required()->description('Full brief of what to achieve (kept in the plan). Not used as the campaign list title.'),
            'campaign_name' => $schema->string()->nullable()->description('Short list title only (max ~50 chars). Example: "Annual event invite". Never paste emails or the full goal sentence.'),
            'sender_name' => $schema->string()->nullable()->description(
                'Exact name to sign THIS campaign with when the user specified one (e.g. "William Victor", "Dr. Ada"). '
                .'Must obey the user — do not substitute their profile name if they gave a different signing name. '
                .'Also call update_sender_profile to remember it for later.'
            ),
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
            'one_shot' => $schema->boolean()->nullable()->description(
                'true = single send only (one email OR one LinkedIn/WhatsApp DM). No Wait N days, no follow-up. Use for greetings and one-off invites.',
            ),
            'message' => $schema->string()->nullable()->description('Exact body for one_shot LinkedIn/WhatsApp/email sends.'),
            'subject' => $schema->string()->nullable()->description('Email subject when one_shot + Email channel.'),
            'profile_url' => $schema->string()->nullable()->description('Exact LinkedIn profile URL for a one-person send.'),
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
        $oneShot = (bool) ($request['one_shot'] ?? false)
            || (bool) preg_match('/one[-\s]?time|one[-\s]?shot|greeting|just (a )?message|single (email|message)|message (him|her|them)/i', (string) $request['goal']);

        $profileUrl = trim((string) ($request['profile_url'] ?? ''));
        if ($profileUrl === '' || ! preg_match('#linkedin\.com/in/#i', $profileUrl)) {
            $blob = (string) $request['goal'].' '.(string) $request['audience'].' '.(string) ($request['message'] ?? '');
            if (preg_match('#https?://(?:www\.)?linkedin\.com/in/[\w%-]+/?#i', $blob, $m)) {
                $profileUrl = $m[0];
            }
        }
        if ($profileUrl !== '') {
            $oneShot = true;
        }

        $sequence = $request['sequence'] ?? null;
        if (! is_array($sequence) || $sequence === [] || $oneShot) {
            if ($oneShot) {
                $sequence = str_contains(strtolower($channels), 'email') && ! str_contains(strtolower($channels), 'linkedin')
                    ? ['One-time email — no follow-up']
                    : ['One-time message — no invite, no wait, no follow-up'];
            } else {
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
        }

        $plan = [
            'type' => 'campaign',
            'goal' => (string) $request['goal'],
            'campaign_name' => trim((string) ($request['campaign_name'] ?? '')),
            'sender_name' => trim((string) ($request['sender_name'] ?? ''))
                ?: \App\V2\Ai\Support\SenderIdentity::extractFromText(
                    trim((string) $request['goal'].' '.(string) ($request['message'] ?? '').' '.(string) ($request['ai_context'] ?? ''))
                ),
            'audience' => (string) $request['audience'],
            'icp_notes' => (string) $request['audience'],
            'target_count' => $oneShot ? 1 : $count,
            'preferred_channels' => $channels,
            'channels' => $channels,
            'follow_up_days' => $oneShot ? 1 : $days,
            'source' => $request['source'] ?? 'LinkedIn search + existing lists',
            'prefer_fresh_audience' => ($explicitTarget && ! $hasList && ! $oneShot) || ($oneShot && $profileUrl !== ''),
            'pause_on_reply' => array_key_exists('pause_on_reply', $request->all())
                ? (bool) $request['pause_on_reply']
                : true,
            'auto_reply_enabled' => array_key_exists('auto_reply_enabled', $request->all())
                ? (bool) $request['auto_reply_enabled']
                : false,
            'ai_context' => trim((string) ($request['ai_context'] ?? '')),
            'network_degree' => $request['network_degree'] ?? null,
            'first_degree_only' => $firstDegree,
            'one_shot' => $oneShot,
            'one_time' => $oneShot,
            'message' => trim((string) ($request['message'] ?? '')),
            'subject' => trim((string) ($request['subject'] ?? '')),
            'profile_url' => $profileUrl,
            'linkedin_url' => $profileUrl,
            'sequence' => array_values(array_map('strval', $sequence)),
            'sequence_steps' => $oneShot ? null : (is_array($request['sequence_steps'] ?? null) ? $request['sequence_steps'] : null),
            'steps' => $oneShot
                ? [
                    'One recipient / one send only',
                    'No Wait N days and no follow-up steps',
                    'Deliver via outreach queue for limits + tracking',
                ]
                : [
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
        // Prefer profile_url import over a stale multi-lead list for one-shots.
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
