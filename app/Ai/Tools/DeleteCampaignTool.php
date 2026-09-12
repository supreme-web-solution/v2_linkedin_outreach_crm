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
            .'Prefer campaign_ids, delete_created_today=true, or delete_all_outreach=true — never stage separate plans. '
            .'When user says "delete campaigns created today", use delete_created_today=true (includes outreach + LinkedIn). '
            .'For lead lists/posts/inbox/templates use delete_resource (also supports items[] bulk).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->nullable()->description('Single campaign id (use campaign_ids for many).'),
            'campaign_ids' => $schema->array()->nullable()->description('Multiple campaign ids → one bulk Confirm Delete.'),
            'delete_created_today' => $schema->boolean()->nullable()->description(
                'true = delete every outreach + LinkedIn campaign created today (app date) in one Confirm Delete.',
            ),
            'created_after' => $schema->string()->nullable()->description('ISO datetime lower bound for campaign creation window'),
            'created_before' => $schema->string()->nullable()->description('ISO datetime upper bound for campaign creation window'),
            'delete_all_outreach' => $schema->boolean()->nullable()->description(
                'true = delete every non-template outreach campaign for this user in one Confirm Delete.',
            ),
            'kind' => $schema->string()->enum(['outreach', 'linkedin'])->nullable()->description(
                'Default outreach. Ignored when delete_all_outreach / delete_created_today is true.',
            ),
            'reason' => $schema->string()->nullable()->description('Why the user wants it deleted'),
        ];
    }

    protected function run(Request $request): array
    {
        $service = app(DeleteCampaignCommandCenterService::class);
        $kind = (string) ($request['kind'] ?? 'outreach');
        $reason = isset($request['reason']) ? (string) $request['reason'] : null;
        $deleteToday = (bool) ($request['delete_created_today'] ?? false);
        $deleteAll = (bool) ($request['delete_all_outreach'] ?? false);
        $createdAfter = isset($request['created_after']) ? (string) $request['created_after'] : null;
        $createdBefore = isset($request['created_before']) ? (string) $request['created_before'] : null;

        $items = [];
        if ($createdAfter && $createdBefore) {
            $listed = $service->listDeletableCampaignsInWindow(
                $this->context->user,
                $this->context->organizationId,
                $createdAfter,
                $createdBefore,
            );
            $items = array_map(fn (array $row) => [
                'kind' => (string) $row['kind'],
                'resource_id' => (string) $row['resource_id'],
            ], $listed);
            if ($items === []) {
                return [
                    'approval_id' => null,
                    'plan' => null,
                    'card' => 'No campaigns matched that time window.',
                    'cta' => 'Nothing to delete.',
                ];
            }
        } elseif ($deleteToday) {
            $listed = $service->listDeletableCampaignsCreatedToday(
                $this->context->user,
                $this->context->organizationId,
            );
            $items = array_map(fn (array $row) => [
                'kind' => (string) $row['kind'],
                'resource_id' => (string) $row['resource_id'],
            ], $listed);
            if ($items === []) {
                return [
                    'approval_id' => null,
                    'plan' => null,
                    'card' => 'No campaigns created today to delete.',
                    'cta' => 'Nothing to delete.',
                ];
            }
        } elseif ($deleteAll) {
            $listed = $service->listDeletableOutreachCampaigns(
                $this->context->user,
                $this->context->organizationId,
            );
            $items = array_map(fn (array $row) => [
                'kind' => 'outreach',
                'resource_id' => (string) $row['resource_id'],
            ], $listed);
            if ($items === []) {
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
                        $items[] = [
                            'kind' => $kind,
                            'resource_id' => (string) $n,
                        ];
                    }
                }
            }
            if ($items === [] && isset($request['campaign_id'])) {
                $n = (int) $request['campaign_id'];
                if ($n > 0) {
                    $items[] = [
                        'kind' => $kind,
                        'resource_id' => (string) $n,
                    ];
                }
            }
        }

        // Dedupe by kind+id
        $seen = [];
        $items = array_values(array_filter($items, function (array $row) use (&$seen) {
            $key = $row['kind'].':'.$row['resource_id'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));

        if ($items === []) {
            throw new \InvalidArgumentException(
                'Provide campaign_id, campaign_ids, delete_created_today=true, or delete_all_outreach=true.',
            );
        }

        $result = $service->stageBulk(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            $items,
            $reason,
            $this->context->channel,
            'delete_campaign',
        );

        if ($result['blocked'] ?? false) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Blocked'));
        }

        return [
            'approval_id' => $result['approval_id'],
            'plan' => $result['plan'],
            'card' => $result['card'],
            'cta' => $result['cta'] ?? 'User must Confirm Delete — deletes never auto-run.',
            'item_count' => count($items),
        ];
    }
}
