<?php

namespace App\V2\Campaign;

use App\Models\V2Campaign;
use App\Models\V2CampaignLead;
use App\Models\V2IntegrationAccount;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class CampaignLeadProfileService
{
    public function __construct(
        private readonly ProviderManager $providerManager,
    ) {}

    /**
     * @return array{provider_id: string, profile: array<string, mixed>, source: string}
     */
    public function resolveRecipient(V2Campaign $campaign, V2CampaignLead $lead): array
    {
        $raw = trim((string) ($lead->provider_profile_id ?? ''));
        $profileUrl = trim((string) ($lead->profile_url ?? ''));

        if ($raw === '' && $profileUrl !== '') {
            if (preg_match('~linkedin\.com/in/([^/?#]+)~i', $profileUrl, $m)) {
                $raw = $m[1];
            }
        }

        if ($raw === '') {
            return ['provider_id' => '', 'profile' => [], 'source' => 'empty'];
        }

        if (preg_match('/^(ACo|ADo|ACw|AE)/i', $raw)) {
            return ['provider_id' => $raw, 'profile' => [], 'source' => 'provider_id'];
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountId((int) $campaign->user_id);
        if (!$accountId) {
            Log::warning('[Campaign] No Unipile account — using raw identifier', [
                'campaign_id' => $campaign->id,
                'lead_id' => $lead->id,
            ]);

            return ['provider_id' => $raw, 'profile' => [], 'source' => 'raw_no_account'];
        }

        try {
            $providerKey = $this->providerManager->defaultProvider();
            /** @var UnipileProvider $provider */
            $provider = $this->providerManager->profile($providerKey);

            if ($profileUrl !== '' && str_contains($profileUrl, 'linkedin.com/in/')) {
                $normalized = $provider->getProfileByUrl($profileUrl, $accountId);
                $providerId = (string) ($normalized['provider_id'] ?? $normalized['id'] ?? '');

                if ($providerId !== '') {
                    return ['provider_id' => $providerId, 'profile' => $normalized, 'source' => 'profile_url'];
                }
            }

            $resolved = $provider->resolveProviderId($raw, ['account_id' => $accountId]);
            $providerId = (string) ($resolved['provider_id'] ?? '');
            $profile = is_array($resolved['profile'] ?? null) ? $resolved['profile'] : [];

            if ($providerId === '' && $profile === []) {
                $profile = $provider->getProfileByIdentifier($raw, ['account_id' => $accountId]);
                $providerId = (string) (
                    Arr::get($profile, 'provider_id')
                    ?? Arr::get($profile, 'id')
                    ?? ''
                );
            }

            return [
                'provider_id' => $providerId !== '' ? $providerId : $raw,
                'profile' => $profile,
                'source' => $providerId !== '' ? 'unipile_resolve' : 'raw_fallback',
            ];
        } catch (Throwable $e) {
            Log::warning('[Campaign] resolveRecipient failed — using raw id', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);

            return ['provider_id' => $raw, 'profile' => [], 'source' => 'raw_error'];
        }
    }

    /**
     * Live Unipile check for 1st-degree / accepted invite.
     * Uses retrieve-profile (network_distance / is_relationship), then relations + sent-invites fallbacks.
     *
     * @return array{connected: bool, profile: array<string, mixed>, network_distance: mixed, error?: string|null, source?: string}
     */
    public function checkLiveConnection(V2Campaign $campaign, V2CampaignLead $lead): array
    {
        $resolved = $this->resolveRecipient($campaign, $lead);
        $providerId = trim((string) ($resolved['provider_id'] ?? ''));
        $profileUrl = trim((string) ($lead->profile_url ?? ''));
        $publicId = '';
        if ($profileUrl !== '' && preg_match('~linkedin\.com/in/([^/?#]+)~i', $profileUrl, $m)) {
            $publicId = rawurldecode($m[1]);
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountId((int) $campaign->user_id);
        if ($accountId === null || $accountId === '') {
            return [
                'connected' => false,
                'profile' => [],
                'network_distance' => null,
                'error' => 'no_account',
                'source' => 'none',
            ];
        }

        $profile = [];
        $error = null;
        $source = 'none';
        $networkDistance = null;
        $connected = false;

        try {
            $providerKey = $this->providerManager->defaultProvider();
            /** @var UnipileProvider $provider */
            $provider = $this->providerManager->profile($providerKey);

            $identifiers = array_values(array_unique(array_filter([
                $providerId,
                $publicId,
            ], fn ($v) => is_string($v) && $v !== '')));

            foreach ($identifiers as $identifier) {
                try {
                    $fresh = $provider->getProfileByIdentifier($identifier, [
                        'account_id' => $accountId,
                    ]);
                    if (is_array($fresh) && $fresh !== []) {
                        $profile = $fresh;
                        $source = 'profile';
                        $resolvedId = trim((string) (
                            Arr::get($fresh, 'provider_id')
                            ?? Arr::get($fresh, 'id')
                            ?? ''
                        ));
                        if ($resolvedId !== '') {
                            $providerId = $resolvedId;
                        }
                        break;
                    }
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                    if ($this->isBusyError($error)) {
                        return [
                            'connected' => false,
                            'profile' => [],
                            'network_distance' => null,
                            'error' => 'busy',
                            'source' => 'busy',
                        ];
                    }
                }
            }

            $networkDistance = $this->extractNetworkDistance($profile);
            $connected = $this->profileLooksConnected($profile, $networkDistance);

            // Unipile-recommended fallback: relations list (source for new_relation webhook).
            if (! $connected && ($providerId !== '' || $publicId !== '')) {
                if ($this->isInRecentRelations($provider, $accountId, $providerId, $publicId)) {
                    $connected = true;
                    $source = 'relations';
                    if (! $this->isFirstDegree($networkDistance)) {
                        $networkDistance = 'FIRST_DEGREE';
                    }
                }
            }

            // Invite no longer in Unipile sent list → accepted or withdrawn.
            // If profile/relations didn't already confirm, accept unless profile clearly says 3rd/out-of-network.
            if (! $connected && ($providerId !== '' || $publicId !== '')) {
                $stillPending = $this->isStillInSentInvitations($provider, $accountId, $providerId, $publicId);
                if ($stillPending === false && ! $this->isClearlyNotConnected($networkDistance)) {
                    $connected = true;
                    $source = 'sent_invites_cleared';
                    if (! $this->isFirstDegree($networkDistance)) {
                        $networkDistance = 'FIRST_DEGREE';
                    }
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Log::warning('[Campaign] Live connection check failed', [
                'lead_id' => $lead->id,
                'error' => $error,
            ]);

            if ($this->isBusyError($error)) {
                return [
                    'connected' => false,
                    'profile' => [],
                    'network_distance' => null,
                    'error' => 'busy',
                    'source' => 'busy',
                ];
            }

            return [
                'connected' => false,
                'profile' => [],
                'network_distance' => null,
                'error' => $error,
                'source' => 'error',
            ];
        }

        if ($networkDistance !== null && $networkDistance !== '') {
            $meta = is_array($lead->meta) ? $lead->meta : [];
            $meta['network_distance'] = $networkDistance;
            $meta['connection_check_at'] = now()->toIso8601String();
            $meta['connection_check_source'] = $source;
            $updates = ['meta' => $meta];
            if ($providerId !== '' && $providerId !== (string) $lead->provider_profile_id) {
                $updates['provider_profile_id'] = $providerId;
            }
            $lead->forceFill($updates)->save();
            $this->persistSourceNetworkDistance($lead, $networkDistance);
        }

        return [
            'connected' => $connected,
            'profile' => $profile,
            'network_distance' => $networkDistance,
            'error' => $error,
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function extractNetworkDistance(array $profile): mixed
    {
        return Arr::get($profile, 'network_distance')
            ?? Arr::get($profile, 'provider_data.network_distance')
            ?? Arr::get($profile, 'distance')
            ?? Arr::get($profile, 'member_distance')
            ?? Arr::get($profile, 'networkDistance');
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function profileLooksConnected(array $profile, mixed $networkDistance): bool
    {
        if ($this->isFirstDegree($networkDistance)) {
            return true;
        }

        foreach (['is_relationship', 'connected', 'is_connection'] as $key) {
            $value = Arr::get($profile, $key);
            if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
                return true;
            }
        }

        return false;
    }

    private function isBusyError(?string $message): bool
    {
        $lower = strtolower((string) $message);

        return str_contains($lower, 'busy with another action')
            || str_contains($lower, 'too many requests')
            || str_contains($lower, 'http 429');
    }

    private function isInRecentRelations(
        UnipileProvider $provider,
        string $accountId,
        string $providerId,
        string $publicId,
    ): bool {
        $needles = array_values(array_filter([$providerId, $publicId]));
        if ($needles === []) {
            return false;
        }

        $cursor = null;
        for ($page = 0; $page < 3; $page++) {
            try {
                $query = [
                    'account_id' => $accountId,
                    'limit' => 100,
                ];
                if (is_string($cursor) && $cursor !== '') {
                    $query['cursor'] = $cursor;
                }
                $response = $provider->listRelations($query);
            } catch (Throwable $e) {
                Log::debug('[Campaign] listRelations failed during invite check', [
                    'error' => $e->getMessage(),
                    'page' => $page,
                ]);

                return false;
            }

            $items = Arr::get($response, 'items');
            if (! is_array($items) || $items === []) {
                $items = Arr::get($response, 'data.items', []);
            }
            if (! is_array($items) || $items === []) {
                return false;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $candidates = [
                    Arr::get($item, 'provider_id'),
                    Arr::get($item, 'user_provider_id'),
                    Arr::get($item, 'id'),
                    Arr::get($item, 'public_identifier'),
                    Arr::get($item, 'user_public_identifier'),
                ];
                foreach ($candidates as $candidate) {
                    $value = trim((string) $candidate);
                    if ($value !== '' && in_array($value, $needles, true)) {
                        return true;
                    }
                }
            }

            $cursor = Arr::get($response, 'cursor') ?? Arr::get($response, 'data.cursor');
            if (! is_string($cursor) || $cursor === '') {
                break;
            }
        }

        return false;
    }

    /**
     * @return bool|null true=still pending, false=not in sent list, null=unknown/error
     */
    private function isStillInSentInvitations(
        UnipileProvider $provider,
        string $accountId,
        string $providerId,
        string $publicId,
    ): ?bool {
        try {
            $response = $provider->listSentInvitations([
                'account_id' => $accountId,
                'limit' => 100,
            ]);
        } catch (Throwable $e) {
            Log::debug('[Campaign] listSentInvitations failed during invite check', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $items = Arr::get($response, 'items');
        if (! is_array($items) || $items === []) {
            $items = Arr::get($response, 'data.items', []);
        }
        if (! is_array($items)) {
            return null;
        }

        $needles = array_values(array_filter([$providerId, $publicId]));
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $candidates = [
                Arr::get($item, 'invited_user_id'),
                Arr::get($item, 'provider_id'),
                Arr::get($item, 'invitee_id'),
                Arr::get($item, 'public_identifier'),
                Arr::get($item, 'invited_user_public_id'),
            ];
            foreach ($candidates as $candidate) {
                $value = trim((string) $candidate);
                if ($value !== '' && in_array($value, $needles, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function isAlreadyConnected(V2CampaignLead $lead, array $profile = []): bool
    {
        $candidates = [
            Arr::get($profile, 'network_distance'),
            Arr::get($profile, 'provider_data.network_distance'),
            Arr::get($profile, 'distance'),
            Arr::get($profile, 'member_distance'),
            // Stored import distance — may be stale; checkLiveConnection prefers a fresh fetch.
            Arr::get($lead->meta, 'network_distance'),
        ];

        foreach ($candidates as $value) {
            if ($this->isFirstDegree($value)) {
                return true;
            }
        }

        if (Arr::get($profile, 'is_relationship') === true || Arr::get($profile, 'connected') === true) {
            return true;
        }

        return false;
    }

    private function persistSourceNetworkDistance(V2CampaignLead $lead, mixed $networkDistance): void
    {
        $recordId = (int) ($lead->source_record_id ?? 0);
        if ($recordId <= 0) {
            return;
        }

        try {
            if ($lead->source_list_src === 'sn') {
                \App\Models\SnLead::query()->where('id', $recordId)->update([
                    'degree' => is_scalar($networkDistance) ? (string) $networkDistance : null,
                ]);
            } elseif ($lead->source_list_src === 'aud') {
                \App\Models\AudienceList::query()->where('id', $recordId)->update([
                    'con_distance' => is_scalar($networkDistance) ? (string) $networkDistance : null,
                ]);
            }
        } catch (Throwable $e) {
            Log::debug('[Campaign] Could not persist source network_distance', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function isAlreadyConnectedError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'already connected')
            || str_contains($lower, 'already a connection')
            || str_contains($lower, 'already in your network')
            || str_contains($lower, 'existing connection')
            || str_contains($lower, 'invitation already sent')
            || str_contains($lower, 'cannot resend');
    }

    private function isFirstDegree(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, [
            '1', '1st', 'first', 'distance_1', 'dist_1', 'f', 'first_degree',
        ], true)
            || str_contains($normalized, 'distance_1')
            || str_contains($normalized, 'first_degree')
            || $normalized === 'distance1';
    }

    private function isClearlyNotConnected(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $normalized = strtolower(trim((string) $value));

        return str_contains($normalized, 'distance_3')
            || str_contains($normalized, 'third')
            || str_contains($normalized, 'out_of_network')
            || str_contains($normalized, 'outofnetwork')
            || in_array($normalized, ['3', '3rd'], true);
    }
}
