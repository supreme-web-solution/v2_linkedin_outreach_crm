<?php

namespace App\V2\Ai\Services;

use App\Jobs\V2\PublishV2ContentPostJob;
use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2ContentPost;
use App\Models\V2IntegrationAccount;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Support\ScheduleAtParser;
use App\V2\Services\OpenAIContentService;
use App\V2\Services\OpenAiUserError;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ContentPostCommandCenterService
{
    public function __construct(
        private readonly OpenAIContentService $openai,
        private readonly ActionApprovalService $approvals,
    ) {}

    /**
     * @return array{
     *     blocked?:bool,
     *     message?:string,
     *     approval_id?:int|null,
     *     plan?:array<string,mixed>,
     *     card?:string
     * }
     */
    public function stagePost(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        ?string $topic,
        ?string $content = null,
        string $style = 'professional',
        string $length = 'medium',
        ?string $scheduleAt = null,
        bool $generateImage = false,
        ?string $imageUrl = null,
        ?string $imagePath = null,
        string $surface = 'web',
        AiAutonomyLevel $autonomy = AiAutonomyLevel::Assisted,
    ): array {
        $topic = trim((string) $topic);
        $content = trim((string) $content);

        if ($content === '' && $topic === '') {
            throw new \InvalidArgumentException('Provide a topic or post content.');
        }

        $hasLinkedIn = V2IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', 'linkedin')
            ->where('status', 'active')
            ->exists();

        $hashtags = '';
        $imageMeta = [];
        $imageError = null;

        if ($content === '') {
            if (! $this->openai->isConfigured()) {
                throw new \RuntimeException(OpenAiUserError::NOT_CONFIGURED);
            }

            $generated = $this->openai->generateLinkedInPost(
                $topic,
                $style,
                $length,
                $generateImage,
                $user->id,
            );
            $content = (string) ($generated['content'] ?? '');
            $hashtags = (string) ($generated['hashtags'] ?? '');
            if (isset($generated['image'])) {
                $imageMeta = $this->imageMetaFromGenerated($generated['image']);
            }
            $imageError = isset($generated['image_error']) ? (string) $generated['image_error'] : null;
        }

        if ($content === '') {
            throw new \RuntimeException('Could not generate post content.');
        }

        $composed = $this->composeContent($content, $hashtags);

        if ($generateImage && $imageMeta === [] && $imageError === null) {
            try {
                $image = $this->openai->generateImage(
                    $this->openai->imagePromptFromPostContent($composed, $topic !== '' ? $topic : null),
                    $user->id,
                );
                $imageMeta = $this->imageMetaFromGenerated($image);
            } catch (\Throwable $e) {
                $imageError = 'Text was generated, but the image could not be created.';
                report($e);
            }
        }

        $imageUrl = trim((string) $imageUrl);
        $imagePath = trim((string) $imagePath);
        if ($imageMeta === [] && $imageUrl !== '' && $imagePath !== '') {
            $imageMeta = [
                'ai_image_url' => $imageUrl,
                'ai_image_path' => $imagePath,
            ];
        }

        $scheduledFor = ScheduleAtParser::parse($scheduleAt, (string) config('app.timezone', 'UTC'));

        $postMeta = array_merge([
            'post_type' => $imageMeta !== [] ? 'image' : 'text',
            'topic' => $topic !== '' ? $topic : null,
            'created_by' => 'Soci',
            'style' => $style,
            'image_urls' => [],
            'image_paths' => [],
            'video_url' => null,
            'video_path' => null,
        ], $imageMeta);

        $post = V2ContentPost::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'provider' => 'linkedin',
            'content' => $composed,
            'status' => 'draft',
            'meta' => $postMeta,
        ]);

        $plan = [
            'type' => 'linkedin_post',
            'goal' => $topic !== '' ? 'Publish LinkedIn post: '.$topic : 'Publish LinkedIn post',
            'topic' => $topic !== '' ? $topic : 'LinkedIn post',
            'post_id' => $post->id,
            'post_preview' => Str::limit($composed, 500),
            'content_url' => url('/content'),
            'linkedin_connected' => $hasLinkedIn,
            'scheduled_at' => $scheduledFor?->toIso8601String(),
            'scheduled_label' => $scheduledFor?->format('D M j, g:i A T'),
            'has_image' => $imageMeta !== [],
            'image_url' => $imageMeta['ai_image_url'] ?? null,
            'image_error' => $imageError,
            'status' => 'awaiting_review',
            'steps' => [
                $scheduledFor
                    ? 'Schedule for '.$scheduledFor->format('M j, g:i A').($imageMeta !== [] ? ' with AI image' : '')
                    : 'Publish immediately on LinkedIn'.($imageMeta !== [] ? ' with AI image' : ''),
            ],
        ];

        if (! $hasLinkedIn) {
            $plan['integration_note'] = $scheduledFor
                ? 'Connect LinkedIn in Integrations before the scheduled publish time.'
                : 'Connect LinkedIn in Integrations before Launch can publish.';
        }

        if ($autonomy->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval_id' => null,
                'plan' => $plan,
                'card' => app(CommandCenterService::class)->formatPlanCard($plan, null, $surface),
                'message' => 'Copilot mode: draft only — connect LinkedIn and switch to Assisted to publish.',
            ];
        }

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'prepare_linkedin_post',
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        if ($autonomy->value >= AiAutonomyLevel::Autopilot->value && $scheduledFor !== null && $scheduledFor->isFuture()) {
            $auto = $this->tryAutoSchedule($approval, $user, $plan, $surface);
            if ($auto !== null) {
                return $auto;
            }
        }

        return [
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => app(CommandCenterService::class)->formatPlanCard($plan, $approval->id, $surface),
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>|null
     */
    private function tryAutoSchedule(
        AiActionApproval $approval,
        User $user,
        array $plan,
        string $surface,
    ): ?array {
        try {
            $result = $this->publishFromApproval($approval, $user);
            $this->approvals->approve($approval, $user);
            $plan['status'] = 'scheduled';

            return [
                'approval_id' => $approval->id,
                'plan' => $plan,
                'auto_scheduled' => true,
                'message' => $result['message'],
                'card' => app(CommandCenterService::class)->formatPlanCard($plan, null, $surface),
                'cta' => 'Scheduled automatically — no Launch click needed.',
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return array{message:string, content_url:string, post_id:int, status:string}
     */
    public function publishFromApproval(AiActionApproval $approval, User $user): array
    {
        $postId = (int) ($approval->payload['post_id'] ?? 0);
        abort_unless($postId > 0, 422, 'Post reference missing from approval.');

        $post = V2ContentPost::query()
            ->where('id', $postId)
            ->where('user_id', $user->id)
            ->where('organization_id', $approval->organization_id)
            ->firstOrFail();

        if (! in_array($post->status, ['draft', 'failed', 'scheduled'], true)) {
            throw new \RuntimeException('This post cannot be published in its current state.');
        }

        $scheduledFor = $this->resolveScheduledFor($approval->payload ?? []);

        if ($scheduledFor !== null && $scheduledFor->isFuture()) {
            $post->update([
                'status' => 'scheduled',
                'scheduled_at' => $scheduledFor,
            ]);
            PublishV2ContentPostJob::dispatch($post->id)->delay($scheduledFor);
            $post->refresh();

            return [
                'message' => 'Scheduled for LinkedIn on '.$scheduledFor->format('M j, g:i A T').'.',
                'content_url' => url('/content'),
                'post_id' => $post->id,
                'status' => (string) $post->status,
            ];
        }

        $hasLinkedIn = V2IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', 'linkedin')
            ->where('status', 'active')
            ->exists();

        if (! $hasLinkedIn) {
            throw new \RuntimeException('Connect LinkedIn in Integrations before publishing.');
        }

        $post->update(['status' => 'ready_to_publish', 'scheduled_at' => now()]);
        PublishV2ContentPostJob::dispatchSync($post->id);
        $post->refresh();

        if ($post->status === 'failed') {
            $error = (string) (($post->meta['publish_error'] ?? '') ?: 'Publish failed.');

            throw new \RuntimeException($error);
        }

        return [
            'message' => $post->status === 'published'
                ? 'Posted to LinkedIn successfully.'
                : 'Publish job started — check Content for status.',
            'content_url' => url('/content'),
            'post_id' => $post->id,
            'status' => (string) $post->status,
        ];
    }

    /**
     * Cancel a draft/scheduled LinkedIn post created by Soci (Activity undo).
     *
     * @return array{ok:bool, message:string, post_id?:int}
     */
    public function cancelFromAlex(User $user, int $organizationId, int $postId): array
    {
        $post = V2ContentPost::query()
            ->whereKey($postId)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if ($post === null) {
            return ['ok' => false, 'message' => 'Content post not found.'];
        }

        if (! in_array($post->status, ['draft', 'scheduled', 'failed', 'ready_to_publish'], true)) {
            return [
                'ok' => false,
                'message' => 'Post is already '.$post->status.' and cannot be cancelled.',
            ];
        }

        $meta = is_array($post->meta) ? $post->meta : [];
        $meta['cancelled_by'] = 'alex_activity_undo';
        $meta['cancelled_at'] = now()->toIso8601String();

        $post->update([
            'status' => 'cancelled',
            'scheduled_at' => null,
            'meta' => $meta,
        ]);

        return [
            'ok' => true,
            'message' => 'Cancelled LinkedIn post #'.$post->id.'.',
            'post_id' => $post->id,
        ];
    }

    private function composeContent(string $content, string $hashtags): string
    {
        $text = trim($content);
        $tags = trim($hashtags);

        return $tags !== '' ? $text."\n\n".$tags : $text;
    }

    /**
     * @param  array{url:string,path:string}  $image
     * @return array{ai_image_url:string,ai_image_path:string}
     */
    private function imageMetaFromGenerated(array $image): array
    {
        $url = trim((string) ($image['url'] ?? ''));
        $path = trim((string) ($image['path'] ?? ''));
        if ($url === '' || $path === '') {
            return [];
        }

        return [
            'ai_image_url' => $url,
            'ai_image_path' => $path,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function resolveScheduledFor(array $payload): ?Carbon
    {
        $scheduledRaw = $payload['scheduled_at'] ?? null;
        if (! is_string($scheduledRaw) || trim($scheduledRaw) === '') {
            return null;
        }

        $scheduledFor = ScheduleAtParser::parse($scheduledRaw, (string) config('app.timezone', 'UTC'));
        if ($scheduledFor !== null) {
            return $scheduledFor;
        }

        try {
            $parsed = Carbon::parse($scheduledRaw);

            return $parsed->isFuture() ? $parsed : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPosts(User $user, int $organizationId, ?string $status = null, ?string $scheduledDay = null, int $limit = 20): array
    {
        $query = V2ContentPost::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        if ($status !== null && trim($status) !== '') {
            $query->where('status', trim($status));
        }

        if ($scheduledDay !== null && trim($scheduledDay) !== '') {
            $range = ScheduleAtParser::dayRange($scheduledDay);
            if ($range !== null) {
                $query->whereBetween('scheduled_at', [$range['start'], $range['end']]);
            }
        }

        return $query->limit(max(1, min($limit, 50)))->get()->map(function (V2ContentPost $post) {
            $meta = is_array($post->meta) ? $post->meta : [];

            return [
                'id' => $post->id,
                'status' => (string) $post->status,
                'preview' => Str::limit((string) $post->content, 120),
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'scheduled_label' => $post->scheduled_at?->format('D M j, g:i A T'),
                'has_image' => ! empty($meta['ai_image_url']) || ! empty($meta['image_urls']),
                'content_url' => url('/content'),
            ];
        })->all();
    }

    /**
     * @return array{
     *     message:string,
     *     rescheduled:int,
     *     posts:list<array<string,mixed>>,
     *     approval_id?:int|null,
     *     plan?:array<string,mixed>,
     *     card?:string
     * }
     */
    public function reschedulePosts(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $toScheduleAt,
        ?string $fromScheduledDay = null,
        ?array $postIds = null,
        AiAutonomyLevel $autonomy = AiAutonomyLevel::Assisted,
        string $surface = 'web',
    ): array {
        $toScheduleAt = trim($toScheduleAt);
        if ($toScheduleAt === '') {
            throw new \InvalidArgumentException('Provide the new schedule time (e.g. Wednesday, Wednesday 10am).');
        }

        $query = V2ContentPost::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'scheduled');

        if ($postIds !== null && $postIds !== []) {
            $query->whereIn('id', array_map('intval', $postIds));
        } elseif ($fromScheduledDay !== null && trim($fromScheduledDay) !== '') {
            $range = ScheduleAtParser::dayRange($fromScheduledDay);
            if ($range === null) {
                throw new \InvalidArgumentException('Could not understand which day to move posts from.');
            }
            $query->whereBetween('scheduled_at', [$range['start'], $range['end']]);
        }

        $posts = $query->orderBy('scheduled_at')->get();
        if ($posts->isEmpty()) {
            throw new \RuntimeException('No scheduled posts matched that filter.');
        }

        $plan = [
            'type' => 'content_reschedule',
            'goal' => 'Reschedule '.$posts->count().' Content post(s)',
            'from_day' => $fromScheduledDay,
            'to_schedule_at' => $toScheduleAt,
            'post_ids' => $posts->pluck('id')->all(),
            'content_url' => url('/content'),
            'steps' => [
                'Move matched scheduled posts to '.$toScheduleAt,
            ],
        ];

        if ($autonomy->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'message' => 'Copilot: would reschedule '.$posts->count().' post(s). Switch to Assisted or Autopilot to apply.',
                'rescheduled' => 0,
                'posts' => [],
                'plan' => $plan,
                'card' => app(CommandCenterService::class)->formatPlanCard($plan, null, $surface),
            ];
        }

        if ($autonomy->value === AiAutonomyLevel::Assisted->value) {
            $approval = $this->approvals->createPending(
                $user,
                $organizationId,
                'reschedule_content_posts',
                AiToolPermission::Prepare,
                $plan,
                $conversation,
            );

            return [
                'message' => 'Staged reschedule for '.$posts->count().' post(s). Review & Launch to apply.',
                'rescheduled' => 0,
                'posts' => [],
                'approval_id' => $approval->id,
                'plan' => $plan,
                'card' => app(CommandCenterService::class)->formatPlanCard($plan, $approval->id, $surface),
            ];
        }

        $result = $this->executeReschedule($posts, $toScheduleAt);

        return array_merge($result, [
            'plan' => $plan,
            'card' => app(CommandCenterService::class)->formatPlanCard($plan, null, $surface),
        ]);
    }

    /**
     * @return array{message:string,rescheduled:int,posts:list<array<string,mixed>>}
     */
    public function executeRescheduleFromApproval(AiActionApproval $approval): array
    {
        $postIds = array_map('intval', (array) ($approval->payload['post_ids'] ?? []));
        $toScheduleAt = trim((string) ($approval->payload['to_schedule_at'] ?? ''));
        abort_unless($toScheduleAt !== '', 422, 'Reschedule target missing from approval.');
        abort_unless($postIds !== [], 422, 'No posts in reschedule approval.');

        $posts = V2ContentPost::query()
            ->where('user_id', $approval->user_id)
            ->where('organization_id', $approval->organization_id)
            ->where('status', 'scheduled')
            ->whereIn('id', $postIds)
            ->orderBy('scheduled_at')
            ->get();

        if ($posts->isEmpty()) {
            throw new \RuntimeException('No scheduled posts remain to reschedule.');
        }

        return $this->executeReschedule($posts, $toScheduleAt);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, V2ContentPost>  $posts
     * @return array{message:string,rescheduled:int,posts:list<array<string,mixed>>}
     */
    private function executeReschedule($posts, string $toScheduleAt): array
    {
        $updated = [];

        foreach ($posts as $post) {
            $newAt = ScheduleAtParser::resolveRescheduleTarget(
                $toScheduleAt,
                $post->scheduled_at,
            );

            if ($newAt === null || ! $newAt->isFuture()) {
                continue;
            }

            $post->update([
                'status' => 'scheduled',
                'scheduled_at' => $newAt,
            ]);
            PublishV2ContentPostJob::dispatch($post->id)->delay($newAt);
            $updated[] = [
                'id' => $post->id,
                'scheduled_at' => $newAt->toIso8601String(),
                'scheduled_label' => $newAt->format('D M j, g:i A T'),
            ];
        }

        if ($updated === []) {
            throw new \RuntimeException('Could not reschedule — new time must be in the future.');
        }

        return [
            'message' => 'Rescheduled '.count($updated).' post(s).',
            'rescheduled' => count($updated),
            'posts' => $updated,
        ];
    }

    /**
     * Read pending WhatsApp image stored on the conversation (cleared after use).
     *
     * @return array{ai_image_url:string,ai_image_path:string}|null
     */
    public function consumePendingWhatsAppImage(AiConversation $conversation): ?array
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $pending = $meta['pending_whatsapp_image'] ?? null;
        if (! is_array($pending)) {
            return null;
        }

        $url = trim((string) ($pending['ai_image_url'] ?? ''));
        $path = trim((string) ($pending['ai_image_path'] ?? ''));
        if ($url === '' || $path === '') {
            return null;
        }

        unset($meta['pending_whatsapp_image']);
        $conversation->forceFill(['meta' => $meta])->save();

        return [
            'ai_image_url' => $url,
            'ai_image_path' => $path,
        ];
    }
}
