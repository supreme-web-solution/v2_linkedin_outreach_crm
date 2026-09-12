<?php

namespace App\V2\Ai\Services;

use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Str;

/**
 * Enforce one outreach channel per prospect/campaign — no mixed multichannel sequences.
 */
class SingleChannelOutreachService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applyPrimaryChannel(array $payload): array
    {
        $channel = Str::lower(trim((string) ($payload['primary_channel'] ?? '')));
        if ($channel === '' || ! OutreachChannelRegistry::isEnabled($channel)) {
            return $payload;
        }

        $label = OutreachChannelRegistry::channelLabel($channel);
        $payload['primary_channel'] = $channel;
        $payload['preferred_channels'] = $label;
        $payload['channels'] = $label;
        $payload['single_channel_only'] = true;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $listRow  Discovery / audience row
     */
    public function primaryChannelFromList(array $listRow): ?string
    {
        $explicit = Str::lower(trim((string) ($listRow['primary_channel'] ?? $listRow['platform'] ?? '')));
        if (in_array($explicit, ['linkedin', 'instagram', 'email', 'whatsapp', 'telegram', 'twitter'], true)) {
            return $explicit;
        }

        $src = Str::lower((string) ($listRow['list_src'] ?? ''));
        $origin = Str::lower((string) ($listRow['origin'] ?? ''));
        $name = Str::lower(trim((string) ($listRow['list_name'] ?? '')));

        if ($origin === 'instagram_search' || ($listRow['platform'] ?? '') === 'instagram') {
            return 'instagram';
        }

        if ($src === 'csv' && ($name !== '' && (str_starts_with($name, 'ig:') || str_contains($name, 'instagram')))) {
            return 'instagram';
        }

        if ($origin === 'linkedin_search' || $src === 'sn' || $src === 'aud') {
            return 'linkedin';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveTemplateType(array $payload): ?string
    {
        $channel = Str::lower(trim((string) ($payload['primary_channel'] ?? '')));

        return match ($channel) {
            'linkedin' => 'linkedin_only',
            'instagram' => 'instagram_only',
            'email' => 'email_only',
            'whatsapp' => 'whatsapp_only',
            'telegram' => 'telegram_only',
            'twitter' => 'twitter_only',
            default => null,
        };
    }
}
