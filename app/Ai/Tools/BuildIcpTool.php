<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ActionApprovalService;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\PlanContentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class BuildIcpTool extends GatedTool
{
    public function toolName(): string
    {
        return 'build_icp';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Build an Ideal Customer Profile from offer, website, example customers, and competitors. Stages a reviewable ICP plan; after Launch say "find prospects".';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'offer' => $schema->string()->required()->description('What the user sells'),
            'website' => $schema->string()->nullable()->description('Company website URL or notes'),
            'customers' => $schema->string()->nullable()->description('Comma-separated example customers / logos'),
            'competitors' => $schema->string()->nullable()->description('Comma-separated competitor names'),
            'geography' => $schema->string()->nullable(),
            'notes' => $schema->string()->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $offer = (string) $request['offer'];
        $geography = (string) ($request['geography'] ?? 'US');
        $competitors = array_values(array_filter(array_map('trim', explode(',', (string) ($request['competitors'] ?? '')))));
        $customers = array_values(array_filter(array_map('trim', explode(',', (string) ($request['customers'] ?? '')))));

        $icp = app(PlanContentService::class)->buildIcp(
            $offer,
            $request['website'] ?? null,
            $competitors,
            $geography,
            $request['notes'] ?? null,
            $customers,
        );

        $plan = [
            'type' => 'icp',
            'goal' => "Find ideal customers for: {$offer}",
            'icp' => $icp,
            'icp_notes' => (string) ($icp['summary'] ?? $offer),
            'website' => $request['website'] ?? null,
            'customers' => $customers,
            'geography' => $geography,
            'preferred_channels' => app(\App\V2\Ai\Services\AiChannelPolicyService::class)->defaultChannelsLabel(),
            'follow_up_days' => 21,
            'steps' => [
                'Launch to save ICP to workspace',
                'Say "find prospects" — Alex searches LinkedIn from this ICP',
                'Draft outreach campaign with the discovered list',
                'Qualify replies and book meetings',
            ],
            'status' => 'awaiting_review',
        ];

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
            'cta' => 'Launch to save ICP, then find prospects or draft a campaign.',
        ];
    }
}
