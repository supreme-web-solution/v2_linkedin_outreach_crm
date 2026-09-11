<?php

namespace App\V2\Services;

use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;

use function Laravel\Ai\agent;

/**
 * Laravel AI provider tools for web search/fetch (Anthropic, OpenAI, OpenRouter, Gemini).
 *
 * Uses the same API keys already in config/ai.php — no extra vendor signup.
 */
class LaravelAiWebResearchService
{
    /**
     * @return list<string>
     */
    public function supportedProviders(): array
    {
        return ['openai', 'anthropic', 'openrouter', 'gemini'];
    }

    /**
     * Providers that expose both WebSearch and WebFetch via Laravel AI.
     *
     * @return list<string>
     */
    public function webFetchProviders(): array
    {
        return ['anthropic', 'openrouter', 'gemini'];
    }

    public function isEnabled(): bool
    {
        return (bool) config('socifusion_ai.web_research.enabled', true)
            && $this->resolveProviderLab() !== null;
    }

    public function supportsWebFetch(): bool
    {
        $provider = $this->configuredProviderName();

        return in_array($provider, $this->webFetchProviders(), true);
    }

    /**
     * @return array{ok:bool, company:string, query:string, url:?string, title:?string, excerpt:string, source:?string, error:?string}|null
     */
    public function searchCompany(string $companyName): ?array
    {
        $companyName = trim($companyName);
        if ($companyName === '' || ! $this->isEnabled()) {
            return null;
        }

        try {
            $response = agent(
                instructions: implode("\n", [
                    'You research B2B companies for sales outreach context.',
                    'Use web search to learn what the company does, who they serve, and any growth or pain signals.',
                    'Reply with plain text only: 3-5 short factual bullets. No markdown headers, no pitch.',
                ]),
                tools: [(new WebSearch)->max((int) config('socifusion_ai.web_research.max_searches', 2))],
            )->prompt(
                "Research this company: {$companyName}",
                provider: $this->resolveProviderLab(),
                model: $this->configuredModel(),
                timeout: (int) config('socifusion_ai.web_research.timeout', 45),
            );

            $text = trim((string) $response);
            if ($text === '') {
                return null;
            }

            return [
                'ok' => true,
                'company' => $companyName,
                'query' => $companyName,
                'url' => null,
                'title' => $companyName,
                'excerpt' => Str::limit($text, 2000),
                'source' => 'laravel_ai:web_search',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return array{ok:bool, url:string, title:?string, content:string, source:?string, error:?string}|null
     */
    public function fetchUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || ! $this->isEnabled() || ! $this->supportsWebFetch()) {
            return null;
        }

        try {
            $response = agent(
                instructions: implode("\n", [
                    'Fetch the URL and summarize the page for sales prospect research.',
                    'Reply with plain text only: page title (if known) then 2-4 factual sentences about what the business does.',
                ]),
                tools: [new WebFetch],
            )->prompt(
                "Fetch and summarize this page: {$url}",
                provider: $this->resolveProviderLab(),
                model: $this->configuredModel(),
                timeout: (int) config('socifusion_ai.web_research.timeout', 45),
            );

            $text = trim((string) $response);
            if ($text === '') {
                return null;
            }

            $title = null;
            if (preg_match('/^title:\s*(.+)$/im', $text, $match)) {
                $title = trim($match[1]);
            }

            return [
                'ok' => true,
                'url' => $url,
                'title' => $title,
                'content' => Str::limit($text, 12000),
                'source' => 'laravel_ai:web_fetch',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function configuredProviderName(): string
    {
        return strtolower((string) config(
            'socifusion_ai.web_research.provider',
            config('ai.default', 'openai'),
        ));
    }

    private function configuredModel(): ?string
    {
        $model = trim((string) config('socifusion_ai.web_research.model', ''));

        return $model !== '' ? $model : null;
    }

    private function resolveProviderLab(): ?Lab
    {
        $name = $this->configuredProviderName();

        if (! in_array($name, $this->supportedProviders(), true)) {
            return null;
        }

        if (trim((string) config("ai.providers.{$name}.key")) === '') {
            return null;
        }

        return match ($name) {
            'openai' => Lab::OpenAI,
            'anthropic' => Lab::Anthropic,
            'openrouter' => Lab::OpenRouter,
            'gemini' => Lab::Gemini,
            default => null,
        };
    }
}
