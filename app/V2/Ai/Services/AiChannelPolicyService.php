<?php

namespace App\V2\Ai\Services;

use App\V2\Outreach\OutreachChannelGuard;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Str;

class AiChannelPolicyService
{
    /**
     * @return list<string>
     */
    public function primaryKeys(): array
    {
        return $this->enabledKeys(config('socifusion_ai.outreach_channels.primary', ['linkedin', 'email']));
    }

    /**
     * @return list<string>
     */
    public function secondaryKeys(): array
    {
        return $this->enabledKeys(config('socifusion_ai.outreach_channels.secondary', [
            'whatsapp',
            'instagram',
            'telegram',
            'twitter',
        ]));
    }

    /**
     * @return list<string>
     */
    public function sequenceKeys(): array
    {
        return OutreachChannelRegistry::sequenceChannelKeys();
    }

    public function defaultChannelsLabel(): string
    {
        return (string) config(
            'socifusion_ai.outreach_channels.default_label',
            'LinkedIn + Email',
        );
    }

    public function agentChannelGuide(): string
    {
        $primary = $this->labelsFor($this->primaryKeys());
        $secondary = $this->labelsFor($this->secondaryKeys());

        $lines = [
            'Default outreach: '.implode(' + ', $primary).' (primary).',
        ];

        if ($secondary !== []) {
            $lines[] = 'Secondary channels when the user asks or ICP fits: '.implode(', ', $secondary).'.';
            $lines[] = 'Mention secondary channels in plans only when relevant — user must connect them on Integrations first.';
        }

        $lines[] = 'Zernio WhatsApp is Command Center control only — not prospect outreach.';

        return implode(' ', $lines);
    }

    /**
     * @return list<array{key:string, label:string, tier:string, connected:bool, enabled:bool}>
     */
    public function readiness(int $userId, OutreachChannelGuard $guard): array
    {
        $rows = [];

        foreach ($this->primaryKeys() as $key) {
            $rows[] = $this->row($key, 'primary', $userId, $guard);
        }

        foreach ($this->secondaryKeys() as $key) {
            $rows[] = $this->row($key, 'secondary', $userId, $guard);
        }

        return $rows;
    }

    /**
     * Parse channel keys mentioned in free text (plan channels string).
     *
     * @return list<string>
     */
    public function mentionedInText(string $text): array
    {
        $hay = Str::lower($text);
        $found = [];

        foreach ($this->sequenceKeys() as $key) {
            if ($this->textMentionsChannel($hay, $key)) {
                $found[] = $key;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function mentionedInPlan(array $payload): array
    {
        $text = Str::lower(implode(' ', array_filter([
            $payload['preferred_channels'] ?? '',
            $payload['channels'] ?? '',
            $payload['goal'] ?? '',
        ])));

        return $this->mentionedInText($text);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function labelsFor(array $keys): array
    {
        return array_map(
            fn (string $key) => OutreachChannelRegistry::channelLabel($key),
            $keys,
        );
    }

    /**
     * @param  list<string>  $configured
     * @return list<string>
     */
    private function enabledKeys(array $configured): array
    {
        return array_values(array_filter(
            $configured,
            fn (string $key) => OutreachChannelRegistry::isEnabled($key),
        ));
    }

    /**
     * @return array{key:string, label:string, tier:string, connected:bool, enabled:bool}
     */
    private function row(string $key, string $tier, int $userId, OutreachChannelGuard $guard): array
    {
        return [
            'key' => $key,
            'label' => OutreachChannelRegistry::channelLabel($key),
            'tier' => $tier,
            'connected' => $guard->isChannelConnected($userId, $key),
            'enabled' => OutreachChannelRegistry::isEnabled($key),
        ];
    }

    private function textMentionsChannel(string $hay, string $key): bool
    {
        if (str_contains($hay, $key)) {
            return true;
        }

        return match ($key) {
            'linkedin' => str_contains($hay, 'linked in'),
            'email' => str_contains($hay, 'mail'),
            'whatsapp' => str_contains($hay, 'whats app') || str_contains($hay, 'wa '),
            'instagram' => str_contains($hay, 'insta') || str_contains($hay, 'ig '),
            'telegram' => str_contains($hay, 'tg '),
            'twitter' => str_contains($hay, ' x ') || str_contains($hay, 'twitter'),
            default => false,
        };
    }
}
