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
class DeleteResourceTool extends GatedTool
{
    public function toolName(): string
    {
        return 'delete_resource';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Stage permanent deletion for user approval (one Confirm Delete). NEVER deletes immediately. '
            .'Single: kind + resource_id. Bulk: items=[{kind,resource_id,list_src?,platform?}]. '
            .'Kinds: outreach | linkedin | lead_list (needs list_src) | content_post | inbox_conversation (needs platform) | outreach_template. '
            .'When deleting many things, ALWAYS use items[] — never stage separate plans per item.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->nullable()->description(
                'Single delete: outreach | linkedin | lead_list | content_post | inbox_conversation | outreach_template',
            ),
            'resource_id' => $schema->string()->nullable()->description(
                'Single delete: campaign/post/conversation/template id, or lead list hash',
            ),
            'list_src' => $schema->string()->enum(['aud', 'sn', 'csv'])->nullable()->description(
                'Required for lead_list: aud, sn, or csv',
            ),
            'platform' => $schema->string()->nullable()->description(
                'Required for inbox_conversation: linkedin, email, whatsapp, etc.',
            ),
            'items' => $schema->array()->nullable()->description(
                'Bulk delete in ONE Confirm Delete. Each item: {kind, resource_id, list_src?, platform?}.',
            ),
            'reason' => $schema->string()->nullable()->description('Why the user wants it deleted'),
        ];
    }

    protected function run(Request $request): array
    {
        $service = app(DeleteCampaignCommandCenterService::class);
        $reason = isset($request['reason']) ? (string) $request['reason'] : null;
        $items = $request['items'] ?? null;

        if (is_array($items) && $items !== []) {
            $normalized = [];
            foreach ($items as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $normalized[] = [
                    'kind' => (string) ($row['kind'] ?? 'outreach'),
                    'resource_id' => (string) ($row['resource_id'] ?? $row['campaign_id'] ?? ''),
                    'list_src' => $row['list_src'] ?? null,
                    'platform' => $row['platform'] ?? null,
                ];
            }
            $result = $service->stageBulk(
                $this->context->user,
                $this->context->organizationId,
                $this->context->conversation,
                $normalized,
                $reason,
                $this->context->channel,
                'delete_resource',
            );
        } else {
            $kind = trim((string) ($request['kind'] ?? ''));
            $resourceId = trim((string) ($request['resource_id'] ?? ''));
            if ($kind === '' || $resourceId === '') {
                throw new \InvalidArgumentException('Provide kind+resource_id, or items[] for bulk delete.');
            }
            $result = $service->stageResource(
                $this->context->user,
                $this->context->organizationId,
                $this->context->conversation,
                $kind,
                $resourceId,
                isset($request['list_src']) ? (string) $request['list_src'] : null,
                isset($request['platform']) ? (string) $request['platform'] : null,
                $reason,
                $this->context->channel,
            );
        }

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
