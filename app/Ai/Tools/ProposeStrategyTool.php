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
        return 'Turn a user sales goal into a structured SociFusion Command Center plan awaiting Review & Launch. Call this even when audience is missing — the card will show what is blocked.';
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
            'list_hash' => $schema->string()->nullable()->description('Lead list id from find_prospects'),
            'list_src' => $schema->string()->enum(['aud', 'sn', 'csv'])->nullable(),
            'list_name' => $schema->string()->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $plan = [
            'type' => 'strategy',
            'goal' => (string) $request['goal'],
            'geography' => $request['geography'] ?? null,
            'icp_notes' => $request['icp_notes'] ?? null,
            'preferred_channels' => $request['preferred_channels'] ?? app(\App\V2\Ai\Services\AiChannelPolicyService::class)->defaultChannelsLabel(),
            'target_count' => $request['target_count'] ?? 500,
            'follow_up_days' => $request['follow_up_days'] ?? 21,
            'steps' => [
                'Clarify / confirm ICP',
                'Find matching companies and decision makers',
                'Enrich contact information',
                'Draft personalized outreach campaign',
                'Follow up until reply; stop automation on reply',
                'Qualify and book meetings',
            ],
            'status' => 'awaiting_review',
        ];

        $plan = app(PlanContentService::class)->enrichStrategy($plan);
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

        return $this->stagePlan($plan);
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
}
