<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\CallManagerCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class PrepareCallManagerLaunchTool extends GatedTool
{
    public function toolName(): string
    {
        return 'prepare_call_manager_launch';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Load a lead list into Call Manager and stage LinkedIn chat outreach for Review & Launch. Launch queues opening messages to prospects.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'audience_query' => $schema->string()->nullable()->description('ICP or list keywords, e.g. US SaaS founders'),
            'list_hash' => $schema->string()->nullable()->description('Lead list id from discover_prospects'),
            'list_src' => $schema->string()->enum(['aud', 'sn'])->nullable(),
            'batch_name' => $schema->string()->nullable()->description('Flow name in Call Manager'),
            'opening_message' => $schema->string()->nullable()->description('Optional custom opening LinkedIn message'),
        ];
    }

    protected function run(Request $request): array
    {
        $result = app(CallManagerCommandCenterService::class)->stageLaunch(
            user: $this->context->user,
            organizationId: $this->context->organizationId,
            conversation: $this->context->conversation,
            audienceQuery: isset($request['audience_query']) ? (string) $request['audience_query'] : null,
            listHash: isset($request['list_hash']) ? (string) $request['list_hash'] : null,
            listSrc: isset($request['list_src']) ? (string) $request['list_src'] : null,
            batchName: isset($request['batch_name']) ? (string) $request['batch_name'] : null,
            openingMessage: isset($request['opening_message']) ? (string) $request['opening_message'] : null,
            surface: $this->context->channel,
            autonomy: $this->context->autonomy(),
        );

        return [
            'approval_id' => $result['approval_id'] ?? null,
            'plan' => $result['plan'] ?? [],
            'card' => $result['card'] ?? '',
            'cta' => ($result['approval_id'] ?? null)
                ? 'User should Review & Launch to start Call Manager chats (LAUNCH '.$result['approval_id'].' on WhatsApp).'
                : ($result['message'] ?? 'Copilot mode: recommendation only.'),
        ];
    }
}
