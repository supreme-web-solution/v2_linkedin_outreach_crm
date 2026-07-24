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

        Log::info('[Campaign] resolveRecipient', [
            'campaign_id' => $campaign->id,
            'lead_id' => $lead->id,
            'raw_id' => $raw,
            'profile_url' => $profileUrl,
        ]);

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

                Log::info('[Campaign] Resolved via profile URL', [
                    'lead_id' => $lead->id,
                    'provider_id' => $providerId,
                    'network_distance' => $normalized['network_distance'] ?? null,
                ]);

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

            Log::info('[Campaign] Resolved via identifier', [
                'lead_id' => $lead->id,
                'identifier' => $raw,
                'provider_id' => $providerId,
                'network_distance' => Arr::get($profile, 'network_distance'),
            ]);

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
     * @param  array<string, mixed>  $profile
     */
    public function isAlreadyConnected(V2CampaignLead $lead, array $profile = []): bool
    {
        $candidates = [
            Arr::get($lead->meta, 'network_distance'),
            Arr::get($profile, 'network_distance'),
            Arr::get($profile, 'distance'),
            Arr::get($profile, 'member_distance'),
        ];

        foreach ($candidates as $value) {
            if ($this->isFirstDegree($value)) {
                Log::info('[Campaign] Lead is 1st-degree connection', [
                    'lead_id' => $lead->id,
                    'signal' => $value,
                ]);

                return true;
            }
        }

        if (Arr::get($profile, 'is_relationship') === true || Arr::get($profile, 'connected') === true) {
            Log::info('[Campaign] Lead profile marks connected relationship', ['lead_id' => $lead->id]);

            return true;
        }

        return false;
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
        ], true) || str_contains($normalized, 'distance_1');
    }
}
