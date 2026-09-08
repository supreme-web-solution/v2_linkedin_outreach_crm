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
        return 'Stage permanent deletion of outreach/LinkedIn campaign(s) for ONE Confirm Delete. '
            .'Prefer campaign_ids or delete_all_outreach=true for multiple campaigns — never stage 7 separate plans. '
            .'For lead lists/posts/inbox/templates use delete_resource (also supports items[] bulk).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->nullable()->description('Single campaign id (use campaign_ids for many).'),
            'campaign_ids' => $schema->array()->nullable()->description('Multiple campaign ids → one bulk Confirm Delete.'),
            'delete_all_outreach' => $schema->boolean()->nullable()->description(
                'true = delete every non-template outreach campaign for this user in one Confirm Delete.',
            ),
            'kind' => $schema->string()->enum(['outreach', 'linkedin'])->nullable()->description(
                'Default outreach. Ignored when delete_all_outreach is true (always outreach).',
            ),
            'reason' => $schema->string()->nullable()->description('Why the user wants it deleted'),
        ];
    }

    protected function run(Request $request): array
    {
        $service = app(DeleteCampaignCommandCenterService::class);
        $kind = (string) ($request['kind'] ?? 'outreach');
        $reason = isset($request['reason']) ? (string) $request['reason'] : null;
        $deleteAll = (bool) ($request['delete_all_outreach'] ?? false);

        $ids = [];
        if ($deleteAll) {
            $listed = $service->listDeletableOutreachCampaigns(
                $this->context->user,
                $this->context->organizationId,
            );
            $ids = array_map(fn (array $row) => (int) $row['resource_id'], $listed);
            $kind = 'outreach';
            if ($ids === []) {
                return [
                    'approval_id' => null,
                    'plan' => null,
                    'card' => 'No outreach campaigns to delete.',
                    'cta' => 'Nothing to delete.',
                ];
            }
        } else {
            $rawIds = $request['campaign_ids'] ?? null;
            if (is_array($rawIds)) {
                foreach ($rawIds as $id) {
                    $n = (int) $id;
                    if ($n > 0) {
                        $ids[] = $n;
                    }
                }
            }
            if ($ids === [] && isset($request['campaign_id'])) {
                $n = (int) $request['campaign_id'];
                if ($n > 0) {
                    $ids[] = $n;
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            throw new \InvalidArgumentException('Provide campaign_id, campaign_ids, or delete_all_outreach=true.');
        }

        if (count($ids) === 1) {
            $result = $service->stage(
                $this->context->user,
                $this->context->organizationId,
                $this->context->conversation,
                $kind,
                $ids[0],
                $reason,
                $this->context->channel,
            );
        } else {
            $items = array_map(fn (int $id) => [
                'kind' => $kind,
                'resource_id' => (string) $id,
            ], $ids);
            $result = $service->stageBulk(
                $this->context->user,
                $this->context->organizationId,
                $this->context->conversation,
                $items,
                $reason,
                $this->context->channel,
                'delete_campaign',
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
            'item_count' => count($ids),
        ];
    }
}
