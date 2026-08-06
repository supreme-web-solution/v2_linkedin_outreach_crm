<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\V2InspirationPost;
use App\Services\ChatGPT;
use App\V2\Services\RapidApiLinkedinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InspirationWebController extends Controller
{
    public function __construct(
        private readonly RapidApiLinkedinService $rapidApi,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $orgId = (int) $user->current_organization_id;

        $base = V2InspirationPost::where('organization_id', $orgId);

        $query = (clone $base);

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('content', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }
        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }
        if ($request->boolean('favorite')) {
            $query->where('is_favorite', true);
        }
        if ($request->filled('engagement')) {
            $query->where('engagement', '>=', (int) $request->integer('engagement'));
        }

        // Newest saves first so users see fresh posts without paging to the end.
        $posts = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(12)->appends($request->query());

        $avgEngagement = (clone $base)->where('engagement', '>', 0)->avg('engagement');

        $stats = [
            'total_posts' => (clone $base)->count(),
            'favorites' => (clone $base)->where('is_favorite', true)->count(),
            'viral_posts' => (clone $base)->where('engagement', '>=', 500)->count(),
            'avg_engagement' => $avgEngagement ? (int) round($avgEngagement) : 0,
        ];

        $categories = (clone $base)->whereNotNull('category')->distinct()->pluck('category')->filter()->values();

        return Inertia::render('crm/Inspiration', [
            'posts' => $posts,
            'stats' => $stats,
            'categories' => $categories,
            'filters' => [
                'search' => $request->input('search'),
                'category' => $request->input('category'),
                'favorite' => $request->boolean('favorite'),
                'engagement' => $request->input('engagement'),
            ],
            'rapidConfigured' => $this->rapidApi->isConfigured(),
        ]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $user = Auth::user();
        $orgId = (int) $user->current_organization_id;

        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:200'],
            'keep' => ['nullable', 'integer', 'min:1', 'max:30'],
            'date_posted' => ['nullable', 'in:Past 24 hours,Past week,Past month,Past year'],
        ]);

        if (! $this->rapidApi->isConfigured()) {
            return response()->json(['message' => 'RAPIDAPI_KEY is missing. Add it in your .env and refresh.'], 422);
        }

        $keep = (int) ($data['keep'] ?? 18);

        try {
            $result = $this->rapidApi->searchPosts(
                $data['keyword'],
                1,
                150,
                $data['date_posted'] ?? 'Past month',
                RapidApiLinkedinService::DISCOVERY_MAX_PAGES,
            );
        } catch (\Throwable $th) {
            return response()->json(['message' => 'Fetch failed: '.$th->getMessage()], 422);
        }

        $candidates = [];
        foreach ($result['items'] as $item) {
            if (trim((string) $item['content']) === '') {
                continue;
            }

            $engagement = $this->engagementScore(
                (int) $item['likes'],
                (int) $item['comments'],
                (int) $item['shares'],
                (int) $item['views'],
            );

            $candidates[] = array_merge($item, ['engagement' => $engagement]);
        }

        usort($candidates, fn (array $a, array $b) => $b['engagement'] <=> $a['engagement']);
        $top = array_slice($candidates, 0, $keep);

        $saved = 0;
        foreach ($top as $item) {
            $postId = $item['post_id'] ?: Str::slug($item['author_name']).'_'.substr(md5($item['content']), 0, 10);

            V2InspirationPost::updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'source' => 'linkedin',
                    'post_id' => $postId,
                ],
                [
                    'user_id' => $user->id,
                    'content' => $item['content'],
                    'category' => $this->autoCategorize($item['content']),
                    'engagement' => $item['engagement'],
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
            $saved++;
        }

        $scanned = count($candidates);

        return response()->json([
            'message' => $scanned === 0
                ? 'No posts found for that keyword.'
                : "Saved {$saved} posts to your library.",
            'count' => $saved,
        ]);
    }

    public function toggleFavorite(int $id): JsonResponse
    {
        $post = $this->owned($id);
        $post->update(['is_favorite' => ! $post->is_favorite]);

        return response()->json(['success' => true, 'is_favorite' => $post->is_favorite]);
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->owned($id)->delete();

        return back()->with('success', 'Post removed from inspiration library.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $user = Auth::user();
        $deleted = V2InspirationPost::query()
            ->where('organization_id', (int) $user->current_organization_id)
            ->whereIn('id', $data['ids'])
            ->delete();

        return back()->with('success', "Removed {$deleted} post(s) from your library.");
    }

    public function remix(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tone' => ['nullable', 'in:Formal and respectful,Neutral and professional,Casual and friendly'],
        ]);

        $post = $this->owned($id);

        try {
            $gpt = new ChatGPT(['content' => (string) $post->content, 'tone' => $data['tone'] ?? 'Neutral and professional']);
            $result = $gpt->rewritePost();

            return response()->json([
                'success' => true,
                'content' => $result['content'],
                'word_count' => $result['word_count'] ?? str_word_count($result['content']),
                'author' => (string) ($post->meta['author_name'] ?? 'Unknown'),
            ]);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], 422);
        }
    }

    private function owned(int $id): V2InspirationPost
    {
        $user = Auth::user();

        return V2InspirationPost::where('id', $id)
            ->where('organization_id', (int) $user->current_organization_id)
            ->firstOrFail();
    }

    private function engagementScore(int $likes, int $comments, int $shares, int $views): int
    {
        return $likes + ($comments * 3) + ($shares * 5) + (int) floor($views / 100);
    }

    private function autoCategorize(string $content): string
    {
        $content = strtolower($content);

        $keywords = [
            'marketing' => ['marketing', 'campaign', 'brand', 'advertising', 'seo', 'content'],
            'sales' => ['sales', 'revenue', 'closing', 'prospect', 'pipeline', 'deal'],
            'tech' => ['tech', 'software', 'coding', 'developer', 'ai', 'programming'],
            'entrepreneurship' => ['startup', 'founder', 'business', 'entrepreneur', 'venture'],
            'productivity' => ['productivity', 'time', 'efficient', 'organize', 'workflow'],
            'leadership' => ['leadership', 'team', 'management', 'culture', 'leader'],
        ];

        foreach ($keywords as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($content, $word)) {
                    return $category;
                }
            }
        }

        return 'general';
    }
}
