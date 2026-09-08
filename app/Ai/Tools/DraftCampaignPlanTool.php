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
            'sequence' => $schema->array()->nullable()->description('Ordered prose steps Alex should execute, e.g. ["Send Invite","Wait 3 days","Send Email","Wait 5 days","Follow-up"]. Launch builds a custom sequence from this when possible.'),
            'sequence_steps' => $schema->array()->nullable()->description(
                'Optional structured steps. action MUST be a built-in key only: '
                .'linkedin=visit_profile|send_invite|send_message|like_post|endorse; '
                .'email=send_email; whatsapp/instagram/telegram/twitter=send_message (twitter also follow). '
                .'Never use connect — use send_invite for connection requests.',
            ),
            'list_hash' => $schema->string()->nullable(),
            'list_src' => $schema->string()->enum(['aud', 'sn', 'csv'])->nullable(),
            'list_name' => $schema->string()->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $channels = (string) ($request['channels'] ?? app(\App\V2\Ai\Services\AiChannelPolicyService::class)->defaultChannelsLabel());
        $days = (int) ($request['follow_up_days'] ?? 21);
        $count = (int) ($request['target_count'] ?? 500);

        $sequence = $request['sequence'] ?? null;
        if (! is_array($sequence) || $sequence === []) {
            $sequence = [
                'Connection / first touch',
                'Wait 3 days',
                'Message / email',
                'Wait 5 days',
                'Follow-up',
                'AI reply handling — stop on reply',
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
            'sequence' => array_values(array_map('strval', $sequence)),
            'sequence_steps' => is_array($request['sequence_steps'] ?? null) ? $request['sequence_steps'] : null,
            'steps' => [
                'Confirm audience: '.$request['audience'],
                'Source prospects ('.$count.' est.)',
                'Enrich contacts',
                "Channel sequence: {$channels}",
                "Follow up for {$days} days; stop when they reply",
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
