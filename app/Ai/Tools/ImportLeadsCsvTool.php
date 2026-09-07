<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ActionApprovalService;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Outreach\OutreachImportListService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ImportLeadsCsvTool extends GatedTool
{
    public function toolName(): string
    {
        return 'import_leads_csv';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Stage a CSV lead import for Review & Launch. On Launch, creates an outreach list and attaches list_hash for campaigns.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_name' => $schema->string()->required(),
            'csv_content' => $schema->string()->required()->description('Full CSV text including header row'),
        ];
    }

    protected function run(Request $request): array
    {
        $listName = trim((string) $request['list_name']);
        $csvContent = trim((string) $request['csv_content']);

        if ($listName === '') {
            throw new \InvalidArgumentException('list_name is required.');
        }
        if ($csvContent === '') {
            throw new \InvalidArgumentException('csv_content is required.');
        }
        if (strlen($csvContent) > 500_000) {
            throw new \InvalidArgumentException('CSV is too large (max 500KB). Ask the user to upload via Outreach → Import instead.');
        }

        $previewRows = $this->previewRowCount($csvContent);
        $template = app(OutreachImportListService::class)->csvTemplate();

        $plan = [
            'type' => 'csv_import',
            'goal' => "Import contacts: {$listName}",
            'list_name' => $listName,
            'csv_content' => $csvContent,
            'preview_rows' => $previewRows,
            'template_hint' => $template,
            'steps' => [
                'Parse CSV on Launch',
                "Create list \"{$listName}\"",
                'Attach list_hash to your next campaign plan',
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
            'cta' => 'User should Review & Launch to import the list.',
        ];
    }

    private function previewRowCount(string $csvContent): int
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];

        return max(0, count($lines) - 1);
    }
}
