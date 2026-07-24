<?php

namespace App\V2\Services;

use App\Models\V2OutreachCampaign;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Outreach\OutreachSequenceResolver;
use Illuminate\Support\Arr;

class OutreachChannelInboxSettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'ai_context' => '',
            'auto_reply_enabled' => false,
            'pause_on_reply' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forCampaignChannel(V2OutreachCampaign $campaign, string $channel): array
    {
        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $stored = Arr::get($meta, "channel_inbox.{$channel}", []);

        if (! is_array($stored)) {
            $stored = [];
        }

        return array_merge($this->defaults(), $stored);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveCampaignChannel(V2OutreachCampaign $campaign, string $channel, array $settings): V2OutreachCampaign
    {
        $this->assertInboxChannel($channel);

        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $channelInbox = is_array($meta['channel_inbox'] ?? null) ? $meta['channel_inbox'] : [];

        $channelInbox[$channel] = [
            'ai_context' => trim((string) ($settings['ai_context'] ?? '')),
            'auto_reply_enabled' => (bool) ($settings['auto_reply_enabled'] ?? false),
            'pause_on_reply' => (bool) ($settings['pause_on_reply'] ?? true),
        ];

        $meta['channel_inbox'] = $channelInbox;
        $campaign->forceFill(['meta' => $meta])->save();

        return $campaign->fresh();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allForCampaign(V2OutreachCampaign $campaign): array
    {
        $result = [];
        foreach ($this->inboxChannelsForCampaign($campaign) as $channel) {
            $result[$channel] = $this->forCampaignChannel($campaign, $channel);
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function inboxChannelsForCampaign(V2OutreachCampaign $campaign): array
    {
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $required = OutreachChannelRegistry::requiredChannelsForNodes($nodes);

        return array_values(array_unique($required));
    }

    /**
     * @return array<int, string>
     */
    public static function validInboxChannels(): array
    {
        return ['linkedin', 'whatsapp', 'instagram', 'telegram', 'twitter', 'email'];
    }

    public function assertInboxChannel(string $channel): void
    {
        if (! in_array($channel, self::validInboxChannels(), true)) {
            throw new \InvalidArgumentException("Unsupported inbox channel: {$channel}");
        }
    }

    public function aiContextFor(?V2OutreachCampaign $campaign, string $channel): string
    {
        if (! $campaign) {
            return '';
        }

        return trim((string) ($this->forCampaignChannel($campaign, $channel)['ai_context'] ?? ''));
    }

    public function autoReplyEnabled(?V2OutreachCampaign $campaign, string $channel): bool
    {
        if (! $campaign) {
            return false;
        }

        return (bool) ($this->forCampaignChannel($campaign, $channel)['auto_reply_enabled'] ?? false);
    }

    public function pauseOnReply(?V2OutreachCampaign $campaign, string $channel): bool
    {
        if (! $campaign) {
            return true;
        }

        return (bool) ($this->forCampaignChannel($campaign, $channel)['pause_on_reply'] ?? true);
    }
}
