<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\V2\Integrations\Mindcase\MindcaseClient;
use App\V2\Outreach\OutreachChannelGuard;

/**
 * Decide which obtainable platforms to search and how to split a requested count.
 * Email and WhatsApp have no public people directory — they are not searched.
 */
class PlatformAllocationService
{
    /** Used only when the user did not specify a count — keep small so Soci does not invent "50". */
    public const DEFAULT_TOTAL = 10;

    public function __construct(
        private readonly OutreachChannelGuard $channelGuard,
        private readonly MindcaseClient $mindcase,
    ) {}

    /**
     * @param  list<string>  $searchableChannels
     * @return array{
     *     requested_total:?int,
     *     planned_total:int,
     *     used_default:bool,
     *     allocation:array<string, int>,
     *     searchable:list<string>,
     *     not_searchable:list<array{channel:string, reason:string}>,
     *     summary:string
     * }
     */
    public function plan(User $user, ?int $requestedTotal, array $searchableChannels): array
    {
        $searchable = array_values(array_unique(array_filter($searchableChannels)));
        $usedDefault = $requestedTotal === null || $requestedTotal <= 0;
        $total = $usedDefault ? self::DEFAULT_TOTAL : max(1, min(100, $requestedTotal));

        $allocation = $this->split($total, $searchable);

        return [
            'requested_total' => $requestedTotal,
            'planned_total' => array_sum($allocation),
            'used_default' => $usedDefault,
            'allocation' => $allocation,
            'searchable' => array_keys($allocation),
            'not_searchable' => $this->notSearchableNotes($user),
            'summary' => $this->summary($allocation, $usedDefault, $requestedTotal),
        ];
    }

    /**
     * @return list<string>
     */
    public function searchableChannels(User $user): array
    {
        $channels = [];

        if ($this->channelGuard->isChannelConnected($user->id, 'linkedin')) {
            $channels[] = 'linkedin';
        }

        if ($this->channelGuard->isChannelConnected($user->id, 'instagram') && $this->mindcase->configured()) {
            $channels[] = 'instagram';
        }

        return $channels;
    }

    /**
     * Prefer LinkedIn for the larger share (5 → 3 LinkedIn + 2 Instagram).
     *
     * @param  list<string>  $channels
     * @return array<string, int>
     */
    public function split(int $total, array $channels): array
    {
        $channels = array_values(array_unique($channels));
        $total = max(1, $total);

        if ($channels === []) {
            return [];
        }

        if (count($channels) === 1) {
            return [$channels[0] => $total];
        }

        $primary = in_array('linkedin', $channels, true) ? 'linkedin' : $channels[0];
        $others = array_values(array_filter($channels, fn (string $c) => $c !== $primary));
        $isLinkedInInstagramPair = count($channels) === 2
            && in_array('linkedin', $channels, true)
            && in_array('instagram', $channels, true);
        // Keep small asks LinkedIn-leaning (e.g. 5 -> 3+2), but make larger asks balanced.
        $primaryRatio = ($isLinkedInInstagramPair && $total >= 20) ? 0.5 : 0.6;
        $primaryShare = (int) max(1, ceil($total * $primaryRatio));
        $remaining = $total - $primaryShare;

        if ($remaining < count($others)) {
            $primaryShare = max(1, $total - count($others));
            $remaining = $total - $primaryShare;
        }

        $allocation = [$primary => $primaryShare];
        $perOther = intdiv($remaining, count($others));
        $extra = $remaining % count($others);

        foreach ($others as $index => $channel) {
            $count = $perOther + ($index < $extra ? 1 : 0);
            if ($count > 0) {
                $allocation[$channel] = $count;
            }
        }

        return $allocation;
    }

    /**
     * @return list<array{channel:string, reason:string}>
     */
    private function notSearchableNotes(User $user): array
    {
        $notes = [];

        if ($this->channelGuard->isChannelConnected($user->id, 'email') || true) {
            $notes[] = [
                'channel' => 'email',
                'reason' => 'No public people search. Use only when the user pastes emails, or after enrichment.',
            ];
        }

        if ($this->channelGuard->isChannelConnected($user->id, 'whatsapp')) {
            $notes[] = [
                'channel' => 'whatsapp',
                'reason' => 'Connected, but there is no public directory. Use only when the user pastes phone numbers.',
            ];
        } else {
            $notes[] = [
                'channel' => 'whatsapp',
                'reason' => 'Not used for discovery unless the user provides phone numbers.',
            ];
        }

        if (! $this->channelGuard->isChannelConnected($user->id, 'instagram')) {
            $notes[] = [
                'channel' => 'instagram',
                'reason' => 'Instagram is not connected, so it is excluded from this search.',
            ];
        } elseif (! $this->mindcase->configured()) {
            $notes[] = [
                'channel' => 'instagram',
                'reason' => 'Instagram is connected, but Mindcase search is not configured.',
            ];
        }

        return $notes;
    }

    /**
     * @param  array<string, int>  $allocation
     */
    private function summary(array $allocation, bool $usedDefault, ?int $requestedTotal): string
    {
        if ($allocation === []) {
            return 'No searchable platforms are connected. Connect LinkedIn and/or Instagram, then ask again.';
        }

        $parts = [];
        foreach ($allocation as $channel => $count) {
            $parts[] = $count.' '.ucfirst($channel);
        }

        $lead = $usedDefault
            ? 'No count was given, so I used a small default of '.array_sum($allocation).' prospects'
            : 'You asked for '.(int) $requestedTotal;

        $campaignNote = count($allocation) > 1
            ? ' If you ask for outreach later, one campaign is created per platform.'
            : '';

        return $lead.'. Searching connected platforms: '.implode(', ', $parts)
            .'. Email and WhatsApp are not searched unless you provide addresses or phone numbers.'
            .$campaignNote;
    }
}
