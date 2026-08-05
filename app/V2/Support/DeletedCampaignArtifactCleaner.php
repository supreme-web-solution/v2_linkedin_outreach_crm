<?php

namespace App\V2\Support;

use App\Models\V2CampaignNodeEvent;
use App\Models\V2CampaignRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * After a campaign/outreach delete: purge leftover queued work and orphaned logs.
 * Job handlers still guard missing campaigns — this just drains waste from the queue.
 */
class DeletedCampaignArtifactCleaner
{
    public const KIND_OUTREACH = 'outreach';

    public const KIND_CAMPAIGN = 'campaign';

    /**
     * @param  array<int, int>  $campaignRunIds
     * @return array{
     *     jobs_purged: int,
     *     failed_purged: int,
     *     redis_purged: int,
     *     events_deleted: int,
     *     runs_deleted: int
     * }
     */
    public function clean(string $kind, int $campaignId, ?int $userId = null, array $campaignRunIds = []): array
    {
        $needles = $this->payloadNeedles($kind, $campaignId, $campaignRunIds);

        $jobsPurged = $this->purgeDatabaseTable('jobs', $needles);
        $failedPurged = $this->purgeDatabaseTable('failed_jobs', $needles);
        $redisPurged = $this->purgeRedisQueues($needles);

        $eventsDeleted = 0;
        $runsDeleted = 0;

        if ($kind === self::KIND_CAMPAIGN) {
            $eventsDeleted = V2CampaignNodeEvent::query()
                ->where('campaign_id', $campaignId)
                ->delete();

            $runsQuery = V2CampaignRun::query()->where('legacy_campaign_id', $campaignId);
            if ($campaignRunIds !== []) {
                $runsQuery->orWhereIn('id', $campaignRunIds);
            }
            $runsDeleted = $runsQuery->delete();
        }

        Cache::forget('outreach:concurrency-notice:'.$campaignId);
        Cache::forget('campaign:concurrency-notice:'.$campaignId);

        $summary = [
            'jobs_purged' => $jobsPurged,
            'failed_purged' => $failedPurged,
            'redis_purged' => $redisPurged,
            'events_deleted' => $eventsDeleted,
            'runs_deleted' => $runsDeleted,
        ];

        Log::info('[CampaignCleanup] Deleted campaign artifacts cleaned', [
            'kind' => $kind,
            'campaign_id' => $campaignId,
            'user_id' => $userId,
            ...$summary,
        ]);

        return $summary;
    }

    /**
     * @param  array<int, int>  $campaignRunIds
     * @return array<int, string>
     */
    public function payloadNeedles(string $kind, int $campaignId, array $campaignRunIds = []): array
    {
        $needles = [];

        if ($kind === self::KIND_OUTREACH) {
            // Database queue JSON-escapes quotes in the serialized command (`\"`).
            $needles[] = 'outreachCampaignId\\";i:'.$campaignId.';';
            $needles[] = 'outreachCampaignId";i:'.$campaignId.';';
        }

        if ($kind === self::KIND_CAMPAIGN) {
            $needles[] = 'campaignId\\";i:'.$campaignId.';';
            $needles[] = 'campaignId";i:'.$campaignId.';';

            foreach ($campaignRunIds as $runId) {
                $runId = (int) $runId;
                if ($runId <= 0) {
                    continue;
                }
                $needles[] = 'campaignRunId\\";i:'.$runId.';';
                $needles[] = 'campaignRunId";i:'.$runId.';';
            }
        }

        return array_values(array_unique($needles));
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function purgeDatabaseTable(string $table, array $needles): int
    {
        if ($needles === [] || ! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $query->where(function ($outer) use ($needles): void {
            foreach ($needles as $needle) {
                $outer->orWhere('payload', 'like', '%'.$this->escapeLike($needle).'%');
            }
        });

        return $query->delete();
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function purgeRedisQueues(array $needles): int
    {
        if ($needles === [] || (string) config('queue.default') !== 'redis') {
            return 0;
        }

        try {
            $connection = (string) config('queue.connections.redis.connection', 'default');
            $redis = Redis::connection($connection);
        } catch (Throwable $e) {
            Log::debug('[CampaignCleanup] Redis purge skipped', ['error' => $e->getMessage()]);

            return 0;
        }

        $queues = array_values(array_unique(array_filter([
            'outreach',
            'campaigns',
            'default',
            'webhooks',
            (string) config('queue.connections.redis.queue', 'default'),
        ])));

        $purged = 0;
        foreach ($queues as $queue) {
            $purged += $this->purgeRedisList($redis, 'queues:'.$queue, $needles);
            $purged += $this->purgeRedisZSet($redis, 'queues:'.$queue.':delayed', $needles);
            $purged += $this->purgeRedisZSet($redis, 'queues:'.$queue.':reserved', $needles);
        }

        return $purged;
    }

    /**
     * @param  mixed  $redis
     * @param  array<int, string>  $needles
     */
    private function purgeRedisList($redis, string $key, array $needles): int
    {
        try {
            $items = $redis->lrange($key, 0, -1);
        } catch (Throwable) {
            return 0;
        }

        if (! is_array($items) || $items === []) {
            return 0;
        }

        $kept = [];
        $removed = 0;
        foreach ($items as $item) {
            $payload = is_string($item) ? $item : (string) $item;
            if ($this->payloadMatches($payload, $needles)) {
                $removed++;
                continue;
            }
            $kept[] = $payload;
        }

        if ($removed === 0) {
            return 0;
        }

        $redis->del($key);
        if ($kept !== []) {
            // push in original order (oldest first for list queues)
            $redis->rpush($key, ...$kept);
        }

        return $removed;
    }

    /**
     * @param  mixed  $redis
     * @param  array<int, string>  $needles
     */
    private function purgeRedisZSet($redis, string $key, array $needles): int
    {
        try {
            $items = $redis->zrange($key, 0, -1);
        } catch (Throwable) {
            return 0;
        }

        if (! is_array($items) || $items === []) {
            return 0;
        }

        $removed = 0;
        foreach ($items as $value) {
            $payload = is_string($value) ? $value : (string) $value;
            if (! $this->payloadMatches($payload, $needles)) {
                continue;
            }

            try {
                $redis->zrem($key, $payload);
                $removed++;
            } catch (Throwable) {
                // ignore single-member failures
            }
        }

        return $removed;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function payloadMatches(string $payload, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($payload, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function escapeLike(string $value): string
    {
        // Keep backslashes intact so JSON-escaped quotes (`\"`) still match.
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
