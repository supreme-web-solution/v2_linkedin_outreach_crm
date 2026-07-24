<?php

namespace App\V2\Campaign;

use App\Models\User;
use App\Models\V2Campaign;
use App\Models\V2IntegrationAccount;
use App\V2\Integrations\Unipile\UnipileException;
use App\V2\Services\LinkedInConnectionService;
use Throwable;

class CampaignLinkedInGuard
{
    public function __construct(
        private readonly LinkedInConnectionService $linkedIn,
        private readonly CampaignActivityLogger $logger = new CampaignActivityLogger(),
    ) {}

    public function isDisconnected(Throwable|string $error): bool
    {
        if ($error instanceof Throwable) {
            if ($this->linkedIn->isDisconnectedError($error)) {
                return true;
            }

            return $this->isDisconnectedMessage($error->getMessage());
        }

        return $this->isDisconnectedMessage($error);
    }

    public function isDisconnectedMessage(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'disconnected_account')
            || str_contains($normalized, 'disconnected account');
    }

    public function isUserDisconnected(int $userId): bool
    {
        $account = $this->findLinkedInAccount($userId);

        return !$account || $account->status !== 'active';
    }

    /**
     * @return array{
     *     connected: bool,
     *     status: string,
     *     live_status: string,
     *     message: string,
     *     reconnect_url: string
     * }
     */
    public function connectionSummary(User $user): array
    {
        $account = $this->findLinkedInAccount($user->id);
        $connected = $account !== null && $account->status === 'active';
        $liveStatus = (string) ($account?->meta['live_status'] ?? $account?->status ?? 'missing');

        if ($connected) {
            $message = 'LinkedIn is connected.';
        } elseif ($account?->status === 'disconnected' || $liveStatus === 'disconnected') {
            $message = 'Your LinkedIn account is disconnected. Campaigns cannot send invites or messages until you reconnect.';
        } else {
            $message = 'Connect LinkedIn to run campaigns.';
        }

        return [
            'connected' => $connected,
            'status' => $account?->status ?? 'missing',
            'live_status' => $liveStatus,
            'message' => $message,
            'reconnect_url' => '/integrations',
        ];
    }

    public function handleDisconnect(int $userId, ?int $organizationId, ?string $reason = null): int
    {
        $account = $this->findLinkedInAccount($userId);
        if ($account && $account->status !== 'disconnected') {
            $this->linkedIn->markDisconnected($account, $reason);
        }

        $query = V2Campaign::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'running']);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $paused = 0;

        foreach ($query->get() as $campaign) {
            $meta = is_array($campaign->meta) ? $campaign->meta : [];
            $campaign->update([
                'status' => 'paused',
                'meta' => array_merge($meta, [
                    'paused_at' => now()->toIso8601String(),
                    'pause_reason' => 'linkedin_disconnected',
                    'pause_message' => 'LinkedIn disconnected. Reconnect your account to resume outreach.',
                ]),
            ]);

            $this->logger->log(
                $campaign->id,
                null,
                null,
                null,
                'paused',
                'Campaign paused — LinkedIn account disconnected. Reconnect on Integrations.',
                ['reason' => 'linkedin_disconnected'],
            );

            $paused++;
        }

        return $paused;
    }

    private function findLinkedInAccount(int $userId): ?V2IntegrationAccount
    {
        return V2IntegrationAccount::query()
            ->where('user_id', $userId)
            ->where('provider', 'linkedin')
            ->latest('id')
            ->first();
    }
}
