<?php

namespace App\Jobs\V2;

use App\Models\User;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Services\OwnerAttentionDigestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PostOwnerAttentionDigestsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(
        public readonly string $slot = 'morning',
    ) {
        $this->onQueue((string) config('socifusion_ai.attention_digest.queue_name', 'default'));
    }

    public function handle(
        OwnerAttentionDigestService $digests,
        AiEmployeeSettingsService $settingsService,
    ): void {
        if (! (bool) config('socifusion_ai.attention_digest.enabled', true)) {
            return;
        }

        $trigger = in_array($this->slot, ['morning', 'evening'], true) ? $this->slot : 'morning';
        $posted = 0;

        User::query()
            ->whereNotNull('current_organization_id')
            ->orderBy('id')
            ->chunkById(50, function ($users) use ($digests, $settingsService, $trigger, &$posted) {
                foreach ($users as $user) {
                    $orgId = (int) ($user->current_organization_id ?? 0);
                    if ($orgId <= 0) {
                        continue;
                    }

                    $settings = $settingsService->for($user, $orgId);
                    if ($settingsService->isBlocked($settings)) {
                        continue;
                    }

                    try {
                        if ($digests->maybePost($user, $orgId, $trigger)) {
                            $posted++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[Soci] Attention digest failed', [
                            'user_id' => $user->id,
                            'organization_id' => $orgId,
                            'slot' => $trigger,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($posted > 0) {
            Log::info('[Soci] Attention digests posted', ['count' => $posted, 'slot' => $trigger]);
        }
    }
}
