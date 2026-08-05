<?php

namespace App\V2\Outreach;

use App\Models\V2IntegrationAccount;
use App\Models\V2OutreachCampaign;
use App\V2\Integrations\ProviderManager;
use App\V2\Services\LinkedInConnectionService;
use Throwable;

class OutreachChannelGuard
{
    public function __construct(
        private readonly LinkedInConnectionService $linkedIn,
        private readonly OutreachActivityLogger $logger,
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
            || str_contains($normalized, 'disconnected account')
            || str_contains($normalized, 'not connected')
            || str_contains($normalized, 'account not found')
            || str_contains($normalized, 'missing_credentials')
            || str_contains($normalized, 'errors/missing_credentials');
    }

    public function isChannelConnected(int $userId, string $channelKey): bool
    {
        $meta = OutreachChannelRegistry::channels()[$channelKey] ?? null;
        if ($meta === null) {
            return false;
        }

        return V2IntegrationAccount::query()
            ->where('user_id', $userId)
            ->where('provider', $meta['integration_provider'])
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public function missingChannels(int $userId, array $nodeModel): array
    {
        $required = OutreachChannelRegistry::requiredChannelsForNodes($nodeModel);
        $missing = [];

        foreach ($required as $channel) {
            if (! $this->isChannelConnected($userId, $channel)) {
                $missing[] = $channel;
            }
        }

        return $missing;
    }

    public function handleChannelDisconnect(int $userId, ?int $organizationId, string $channelKey, ?string $reason = null): int
    {
        $meta = OutreachChannelRegistry::channels()[$channelKey] ?? null;
        if ($meta !== null) {
            $account = V2IntegrationAccount::query()
                ->where('user_id', $userId)
                ->where('provider', $meta['integration_provider'])
                ->latest('id')
                ->first();

            if ($account && $account->status !== 'disconnected') {
                $this->linkedIn->markDisconnected($account, $reason);
            }
        }

        $query = V2OutreachCampaign::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'running']);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $paused = 0;
        foreach ($query->get() as $campaign) {
            $campaignMeta = is_array($campaign->meta) ? $campaign->meta : [];
            $campaign->update([
                'status' => 'paused',
                'meta' => array_merge($campaignMeta, [
                    'paused_at' => now()->toIso8601String(),
                    'pause_reason' => 'channel_disconnected',
                    'pause_channel' => $channelKey,
                    'pause_message' => OutreachChannelRegistry::channelLabel($channelKey).' disconnected. Reconnect on Integrations.',
                ]),
            ]);

            $this->logger->log(
                $campaign->id,
                null,
                null,
                null,
                'paused',
                'Outreach paused — '.OutreachChannelRegistry::channelLabel($channelKey).' disconnected.',
                ['channel' => $channelKey],
            );

            $paused++;
        }

        return $paused;
    }
}
