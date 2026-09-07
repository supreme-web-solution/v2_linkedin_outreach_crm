<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ActionApprovalService;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Support\PlanLeadList;
use App\V2\Outreach\OutreachLeadReadinessService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class PrepareEnrichmentTool extends GatedTool
{
    public function toolName(): string
    {
        return 'prepare_enrichment';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Stage email/phone enrichment for a lead list (FullEnrich + Unipile). Requires Review & Launch before jobs queue.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_hash' => $schema->string()->required(),
            'list_src' => $schema->string()->enum(['aud', 'sn', 'csv'])->required(),
            'list_name' => $schema->string()->nullable(),
            'mode' => $schema->string()->enum(['email', 'phone', 'both'])->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $listHash = trim((string) $request['list_hash']);
        $listSrc = trim((string) $request['list_src']);
        $mode = (string) ($request['mode'] ?? 'email');
        $listName = trim((string) ($request['list_name'] ?? 'Lead list'));

        $preview = app(OutreachLeadReadinessService::class)->previewForLists(
            [['list_hash' => $listHash, 'list_src' => $listSrc]],
            [],
            $this->context->user->id,
        );

        $emailEligible = (int) data_get($preview, 'email_fetch.fetchable', 0);
        $phoneEligible = (int) data_get($preview, 'phone_fetch.fetchable', 0);

        $plan = PlanLeadList::merge([
            'type' => 'enrichment',
            'goal' => "Enrich contacts on {$listName}",
            'mode' => $mode,
            'email_eligible' => $emailEligible,
            'phone_eligible' => $phoneEligible,
            'steps' => [
                'Queue LinkedIn profile lookups where needed',
                'Run FullEnrich for missing emails (when configured)',
                'Fetch phone numbers where eligible',
                'Return to Command Center to draft outreach',
            ],
            'status' => 'awaiting_review',
        ], $listHash, $listSrc, $listName);

        if ($emailEligible === 0 && $phoneEligible === 0) {
            return [
                'approval_id' => null,
                'plan' => $plan,
                'card' => app(CommandCenterService::class)->formatPlanCard($plan, null, $this->context->channel),
                'hint' => 'No enrichable contacts found on this list — it may already be enriched or not audience-based.',
            ];
        }

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
            'cta' => 'User should Review & Launch to queue enrichment (LAUNCH '.$approval->id.' on WhatsApp).',
        ];
    }
}
