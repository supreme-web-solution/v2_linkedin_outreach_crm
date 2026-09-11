<?php

namespace App\V2\Ai\Services;

/**
 * Ordered provider => model list for Laravel AI failover.
 * Only providers with an API key are included, so an empty OpenRouter slot is skipped.
 */
class AiProviderChain
{
    /**
     * @return array<string, string|null>
     */
    public function forAgent(): array
    {
        $configured = config('socifusion_ai.model_failover', []);
        if (! is_array($configured)) {
            $configured = [];
        }

        $chain = [];
        foreach ($configured as $provider => $model) {
            $provider = strtolower(trim((string) $provider));
            if ($provider === '' || ! $this->hasKey($provider)) {
                continue;
            }

            $chain[$provider] = is_string($model) && trim($model) !== '' ? trim($model) : null;
        }

        if ($chain === [] && $this->hasKey('openai')) {
            $chain['openai'] = null;
        }

        return $chain;
    }

    public function hasFailover(): bool
    {
        return count($this->forAgent()) > 1;
    }

    public function hasKey(string $provider): bool
    {
        $key = match (strtolower($provider)) {
            'openai' => config('ai.providers.openai.key'),
            'openrouter' => config('ai.providers.openrouter.key'),
            'gemini' => config('ai.providers.gemini.key'),
            'groq' => config('ai.providers.groq.key'),
            'anthropic' => config('ai.providers.anthropic.key'),
            default => null,
        };

        return trim((string) $key) !== '';
    }
}
