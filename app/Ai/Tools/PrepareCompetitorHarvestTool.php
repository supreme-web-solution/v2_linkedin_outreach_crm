<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ActionApprovalService;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Services\CompetitorEngagerHarvestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class PrepareCompetitorHarvestTool extends GatedTool
{
    public function toolName(): string
    {
        return 'prepare_competitor_harvest';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Stage a competitor active-engager harvest from a LinkedIn company or profile URL. Requires Review & Launch before Unipile harvest runs.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'linkedin_url' => $schema->string()->required()->description('LinkedIn company (/company/...) or profile (/in/...) URL'),
            'competitor_name' => $schema->string()->nullable()->description('Display label, e.g. competitor brand name'),
            'goal' => $schema->string()->nullable()->description('Why we are harvesting, e.g. build prospect list for outreach'),
        ];
    }

    protected function run(Request $request): array
    {
        $url = trim((string) $request['linkedin_url']);
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (
            ! preg_match('~/company/[^/?#]+~i', $path)
            && ! preg_match('~/in/[^/?#]+~i', $path)
        ) {
            throw new \InvalidArgumentException('Provide a LinkedIn company URL (linkedin.com/company/...) or profile URL (linkedin.com/in/...).');
        }

        $source = app(CompetitorEngagerHarvestService::class)->detectLinkedInSource($url);
        $label = trim((string) ($request['competitor_name'] ?? ''));
        $goal = trim((string) ($request['goal'] ?? 'Harvest active engagers for outreach'));

        $plan = [
            'type' => 'competitor_harvest',
            'goal' => $goal,
            'linkedin_url' => $url,
            'label' => $label !== '' ? $label : null,
            'source_type' => $source['type'] ?: 'company',
            'steps' => [
                'Connect LinkedIn via Integrations (required)',
                'Harvest recent post engagers via Unipile',
                'Store audience list in SociFusion',
                'Analyze audience and draft outreach campaign',
            ],
            'status' => 'awaiting_review',
        ];

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
            'cta' => 'User should Review & Launch to start harvest (LAUNCH '.$approval->id.' on WhatsApp).',
        ];
    }
}
