<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ContentPostCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class RescheduleContentPostsTool extends GatedTool
{
    public function toolName(): string
    {
        return 'reschedule_content_posts';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Reschedule Content posts to a new day/time. Example: move all posts scheduled for tomorrow to Wednesday. Autopilot+ applies immediately.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'to_schedule_at' => $schema->string()->description('New schedule, e.g. Wednesday, Wednesday 10am'),
            'from_scheduled_day' => $schema->string()->nullable()->description('Only posts currently scheduled on this day, e.g. tomorrow'),
            'post_ids' => $schema->array()->items($schema->integer())->nullable()->description('Optional specific post ids from list_content_posts'),
        ];
    }

    protected function run(Request $request): array
    {
        $postIds = null;
        if (isset($request['post_ids']) && is_array($request['post_ids'])) {
            $postIds = array_values(array_filter(array_map('intval', $request['post_ids'])));
        }

        return app(ContentPostCommandCenterService::class)->reschedulePosts(
            user: $this->context->user,
            organizationId: $this->context->organizationId,
            conversation: $this->context->conversation,
            toScheduleAt: (string) ($request['to_schedule_at'] ?? ''),
            fromScheduledDay: isset($request['from_scheduled_day']) ? (string) $request['from_scheduled_day'] : null,
            postIds: $postIds,
            autonomy: $this->context->autonomy(),
            surface: $this->context->channel,
        );
    }
}
