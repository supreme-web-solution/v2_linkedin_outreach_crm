<?php

namespace App\V2\Ai\Services;

use App\V2\Services\OpenAIContentService;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Laravel AI JSON generation with provider failover.
 * OpenAI generateAgentJson is emergency fallback only.
 */
class LaravelAiJsonService
{
    public function __construct(
        private readonly AiProviderChain $providers,
        private readonly OpenAIContentService $openai,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(string $system, string $userPayload, int $maxTokens = 800): array
    {
        $chain = $this->providers->forAgent();
        if ($chain !== []) {
            try {
                $response = agent(
                    instructions: $system."\n\nReturn JSON only. No markdown fences.",
                )->prompt(
                    $userPayload,
                    provider: $chain,
                    timeout: 90,
                );

                $decoded = $this->decodeJson((string) $response);
                if ($decoded !== []) {
                    return $decoded;
                }
            } catch (Throwable $e) {
                Log::warning('[Soci] LaravelAiJsonService agent failed', ['error' => $e->getMessage()]);
            }
        }

        if ($this->openai->isConfigured()) {
            try {
                return $this->openai->generateAgentJson($system, $userPayload, $maxTokens, true);
            } catch (Throwable $e) {
                Log::warning('[Soci] LaravelAiJsonService OpenAI fallback failed', ['error' => $e->getMessage()]);
            }
        }

        return [];
    }

    public function isAvailable(): bool
    {
        return $this->providers->forAgent() !== [] || $this->openai->isConfigured();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $raw = trim($raw);

        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
