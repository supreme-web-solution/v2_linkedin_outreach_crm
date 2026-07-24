<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2ContentPost;
use App\Models\V2MiniStat;
use App\Models\V2UserActivity;
use App\V2\Services\MiniStatsSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    public function __construct(
        private readonly MiniStatsSyncService $miniStatsSync,
    ) {}

    public function storeMiniStats(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'connections' => ['required', 'integer', 'min:0'],
            'sent_invites' => ['required', 'integer', 'min:0'],
            'profile_views' => ['required', 'integer', 'min:-100000', 'max:100000'],
            'profile_id' => ['nullable', 'string', 'max:191'],
        ]);

        $row = V2MiniStat::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'connections' => $data['connections'],
            'sent_invites' => $data['sent_invites'],
            'profile_views' => $data['profile_views'],
            'profile_id' => $data['profile_id'] ?? null,
        ]);

        return response()->json(['data' => $row], 201);
    }

    public function storeUserActivity(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'module' => ['required', 'string', 'max:100'],
            'stat' => ['required', 'integer'],
            'identifier' => ['nullable', 'string', 'max:191'],
            'meta' => ['nullable', 'array'],
        ]);

        $row = V2UserActivity::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'module' => $data['module'],
            'stat' => $data['stat'],
            'identifier' => $data['identifier'] ?? null,
            'meta' => $data['meta'] ?? [],
        ]);

        return response()->json(['data' => $row], 201);
    }

    public function syncMiniStats(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        try {
            $synced = $this->miniStatsSync->syncForUser($user, $organizationId);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $response = $this->summary($request);
        $data = $response->getData(true);
        if (is_array($data['mini_stats'] ?? null)) {
            $data['mini_stats']['connections_at_least'] = $synced['connections_at_least'];
            $data['mini_stats']['sent_invites_at_least'] = $synced['sent_invites_at_least'];
        }

        return response()->json($data);
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $latestMini = V2MiniStat::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->latest('id')
            ->first();

        $liveProfileViews = $this->miniStatsSync->countProfileViews($user->id, $organizationId);

        $miniStats = null;
        if ($latestMini) {
            $miniStats = $latestMini->toArray();
            $miniStats['profile_views'] = $liveProfileViews;
        }

        $activityByModule = V2UserActivity::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->select('module', DB::raw('SUM(stat) as total_stat'), DB::raw('COUNT(*) as events'))
            ->groupBy('module')
            ->get();

        return response()->json([
            'mini_stats' => $miniStats,
            'activity_modules' => $activityByModule,
        ]);
    }

    public function contentAnalytics(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $days = (int) $request->query('days', 14);
        if ($days < 1) {
            $days = 1;
        }
        if ($days > 90) {
            $days = 90;
        }

        $since = now()->subDays($days);

        $posts = V2ContentPost::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $since)
            ->get();

        $totals = [
            'total_posts' => $posts->count(),
            'draft_posts' => $posts->where('status', 'draft')->count(),
            'scheduled_posts' => $posts->where('status', 'scheduled')->count(),
            'published_posts' => $posts->where('status', 'published')->count(),
        ];

        $daily = $posts
            ->groupBy(fn (V2ContentPost $post) => $post->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'))
            ->map(function ($dayPosts, $day) {
                return [
                    'date' => $day,
                    'total' => $dayPosts->count(),
                    'published' => $dayPosts->where('status', 'published')->count(),
                    'scheduled' => $dayPosts->where('status', 'scheduled')->count(),
                    'draft' => $dayPosts->where('status', 'draft')->count(),
                ];
            })
            ->values()
            ->all();

        $activity = V2UserActivity::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('module', 'content')
            ->where('created_at', '>=', $since)
            ->select(
                DB::raw('COUNT(*) as events'),
                DB::raw('COALESCE(SUM(stat), 0) as total_stat')
            )
            ->first();

        return response()->json([
            'window_days' => $days,
            'totals' => $totals,
            'daily' => $daily,
            'content_activity' => [
                'events' => (int) ($activity->events ?? 0),
                'total_stat' => (int) ($activity->total_stat ?? 0),
            ],
        ]);
    }

    public function contentCohorts(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $days = (int) $request->query('days', 30);
        if ($days < 7) {
            $days = 7;
        }
        if ($days > 180) {
            $days = 180;
        }

        $since = now()->subDays($days);
        $posts = V2ContentPost::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $since)
            ->get();

        $cohorts = $posts
            ->groupBy(fn (V2ContentPost $post) => $post->created_at?->startOfWeek()->format('Y-m-d') ?? now()->startOfWeek()->format('Y-m-d'))
            ->map(function ($cohortPosts, $cohortStart) {
                $published = $cohortPosts->where('status', 'published')->count();
                $total = $cohortPosts->count();
                $metaEngagement = $cohortPosts->sum(function (V2ContentPost $post) {
                    $meta = is_array($post->meta) ? $post->meta : [];
                    return (int) ($meta['engagement_score'] ?? 0);
                });

                return [
                    'cohort_start' => $cohortStart,
                    'posts' => $total,
                    'published' => $published,
                    'publish_rate' => $total > 0 ? round(($published / $total) * 100, 2) : 0.0,
                    'engagement_score' => $metaEngagement,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'window_days' => $days,
            'cohorts' => $cohorts,
        ]);
    }

    public function contentAttribution(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $days = (int) $request->query('days', 30);
        if ($days < 1) {
            $days = 1;
        }
        if ($days > 180) {
            $days = 180;
        }

        $since = now()->subDays($days);
        $posts = V2ContentPost::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $since)
            ->get();

        $channels = [];
        $funnel = [
            'impressions' => 0,
            'clicks' => 0,
            'leads' => 0,
            'conversions' => 0,
        ];
        $models = [
            'last_touch' => [],
            'first_touch' => [],
            'linear' => [],
        ];

        foreach ($posts as $post) {
            $meta = is_array($post->meta) ? $post->meta : [];
            $channel = (string) ($meta['channel'] ?? $post->provider ?? 'linkedin');
            $impressions = (int) ($meta['impressions'] ?? 0);
            $clicks = (int) ($meta['clicks'] ?? 0);
            $leads = (int) ($meta['leads'] ?? 0);
            $conversions = (int) ($meta['conversions'] ?? 0);
            $touchpoints = $this->extractTouchpointChannels($meta, $channel);

            if (!isset($channels[$channel])) {
                $channels[$channel] = [
                    'channel' => $channel,
                    'posts' => 0,
                    'impressions' => 0,
                    'clicks' => 0,
                    'leads' => 0,
                    'conversions' => 0,
                ];
            }

            $channels[$channel]['posts']++;
            $channels[$channel]['impressions'] += $impressions;
            $channels[$channel]['clicks'] += $clicks;
            $channels[$channel]['leads'] += $leads;
            $channels[$channel]['conversions'] += $conversions;

            $funnel['impressions'] += $impressions;
            $funnel['clicks'] += $clicks;
            $funnel['leads'] += $leads;
            $funnel['conversions'] += $conversions;

            // Last touch attribution
            $lastTouchChannel = $touchpoints[count($touchpoints) - 1] ?? $channel;
            $models['last_touch'][$lastTouchChannel] = ($models['last_touch'][$lastTouchChannel] ?? 0) + $conversions;

            // First touch attribution
            $firstTouchChannel = $touchpoints[0] ?? $channel;
            $models['first_touch'][$firstTouchChannel] = ($models['first_touch'][$firstTouchChannel] ?? 0) + $conversions;

            // Linear attribution over all known touchpoints
            $share = count($touchpoints) > 0 ? $conversions / count($touchpoints) : (float) $conversions;
            foreach ($touchpoints as $touchpointChannel) {
                $models['linear'][$touchpointChannel] = round(($models['linear'][$touchpointChannel] ?? 0) + $share, 4);
            }
        }

        $channelRows = array_values(array_map(function ($row) {
            $ctr = $row['impressions'] > 0 ? round(($row['clicks'] / $row['impressions']) * 100, 2) : 0.0;
            $leadRate = $row['clicks'] > 0 ? round(($row['leads'] / $row['clicks']) * 100, 2) : 0.0;
            $conversionRate = $row['leads'] > 0 ? round(($row['conversions'] / $row['leads']) * 100, 2) : 0.0;

            return $row + [
                'ctr' => $ctr,
                'lead_rate' => $leadRate,
                'conversion_rate' => $conversionRate,
            ];
        }, $channels));

        $funnelRates = [
            'ctr' => $funnel['impressions'] > 0 ? round(($funnel['clicks'] / $funnel['impressions']) * 100, 2) : 0.0,
            'lead_rate' => $funnel['clicks'] > 0 ? round(($funnel['leads'] / $funnel['clicks']) * 100, 2) : 0.0,
            'conversion_rate' => $funnel['leads'] > 0 ? round(($funnel['conversions'] / $funnel['leads']) * 100, 2) : 0.0,
        ];

        $attributionModels = [
            'last_touch' => $this->normalizeModelRows($models['last_touch']),
            'first_touch' => $this->normalizeModelRows($models['first_touch']),
            'linear' => $this->normalizeModelRows($models['linear']),
        ];

        return response()->json([
            'window_days' => $days,
            'channels' => $channelRows,
            'funnel' => $funnel,
            'funnel_rates' => $funnelRates,
            'attribution_models' => $attributionModels,
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<int, string>
     */
    private function extractTouchpointChannels(array $meta, string $fallback): array
    {
        $touchpoints = $meta['touchpoints'] ?? null;
        if (!is_array($touchpoints) || empty($touchpoints)) {
            return [$fallback];
        }

        $channels = [];
        foreach ($touchpoints as $touchpoint) {
            if (is_string($touchpoint)) {
                $channel = trim($touchpoint);
                if ($channel !== '') {
                    $channels[] = $channel;
                }
                continue;
            }

            if (is_array($touchpoint)) {
                $channel = trim((string) ($touchpoint['channel'] ?? ''));
                if ($channel !== '') {
                    $channels[] = $channel;
                }
            }
        }

        return empty($channels) ? [$fallback] : $channels;
    }

    /**
     * @param array<string, float|int> $model
     * @return array<int, array<string, mixed>>
     */
    private function normalizeModelRows(array $model): array
    {
        $rows = [];
        foreach ($model as $channel => $value) {
            $rows[] = [
                'channel' => (string) $channel,
                'conversion_credit' => round((float) $value, 4),
            ];
        }

        usort($rows, function (array $left, array $right): int {
            return $right['conversion_credit'] <=> $left['conversion_credit'];
        });

        return $rows;
    }
}
