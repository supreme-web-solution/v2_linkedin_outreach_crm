<?php

namespace App\V2\Ai\Services;

use App\Jobs\V2\RefreshZernioTypingIndicatorJob;
use App\Models\AiChannelIdentity;
use App\V2\Ai\Integrations\ZernioClient;
use Illuminate\Support\Facades\Cache;

class ZernioTypingIndicatorService
{
    private const CACHE_PREFIX = 'zernio_typing_active:';

    private const CACHE_TTL_SECONDS = 180;

    public function __construct(
        private readonly ZernioClient $zernio,
    ) {}

    public function begin(AiChannelIdentity $identity): void
    {
        if (! (bool) config('socifusion_ai.zernio.typing_indicator', true)) {
            return;
        }

        $identity = $identity->fresh() ?? $identity;

        if (! $this->zernio->sendTypingIndicator($identity)) {
            return;
        }

        $this->markActive($identity->id);
    }

    public function end(int $identityId): void
    {
        Cache::forget($this->cacheKey($identityId));
    }

    public function refreshIfActive(int $identityId): bool
    {
        if (! Cache::get($this->cacheKey($identityId))) {
            return false;
        }

        $identity = AiChannelIdentity::query()->find($identityId);
        if (! $identity || $identity->status !== 'active') {
            $this->end($identityId);

            return false;
        }

        $this->zernio->sendTypingIndicator($identity);
        $this->scheduleRefresh($identityId);

        return true;
    }

    private function markActive(int $identityId): void
    {
        Cache::put($this->cacheKey($identityId), true, self::CACHE_TTL_SECONDS);
        $this->scheduleRefresh($identityId);
    }

    private function scheduleRefresh(int $identityId): void
    {
        $seconds = max(5, (int) config('socifusion_ai.zernio.typing_refresh_seconds', 18));

        RefreshZernioTypingIndicatorJob::dispatch($identityId)
            ->delay(now()->addSeconds($seconds));
    }

    private function cacheKey(int $identityId): string
    {
        return self::CACHE_PREFIX.$identityId;
    }
}
