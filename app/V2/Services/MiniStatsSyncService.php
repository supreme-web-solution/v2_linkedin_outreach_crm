<?php

namespace App\V2\Services;

use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\Models\V2MiniStat;
use App\Models\V2UserActivity;
use App\V2\Integrations\ProviderManager;

class MiniStatsSyncService
{
    public function __construct(
        private readonly ProviderManager $providerManager,
    ) {}

    /**
     * @return array{connections: int, sent_invites: int, profile_views: int, connections_at_least: bool, sent_invites_at_least: bool}
     */
    public function syncForUser(User $user, int $organizationId): array
    {
        $profileViews = $this->countProfileViews($user->id, $organizationId);
        $accountId = V2IntegrationAccount::activeUnipileAccountId($user->id);
        if ($accountId === null || $accountId === '') {
            $metrics = [
                'connections' => 0,
                'sent_invites' => 0,
                'profile_views' => $profileViews,
                'connections_at_least' => false,
                'sent_invites_at_least' => false,
            ];

            return $this->persistSnapshot($user->id, $organizationId, $metrics);
        }

        $providerKey = $this->providerManager->defaultProvider();
        [$connections, $connectionsAtLeast] = $this->countRelations($providerKey, $accountId);
        [$sentInvites, $sentInvitesAtLeast] = $this->countSentInvitations($providerKey, $accountId);

        return $this->persistSnapshot($user->id, $organizationId, [
            'connections' => $connections,
            'sent_invites' => $sentInvites,
            'profile_views' => $profileViews,
            'connections_at_least' => $connectionsAtLeast,
            'sent_invites_at_least' => $sentInvitesAtLeast,
        ]);
    }

    public function countProfileViews(int $userId, int $organizationId): int
    {
        return (int) V2UserActivity::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->where(function ($query): void {
                $query->where(function ($inner): void {
                    $inner->where('module', 'outreach')
                        ->whereIn('identifier', ['view_profile', 'profile-view', 'profile_view']);
                })->orWhere(function ($inner): void {
                    $inner->where('module', 'campaign')
                        ->where('identifier', 'profile-view');
                });
            })
            ->sum('stat');
    }

    /**
     * @param array{connections: int, sent_invites: int, profile_views: int, connections_at_least?: bool, sent_invites_at_least?: bool} $metrics
     * @return array{connections: int, sent_invites: int, profile_views: int, connections_at_least: bool, sent_invites_at_least: bool}
     */
    private function persistSnapshot(int $userId, int $organizationId, array $metrics): array
    {
        V2MiniStat::query()->create([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'connections' => max(0, (int) $metrics['connections']),
            'sent_invites' => max(0, (int) $metrics['sent_invites']),
            'profile_views' => (int) $metrics['profile_views'],
        ]);

        return [
            'connections' => max(0, (int) $metrics['connections']),
            'sent_invites' => max(0, (int) $metrics['sent_invites']),
            'profile_views' => (int) $metrics['profile_views'],
            'connections_at_least' => (bool) ($metrics['connections_at_least'] ?? false),
            'sent_invites_at_least' => (bool) ($metrics['sent_invites_at_least'] ?? false),
        ];
    }

    /**
     * @return array{0: int, 1: bool}
     */
    private function countRelations(string $providerKey, string $accountId): array
    {
        $result = $this->providerManager->profile($providerKey)->listRelations([
            'account_id' => $accountId,
            'limit' => 100,
        ]);

        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $cursor = is_string($result['cursor'] ?? null) && $result['cursor'] !== '' ? $result['cursor'] : null;

        return [count($items), $cursor !== null];
    }

    /**
     * @return array{0: int, 1: bool}
     */
    private function countSentInvitations(string $providerKey, string $accountId): array
    {
        $result = $this->providerManager->invitation($providerKey)->listSentInvitations([
            'account_id' => $accountId,
            'limit' => 100,
        ]);

        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $cursor = is_string($result['cursor'] ?? null) && $result['cursor'] !== '' ? $result['cursor'] : null;

        return [count($items), $cursor !== null];
    }
}
