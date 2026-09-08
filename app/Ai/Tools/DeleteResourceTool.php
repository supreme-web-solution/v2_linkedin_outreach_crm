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
        return 'Stage permanent deletion for user approval (Confirm Delete). NEVER deletes immediately. '
            .'Kinds: outreach / linkedin campaigns, lead_list (needs list_src aud|sn|csv), content_post, '
            .'inbox_conversation (needs platform), outreach_template. Prefer this over delete_campaign for non-campaign items.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->required()->description(
                'outreach | linkedin | lead_list | content_post | inbox_conversation | outreach_template',
            ),
            'resource_id' => $schema->string()->required()->description(
                'Campaign/post/conversation/template numeric id, or lead list hash',
            ),
            'list_src' => $schema->string()->enum(['aud', 'sn', 'csv'])->nullable()->description(
                'Required for lead_list: aud, sn, or csv',
            ),
            'platform' => $schema->string()->nullable()->description(
                'Required for inbox_conversation: linkedin, email, whatsapp, etc.',
            ),
            'reason' => $schema->string()->nullable()->description('Why the user wants it deleted'),
        ];
    }

    protected function run(Request $request): array
    {
        $result = app(DeleteCampaignCommandCenterService::class)->stageResource(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            (string) $request['kind'],
            (string) $request['resource_id'],
            isset($request['list_src']) ? (string) $request['list_src'] : null,
            isset($request['platform']) ? (string) $request['platform'] : null,
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
