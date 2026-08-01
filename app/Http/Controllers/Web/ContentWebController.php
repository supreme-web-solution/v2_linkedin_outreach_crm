<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\V2\PublishV2ContentPostJob;
use App\Models\V2ContentPost;
use App\Models\V2IntegrationAccount;
use App\V2\Services\CloudinaryMediaService;
use App\V2\Services\OpenAIContentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentWebController extends Controller
{
    public function __construct(
        private readonly OpenAIContentService $openai,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));

        $postsQuery = $orgId
            ? V2ContentPost::where('organization_id', $orgId)
            : null;

        if ($postsQuery && $search !== '') {
            $postsQuery->where('content', 'like', '%'.$search.'%');
        }

        if ($postsQuery && $status !== '' && $status !== 'all') {
            $postsQuery->where('status', $status);
        }

        $posts = $postsQuery
            ? $postsQuery->latest()->paginate(20)->appends($request->query())
            : collect()->paginate(1);

        $stats = $orgId ? [
            'total'     => V2ContentPost::where('organization_id', $orgId)->count(),
            'published' => V2ContentPost::where('organization_id', $orgId)->where('status', 'published')->count(),
            'scheduled' => V2ContentPost::where('organization_id', $orgId)->where('status', 'scheduled')->count(),
            'draft'     => V2ContentPost::where('organization_id', $orgId)->where('status', 'draft')->count(),
        ] : ['total' => 0, 'published' => 0, 'scheduled' => 0, 'draft' => 0];

        $hasLinkedIn = $orgId
            ? V2IntegrationAccount::where('user_id', $user->id)
                ->where('provider', 'linkedin')
                ->where('status', 'active')
                ->exists()
            : false;

        return Inertia::render('crm/Content', [
            'posts'        => $posts,
            'contentStats' => $stats,
            'hasOrg'       => (bool) $orgId,
            'filters'      => [
                'search' => $search !== '' ? $search : null,
                'status' => $status !== '' ? $status : null,
            ],
            'hasLinkedIn'  => $hasLinkedIn,
            'aiConfigured' => $this->openai->isConfigured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $data = $request->validate([
            'content'      => ['required', 'string', 'max:3000'],
            'hashtags'     => ['nullable', 'string', 'max:500'],
            'scheduled_at' => ['nullable', 'date'],
            'action'       => ['required', 'in:draft,publish,schedule'],
            'post_type'    => ['nullable', 'in:text,image,video'],
            'images'       => ['nullable', 'array', 'max:10'],
            'images.*'     => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'video'        => ['nullable', 'file', 'mimes:mp4,mov,avi,wmv', 'max:102400'],
            'ai_image_url' => ['nullable', 'string', 'max:2048'],
            'ai_image_path' => ['nullable', 'string', 'max:2048'],
        ]);

        [$status, $scheduledAt] = $this->resolveActionStatus($data['action'], $data['scheduled_at'] ?? null);
        if ($status === 'invalid_schedule') {
            return back()->withErrors(['scheduled_at' => 'Scheduled time must be in the future.'])->withInput();
        }

        $content = $this->composeContent($data['content'], $data['hashtags'] ?? '');
        $meta = $this->collectMediaMeta($request, $user->id, $data);

        $post = V2ContentPost::create([
            'user_id'         => $user->id,
            'organization_id' => $orgId,
            'provider'        => 'linkedin',
            'content'         => $content,
            'status'          => $status,
            'scheduled_at'    => $scheduledAt,
            'meta'            => $meta,
        ]);

        if ($status === 'ready_to_publish') {
            PublishV2ContentPostJob::dispatchSync($post->id);
        } elseif ($status === 'scheduled') {
            PublishV2ContentPostJob::dispatch($post->id)->delay($scheduledAt);
        }

        return redirect()->route('content')->with('success', 'Post saved.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $user = auth()->user();
        $post = V2ContentPost::where('user_id', $user->id)
            ->where('organization_id', $user->current_organization_id)
            ->findOrFail($id);

        if (!in_array($post->status, ['draft', 'failed'], true)) {
            return back()->withErrors(['post' => 'Only draft posts can be edited.']);
        }

        $data = $request->validate([
            'content'      => ['required', 'string', 'max:3000'],
            'hashtags'     => ['nullable', 'string', 'max:500'],
            'scheduled_at' => ['nullable', 'date'],
            'action'       => ['required', 'in:draft,publish,schedule'],
            'post_type'    => ['nullable', 'in:text,image,video'],
            'images'       => ['nullable', 'array', 'max:10'],
            'images.*'     => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'video'        => ['nullable', 'file', 'mimes:mp4,mov,avi,wmv', 'max:102400'],
            'ai_image_url' => ['nullable', 'string', 'max:2048'],
            'ai_image_path' => ['nullable', 'string', 'max:2048'],
        ]);

        [$status, $scheduledAt] = $this->resolveActionStatus($data['action'], $data['scheduled_at'] ?? null);
        if ($status === 'invalid_schedule') {
            return back()->withErrors(['scheduled_at' => 'Scheduled time must be in the future.'])->withInput();
        }

        $content = $this->composeContent($data['content'], $data['hashtags'] ?? '');
        $meta = array_merge((array) $post->meta, $this->collectMediaMeta($request, $user->id, $data));

        $post->update([
            'content'      => $content,
            'status'       => $status,
            'scheduled_at' => $scheduledAt,
            'meta'         => $meta,
        ]);

        if ($status === 'ready_to_publish') {
            PublishV2ContentPostJob::dispatchSync($post->id);
        } elseif ($status === 'scheduled') {
            PublishV2ContentPostJob::dispatch($post->id)->delay($scheduledAt);
        }

        return redirect()->route('content')->with('success', 'Post updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $user = auth()->user();
        $post = V2ContentPost::where('user_id', $user->id)
            ->where('organization_id', $user->current_organization_id)
            ->findOrFail($id);

        $post->delete();

        return redirect()->route('content')->with('success', 'Post deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        $deleted = V2ContentPost::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->whereIn('id', $ids)
            ->delete();

        if ($deleted === 0) {
            return back()->withErrors(['post' => 'No matching posts to delete.']);
        }

        $message = $deleted === 1 ? '1 post deleted.' : "{$deleted} posts deleted.";

        return redirect()->route('content')->with('success', $message);
    }

    public function duplicate(int $id): RedirectResponse
    {
        $user = auth()->user();
        $source = V2ContentPost::where('user_id', $user->id)
            ->where('organization_id', $user->current_organization_id)
            ->findOrFail($id);

        $copy = V2ContentPost::create([
            'user_id' => $user->id,
            'organization_id' => (int) $user->current_organization_id,
            'provider' => $source->provider ?: 'linkedin',
            'content' => $source->content,
            'status' => 'draft',
            'scheduled_at' => null,
            'published_at' => null,
            'meta' => array_merge(is_array($source->meta) ? $source->meta : [], [
                'duplicated_from' => $source->id,
                'duplicated_at' => now()->toIso8601String(),
            ]),
        ]);

        return redirect()->route('content')->with('success', 'Post duplicated as draft.');
    }

    public function publish(int $id): RedirectResponse
    {
        $user = auth()->user();
        $post = V2ContentPost::where('user_id', $user->id)
            ->where('organization_id', $user->current_organization_id)
            ->findOrFail($id);

        if (!in_array($post->status, ['draft', 'failed', 'scheduled'], true)) {
            return back()->withErrors(['post' => 'Post cannot be published now.']);
        }

        $post->update(['status' => 'ready_to_publish', 'scheduled_at' => now()]);
        PublishV2ContentPostJob::dispatchSync($post->id);

        return redirect()->route('content')->with('success', 'Post sent to LinkedIn.');
    }

    public function schedule(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $post = V2ContentPost::where('user_id', $user->id)
            ->where('organization_id', $user->current_organization_id)
            ->findOrFail($id);

        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
        ]);

        $scheduledAt = Carbon::parse($data['scheduled_at']);
        if ($scheduledAt->lte(now())) {
            return response()->json(['message' => 'Scheduled time must be in the future.'], 422);
        }

        $post->update(['status' => 'scheduled', 'scheduled_at' => $scheduledAt]);
        PublishV2ContentPostJob::dispatch($post->id)->delay($scheduledAt);

        return response()->json(['message' => 'Post scheduled.', 'data' => $post]);
    }

    /**
     * AI-assisted post generation using OpenAI (if key configured) or a smart template fallback.
     */
    public function generateAi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic'  => ['required', 'string', 'max:500'],
            'style'  => ['nullable', 'in:professional,casual,motivational,educational,storytelling'],
            'length' => ['nullable', 'in:short,medium,long'],
            'generate_image' => ['nullable', 'boolean'],
        ]);

        if (!$this->openai->isConfigured()) {
            return response()->json([
                'message' => 'OPENAI_API_KEY is missing. Add it in your .env and refresh.',
            ], 422);
        }

        $result = $this->openai->generateLinkedInPost(
            $data['topic'],
            $data['style'] ?? 'professional',
            $data['length'] ?? 'medium',
            (bool) ($data['generate_image'] ?? false),
            (int) auth()->id(),
        );

        $response = [
            'content' => $result['content'],
            'hashtags' => $result['hashtags'],
            'source' => 'openai',
        ];

        if (isset($result['image'])) {
            $response['url'] = $result['image']['url'];
            $response['path'] = $result['image']['path'];
        }

        if (isset($result['image_error'])) {
            $response['image_error'] = $result['image_error'];
        }

        return response()->json($response);
    }

    public function improveAi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string'],
            'action' => ['required', 'string', 'max:60'],
        ]);

        if (!$this->openai->isConfigured()) {
            return response()->json(['message' => 'OPENAI_API_KEY is missing.'], 422);
        }

        $improved = $this->openai->improvePost($data['content'], $data['action']);
        [$content, $hashtags] = $this->splitImprovedContent($improved);

        return response()->json([
            'content' => $content,
            'hashtags' => $hashtags,
            'word_count' => str_word_count($content),
        ]);
    }

    public function rewriteAi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string'],
            'tone' => ['nullable', 'in:professional,casual,motivational,educational,storytelling'],
            'mode' => ['nullable', 'in:shorten,expand'],
        ]);

        if (!$this->openai->isConfigured()) {
            return response()->json(['message' => 'OPENAI_API_KEY is missing.'], 422);
        }

        $rewritten = $this->openai->rewritePost(
            $data['content'],
            $data['tone'] ?? 'professional',
            $data['mode'] ?? null,
        );
        [$content, $hashtags] = $this->splitImprovedContent($rewritten);

        return response()->json([
            'content' => $content,
            'hashtags' => $hashtags,
            'word_count' => str_word_count($content),
        ]);
    }

    public function generateImageAi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:700'],
        ]);

        if (!$this->openai->isConfigured()) {
            return response()->json(['message' => 'OPENAI_API_KEY is missing.'], 422);
        }

        $image = $this->openai->generateImage($data['prompt'], (int) auth()->id());
        return response()->json($image);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function collectMediaMeta(Request $request, int $userId, array $data): array
    {
        $cloudinary = app(CloudinaryMediaService::class);

        $meta = [
            'post_type' => $data['post_type'] ?? 'text',
            'image_urls' => [],
            'image_paths' => [],
            'video_url' => null,
            'video_path' => null,
            'ai_image_url' => $data['ai_image_url'] ?? null,
            'ai_image_path' => $data['ai_image_path'] ?? null,
        ];

        if ($request->hasFile('images')) {
            foreach ((array) $request->file('images') as $file) {
                if (! $file) {
                    continue;
                }
                $upload = $cloudinary->upload($file, 'content-images/u'.$userId);
                $meta['image_paths'][] = $upload['public_id'];
                $meta['image_urls'][] = $upload['secure_url'] ?: $upload['url'];
            }
        }

        if ($request->hasFile('video')) {
            $upload = $cloudinary->upload($request->file('video'), 'content-videos/u'.$userId);
            $meta['video_path'] = $upload['public_id'];
            $meta['video_url'] = $upload['secure_url'] ?: $upload['url'];
        }

        return $meta;
    }

    /**
     * @return array{0:string,1:Carbon|null}
     */
    private function resolveActionStatus(string $action, ?string $scheduledAt): array
    {
        if ($action === 'publish') {
            return ['ready_to_publish', now()];
        }
        if ($action === 'schedule' && $scheduledAt) {
            $at = Carbon::parse($scheduledAt);
            if ($at->lte(now())) {
                return ['invalid_schedule', null];
            }
            return ['scheduled', $at];
        }

        return ['draft', null];
    }

    private function composeContent(string $content, string $hashtags): string
    {
        $text = trim($content);
        $tags = trim($hashtags);
        return $tags !== '' ? $text."\n\n".$tags : $text;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitImprovedContent(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['', ''];
        }

        if (preg_match('/(?:\s|^)((?:#\w+\s*){2,})\s*$/u', $text, $matches)) {
            $hashtags = trim($matches[1]);
            $content = trim(substr($text, 0, -strlen($matches[0])));

            return [$content, $hashtags];
        }

        return [$text, ''];
    }
}
