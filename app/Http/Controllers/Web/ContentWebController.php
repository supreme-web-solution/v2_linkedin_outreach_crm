<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\V2\PublishV2ContentPostJob;
use App\Models\V2ContentPost;
use App\Models\V2InspirationPost;
use App\Models\V2IntegrationAccount;
use App\V2\Services\OpenAIContentService;
use App\V2\Services\RapidApiLinkedinService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ContentWebController extends Controller
{
    public function __construct(
        private readonly OpenAIContentService $openai,
        private readonly RapidApiLinkedinService $rapidApi,
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

        $inspiration = $orgId
            ? V2InspirationPost::where('organization_id', $orgId)->latest()->limit(30)->get()
            : collect();

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
            'inspiration'  => $inspiration,
            'contentStats' => $stats,
            'hasOrg'       => (bool) $orgId,
            'filters'      => [
                'search' => $search !== '' ? $search : null,
                'status' => $status !== '' ? $status : null,
            ],
            'hasLinkedIn'  => $hasLinkedIn,
            'templates'    => $this->defaultTemplates(),
            'aiConfigured' => $this->openai->isConfigured(),
            'rapidConfigured' => $this->rapidApi->isConfigured(),
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

        if ($post->status === 'published') {
            return back()->withErrors(['post' => 'Published posts cannot be deleted.']);
        }

        $post->delete();

        return redirect()->route('content')->with('success', 'Post deleted.');
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
        );

        return response()->json($result + ['source' => 'openai']);
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
        return response()->json(['content' => $improved, 'word_count' => str_word_count($improved)]);
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

        return response()->json(['content' => $rewritten, 'word_count' => str_word_count($rewritten)]);
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

    public function templates(): JsonResponse
    {
        return response()->json(['templates' => $this->defaultTemplates()]);
    }

    public function fetchInspiration(Request $request): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        if (!$this->rapidApi->isConfigured()) {
            return response()->json(['message' => 'RAPIDAPI_KEY is missing.'], 422);
        }

        $items = $this->rapidApi->searchPosts($data['keyword'], 1, $data['limit'] ?? 18, 'Past month');
        $saved = [];
        foreach ($items as $item) {
            $row = V2InspirationPost::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'organization_id' => $orgId,
                    'source' => 'linkedin',
                    'post_id' => $item['post_id'] ?: Str::slug($item['author_name']).'_'.substr(md5($item['content']), 0, 10),
                ],
                [
                    'content' => $item['content'],
                    'meta' => [
                        'author_name' => $item['author_name'],
                        'author_headline' => $item['author_headline'],
                        'author_profile_url' => $item['author_profile_url'],
                        'post_url' => $item['post_url'],
                        'likes' => $item['likes'],
                        'comments' => $item['comments'],
                        'shares' => $item['shares'],
                        'views' => $item['views'],
                        'posted' => $item['posted'],
                        'images' => $item['images'],
                        'video' => $item['video'],
                    ],
                ]
            );
            $saved[] = $row;
        }

        return response()->json(['data' => $saved, 'count' => count($saved)]);
    }

    public function useInspiration(int $id): JsonResponse
    {
        $row = $this->findOwnedInspiration($id);
        return response()->json([
            'content' => (string) ($row->content ?? ''),
            'author' => (string) ($row->meta['author_name'] ?? 'Unknown'),
        ]);
    }

    public function remixInspiration(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tone' => ['nullable', 'in:professional,casual,motivational,educational,storytelling'],
        ]);

        if (!$this->openai->isConfigured()) {
            return response()->json(['message' => 'OPENAI_API_KEY is missing.'], 422);
        }

        $row = $this->findOwnedInspiration($id);
        $remix = $this->openai->rewritePost((string) ($row->content ?? ''), $data['tone'] ?? 'professional');

        return response()->json([
            'content' => $remix,
            'author' => (string) ($row->meta['author_name'] ?? 'Unknown'),
            'word_count' => str_word_count($remix),
        ]);
    }

    private function findOwnedInspiration(int $id): V2InspirationPost
    {
        $user = auth()->user();
        return V2InspirationPost::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->where('organization_id', (int) $user->current_organization_id)
            ->firstOrFail();
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function collectMediaMeta(Request $request, int $userId, array $data): array
    {
        $meta = [
            'post_type' => $data['post_type'] ?? 'text',
            'image_urls' => [],
            'video_url' => null,
            'ai_image_url' => $data['ai_image_url'] ?? null,
        ];

        if ($request->hasFile('images')) {
            foreach ((array) $request->file('images') as $file) {
                if (!$file) {
                    continue;
                }
                $path = $file->store('content-images/u'.$userId, 'public');
                $meta['image_urls'][] = Storage::disk('public')->url($path);
            }
        }

        if ($request->hasFile('video')) {
            $path = $request->file('video')->store('content-videos/u'.$userId, 'public');
            $meta['video_url'] = Storage::disk('public')->url($path);
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
     * @return array<int, array<string,mixed>>
     */
    private function defaultTemplates(): array
    {
        return [
            [
                'id' => 1,
                'title' => 'Contrarian Hook',
                'category' => 'engagement',
                'industry' => 'general',
                'engagement_score' => 92,
                'content' => "Unpopular opinion: {topic} is being done wrong by most teams.\n\nHere is what actually works:\n\n1) ...\n2) ...\n3) ...\n\nWhat would you add?",
            ],
            [
                'id' => 2,
                'title' => 'Story + Lesson',
                'category' => 'storytelling',
                'industry' => 'general',
                'engagement_score' => 88,
                'content' => "A year ago, I learned this about {topic} the hard way.\n\n[Short story]\n\nLesson:\n...\n\nIf I had to start over, I would...",
            ],
            [
                'id' => 3,
                'title' => 'Educational 3-Point',
                'category' => 'educational',
                'industry' => 'general',
                'engagement_score' => 85,
                'content' => "3 things every founder should know about {topic}:\n\n• ...\n• ...\n• ...\n\nSave this for later.",
            ],
            [
                'id' => 4,
                'title' => 'Soft CTA Lead Gen',
                'category' => 'sales',
                'industry' => 'b2b',
                'engagement_score' => 86,
                'content' => "If you're struggling with {topic}, this framework might help:\n\n[Framework]\n\nIf useful, I can send a one-page version.",
            ],
        ];
    }
}
