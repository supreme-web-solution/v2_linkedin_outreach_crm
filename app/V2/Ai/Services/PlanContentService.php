<?php

namespace App\V2\Ai\Services;

use App\V2\Services\OpenAIContentService;
use Illuminate\Support\Str;

class PlanContentService
{
    public function __construct(
        private readonly OpenAIContentService $openai,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildIcp(string $offer, ?string $website, array $competitors, string $geography, ?string $notes): array
    {
        if ($this->openai->isConfigured()) {
            try {
                $payload = $this->openai->generateAgentJson(
                    'You are a B2B sales strategist for SociFusion. Return JSON only.',
                    json_encode([
                        'task' => 'Build an Ideal Customer Profile',
                        'offer' => $offer,
                        'website' => $website,
                        'competitors' => $competitors,
                        'geography' => $geography,
                        'notes' => $notes,
                        'schema' => [
                            'industry' => 'string',
                            'company_size' => 'string',
                            'geography' => 'string',
                            'decision_maker' => 'string',
                            'likely_pain' => 'string',
                            'summary' => 'string',
                            'buying_triggers' => 'array of strings',
                            'disqualifiers' => 'array of strings',
                        ],
                    ], JSON_THROW_ON_ERROR),
                    700,
                );

                if ($payload !== []) {
                    return array_merge($this->fallbackIcp($offer, $competitors, $geography), $payload, [
                        'competitors' => $competitors,
                        'website' => $website,
                        'notes' => $notes,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->fallbackIcp($offer, $competitors, $geography, $website, $notes);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public function enrichStrategy(array $base): array
    {
        if (! $this->openai->isConfigured()) {
            return $base;
        }

        try {
            $payload = $this->openai->generateAgentJson(
                'You are Alex, SociFusion AI Sales Command Center. Return JSON only. '
                .'Default outreach is LinkedIn + Email (primary). Suggest WhatsApp, Instagram, or Telegram only when the goal or ICP clearly fits.',
                json_encode([
                    'task' => 'Refine a sales execution strategy plan',
                    'plan' => $base,
                    'schema' => [
                        'icp_notes' => 'string',
                        'preferred_channels' => 'string',
                        'target_count' => 'integer',
                        'follow_up_days' => 'integer',
                        'steps' => 'array of short action strings',
                        'risks' => 'array of strings',
                    ],
                ], JSON_THROW_ON_ERROR),
                800,
            );

            if ($payload !== []) {
                return array_merge($base, array_filter([
                    'icp_notes' => $payload['icp_notes'] ?? null,
                    'preferred_channels' => $payload['preferred_channels'] ?? null,
                    'target_count' => isset($payload['target_count']) ? (int) $payload['target_count'] : null,
                    'follow_up_days' => isset($payload['follow_up_days']) ? (int) $payload['follow_up_days'] : null,
                    'steps' => $payload['steps'] ?? null,
                    'risks' => $payload['risks'] ?? null,
                ], fn ($v) => $v !== null));
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public function enrichCampaign(array $base): array
    {
        if (! $this->openai->isConfigured()) {
            return $base;
        }

        try {
            $payload = $this->openai->generateAgentJson(
                'You are Alex, SociFusion outreach architect. Return JSON only. '
                .'Default sequence: LinkedIn + Email. Add WhatsApp/Instagram/Telegram as secondary touches when appropriate.',
                json_encode([
                    'task' => 'Refine a multi-channel outreach campaign plan',
                    'plan' => $base,
                    'schema' => [
                        'audience' => 'string',
                        'preferred_channels' => 'string',
                        'channels' => 'string',
                        'target_count' => 'integer',
                        'follow_up_days' => 'integer',
                        'sequence' => 'array of step labels',
                        'steps' => 'array of execution steps',
                    ],
                ], JSON_THROW_ON_ERROR),
                900,
            );

            if ($payload !== []) {
                return array_merge($base, array_filter([
                    'audience' => $payload['audience'] ?? null,
                    'preferred_channels' => $payload['preferred_channels'] ?? null,
                    'channels' => $payload['channels'] ?? null,
                    'target_count' => isset($payload['target_count']) ? (int) $payload['target_count'] : null,
                    'follow_up_days' => isset($payload['follow_up_days']) ? (int) $payload['follow_up_days'] : null,
                    'sequence' => $payload['sequence'] ?? null,
                    'steps' => $payload['steps'] ?? null,
                ], fn ($v) => $v !== null));
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackIcp(
        string $offer,
        array $competitors,
        string $geography,
        ?string $website = null,
        ?string $notes = null,
    ): array {
        $industry = Str::contains(Str::lower($offer), ['saas', 'software'])
            ? 'B2B SaaS'
            : 'B2B services';

        return [
            'industry' => $industry,
            'company_size' => '10–200 employees',
            'geography' => $geography,
            'decision_maker' => 'Founder, VP Sales, or Head of Growth',
            'likely_pain' => 'Inconsistent pipeline and manual prospecting',
            'summary' => "Teams that need {$offer}",
            'buying_triggers' => ['Missed quota', 'New funding', 'Hiring sales roles'],
            'disqualifiers' => ['No budget this quarter', 'Locked in competitor contract'],
            'competitors' => $competitors,
            'website' => $website,
            'notes' => $notes,
        ];
    }
}
