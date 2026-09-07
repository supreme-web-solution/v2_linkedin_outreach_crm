<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ContentPostCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class PrepareLinkedInPostTool extends GatedTool
{
    public function toolName(): string
    {
        return 'prepare_linkedin_post';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Generate a LinkedIn post with optional image (saved to Content). Uses WhatsApp-uploaded image when user sent image+caption. Launch publishes or schedules.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->nullable()->description('Topic to write about, e.g. sales marketing alignment'),
            'content' => $schema->string()->nullable()->description('Optional full post text if already drafted'),
            'style' => $schema->string()->nullable()->description('professional, casual, motivational, educational, storytelling'),
            'length' => $schema->string()->nullable()->description('short, medium, long'),
            'schedule_at' => $schema->string()->nullable()->description('When to publish, e.g. Thursday morning, 2026-09-11 09:00, tomorrow 10am'),
            'generate_image' => $schema->boolean()->nullable()->description('Generate an AI image for the post (same as /content Generate image)'),
            'image_url' => $schema->string()->nullable()->description('Use an uploaded image URL instead of generating'),
            'image_path' => $schema->string()->nullable()->description('Cloudinary public id for uploaded image'),
        ];
    }

    protected function run(Request $request): array
    {
        $topic = isset($request['topic']) ? (string) $request['topic'] : null;
        $content = isset($request['content']) ? (string) $request['content'] : null;

        if (trim((string) $topic) === '' && trim((string) $content) === '') {
            throw new \InvalidArgumentException('Provide topic or content.');
        }

        $generateImage = (bool) ($request['generate_image'] ?? false);
        $autonomy = $this->context->autonomy();
        $contentPosts = app(ContentPostCommandCenterService::class);

        $imageUrl = isset($request['image_url']) ? (string) $request['image_url'] : null;
        $imagePath = isset($request['image_path']) ? (string) $request['image_path'] : null;
        if (trim((string) $imageUrl) === '' || trim((string) $imagePath) === '') {
            $pending = $contentPosts->consumePendingWhatsAppImage($this->context->conversation);
            if ($pending !== null) {
                $imageUrl = $pending['ai_image_url'];
                $imagePath = $pending['ai_image_path'];
            }
        }

        $result = $contentPosts->stagePost(
            user: $this->context->user,
            organizationId: $this->context->organizationId,
            conversation: $this->context->conversation,
            topic: $topic,
            content: $content,
            style: (string) ($request['style'] ?? 'professional'),
            length: (string) ($request['length'] ?? 'medium'),
            scheduleAt: isset($request['schedule_at']) ? (string) $request['schedule_at'] : null,
            generateImage: $generateImage,
            imageUrl: $imageUrl,
            imagePath: $imagePath,
            surface: $this->context->channel,
            autonomy: $autonomy,
        );

        if (! empty($result['auto_scheduled'])) {
            return [
                'approval_id' => $result['approval_id'] ?? null,
                'plan' => $result['plan'] ?? [],
                'card' => $result['card'] ?? '',
                'auto_scheduled' => true,
                'message' => $result['message'] ?? 'Post scheduled.',
                'cta' => 'Already scheduled automatically — user does not need to click Launch.',
            ];
        }

        $approvalId = $result['approval_id'] ?? null;
        $cta = match (true) {
            $approvalId === null => $result['message'] ?? 'Copilot mode: recommendation only.',
            $autonomy->value >= AiAutonomyLevel::Autopilot->value && ! empty($result['plan']['scheduled_at'])
                => 'Autopilot will auto-schedule after staging (no Launch click needed).',
            default => 'User should Review & Launch to publish on LinkedIn (LAUNCH '.$approvalId.' on WhatsApp). Edit at /content.',
        };

        return [
            'approval_id' => $approvalId,
            'plan' => $result['plan'] ?? [],
            'card' => $result['card'] ?? '',
            'cta' => $cta,
        ];
    }
}
