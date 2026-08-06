<?php

namespace App\V2\Services;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Unified daily usage quotas for the analytics dashboard and UI surfaces.
 */
class DailyUsageQuotaService
{
    public function __construct(
        private readonly UnipileDailyActionLimiter $limiter,
    ) {}

    /**
     * @return array{
     *     items: list<array{
     *         key: string,
     *         label: string,
     *         description: string,
     *         used: int,
     *         limit: int,
     *         remaining: int,
     *         unlimited: bool,
     *         percent: float,
     *         at_limit: bool
     *     }>,
     *     resets_at: string
     * }
     */
    public function forUser(User $user): array
    {
        return [
            'items' => [
                $this->unipileQuota(
                    $user->id,
                    UnipileDailyActionLimiter::ACTION_INVITES,
                    'Connection invites',
                    'Invites sent through campaigns, outreach, and the extension.',
                ),
                $this->unipileQuota(
                    $user->id,
                    UnipileDailyActionLimiter::ACTION_NEW_CHATS,
                    'New LinkedIn chats',
                    'First-time chats started from Call Manager and conversation flows.',
                ),
                $this->unipileQuota(
                    $user->id,
                    UnipileDailyActionLimiter::ACTION_MESSAGES,
                    'LinkedIn messages',
                    'Follow-up messages sent in existing chats via outreach and Call Manager.',
                ),
                $this->emailEnrichmentQuota($user),
            ],
            'resets_at' => $this->resetsAt()->toIso8601String(),
        ];
    }

    /**
     * Compact invite/message caps for campaign & outreach UI notices.
     *
     * @return array{invites: array{limit: int, used: int, remaining: int, unlimited: bool, at_limit: bool}, messages: array{limit: int, used: int, remaining: int, unlimited: bool, at_limit: bool}}
     */
    public function linkedInActionQuotas(User $user): array
    {
        $bundle = $this->forUser($user);
        $items = collect($bundle['items'] ?? []);

        $pick = function (string $key) use ($items): array {
            $row = $items->firstWhere('key', $key) ?? [];

            return [
                'limit' => (int) ($row['limit'] ?? 0),
                'used' => (int) ($row['used'] ?? 0),
                'remaining' => (int) ($row['remaining'] ?? 0),
                'unlimited' => (bool) ($row['unlimited'] ?? true),
                'at_limit' => (bool) ($row['at_limit'] ?? false),
            ];
        };

        return [
            'invites' => $pick(UnipileDailyActionLimiter::ACTION_INVITES),
            'messages' => $pick(UnipileDailyActionLimiter::ACTION_MESSAGES),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     used: int,
     *     limit: int,
     *     remaining: int,
     *     unlimited: bool,
     *     percent: float,
     *     at_limit: bool
     * }
     */
    private function unipileQuota(int $userId, string $action, string $label, string $description): array
    {
        $snapshot = $this->limiter->snapshot($userId, $action);
        $limit = (int) $snapshot['limit'];
        $used = (int) $snapshot['used'];
        $unlimited = $limit <= 0;

        return $this->formatItem(
            key: $action,
            label: $label,
            description: $description,
            used: $used,
            limit: $unlimited ? 0 : $limit,
            remaining: $unlimited ? -1 : max(0, (int) $snapshot['remaining']),
            unlimited: $unlimited,
        );
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     used: int,
     *     limit: int,
     *     remaining: int,
     *     unlimited: bool,
     *     percent: float,
     *     at_limit: bool
     * }
     */
    private function emailEnrichmentQuota(User $user): array
    {
        $this->resetEmailCountIfNeeded($user);
        $user->refresh();

        $limit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        $used = (int) ($user->daily_profile_email_scraping_count ?? 0);
        $unlimited = $limit <= 0;

        return $this->formatItem(
            key: 'email_enrichment',
            label: 'Email enrichment',
            description: 'Profile lookups on lead and competitor lists. Only returns emails shared in LinkedIn Contact Info.',
            used: $used,
            limit: $unlimited ? 0 : $limit,
            remaining: $unlimited ? -1 : max(0, $limit - $used),
            unlimited: $unlimited,
        );
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     used: int,
     *     limit: int,
     *     remaining: int,
     *     unlimited: bool,
     *     percent: float,
     *     at_limit: bool
     * }
     */
    private function formatItem(
        string $key,
        string $label,
        string $description,
        int $used,
        int $limit,
        int $remaining,
        bool $unlimited,
    ): array {
        $percent = $unlimited || $limit <= 0
            ? 0.0
            : min(100.0, round(($used / $limit) * 100, 1));

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'used' => $used,
            'limit' => $limit,
            'remaining' => $remaining,
            'unlimited' => $unlimited,
            'percent' => $percent,
            'at_limit' => ! $unlimited && $remaining <= 0,
        ];
    }

    private function resetEmailCountIfNeeded(User $user): void
    {
        $today = now()->toDateString();
        $resetDate = $user->daily_profile_email_scraping_reset_at
            ? \Carbon\Carbon::parse($user->daily_profile_email_scraping_reset_at)->toDateString()
            : null;

        if ($resetDate !== $today) {
            $user->update([
                'daily_profile_email_scraping_count' => 0,
                'daily_profile_email_scraping_reset_at' => $today,
            ]);
        }
    }

    private function resetsAt(): CarbonInterface
    {
        return now()->endOfDay();
    }
}
