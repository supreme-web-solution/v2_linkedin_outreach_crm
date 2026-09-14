<?php

namespace App\V2\Ai\Services;

/**
 * Ordered provider => model list for Laravel AI failover.
 * Only providers with an API key are included, so an empty OpenRouter slot is skipped.
 *
 * Used by Soci Laravel AI agents (semantic planner, copy, quality, etc.).
 * Regex/heuristic fallbacks are last-resort only after this chain is exhausted.
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

        return $this->preferNonOpenaiOpenRouterCover($chain);
    }

    /**
     * @param  array<string, string|null>  $chain
     * @return array<string, string|null>
     */
    private function preferNonOpenaiOpenRouterCover(array $chain): array
    {
        if (! array_key_exists('openai', $chain) || ! array_key_exists('openrouter', $chain)) {
            return $chain;
        }

        $openrouterModel = strtolower(trim((string) ($chain['openrouter'] ?? '')));
        if ($openrouterModel === '' || ! str_starts_with($openrouterModel, 'openai/')) {
            return $chain;
        }

        $cover = trim((string) config('socifusion_ai.openrouter_non_openai_cover', 'google/gemini-2.5-flash'));
        if ($cover === '' || str_starts_with(strtolower($cover), 'openai/')) {
            return $chain;
        }

        $chain['openrouter'] = $cover;

        return $chain;
    }

    public function hasFailover(): bool
    {
        return count($this->forAgent()) > 1;
    }

    /**
     * @return list<string>
     */
    public function providerNames(): array
    {
        return array_keys($this->forAgent());
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
