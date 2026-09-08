<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\DeleteCampaignCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Destructive — always stages Review & Launch. Never auto-executes at any autonomy level.
 */
class DeleteCampaignTool extends GatedTool
{
    public function toolName(): string
    {
        return 'delete_campaign';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Stage permanent deletion of an outreach or LinkedIn campaign for user approval. NEVER deletes immediately — user must Confirm Delete / LAUNCH. Use for "delete campaign X", "remove that outreach", etc.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->required()->description('Campaign id to delete'),
            'kind' => $schema->string()->required()->description('outreach (multi-channel) or linkedin (extension campaigns)'),
            'reason' => $schema->string()->nullable()->description('Why the user wants it deleted'),
        ];
    }

    protected function run(Request $request): array
    {
        $result = app(DeleteCampaignCommandCenterService::class)->stage(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            (string) $request['kind'],
            (int) $request['campaign_id'],
            isset($request['reason']) ? (string) $request['reason'] : null,
            $this->context->channel,
        );

        if ($result['blocked'] ?? false) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Blocked'));
        }

        return [
            'approval_id' => $result['approval_id'],
            'plan' => $result['plan'],
            'card' => $result['card'],
            'cta' => $result['cta'] ?? 'User must Confirm Delete — deletes never auto-run.',
        ];
    }
}
