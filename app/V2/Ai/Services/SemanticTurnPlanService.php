<?php

namespace App\V2\Ai\Services;

use App\Ai\Agents\SemanticTurnPlanAgent;
use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Governed LLM semantic interpretation — meaning only, no tools or DB writes.
 * Uses Laravel AI HasStructuredOutput + provider failover (not raw OpenAI JSON HTTP).
 */
class SemanticTurnPlanService
{
    /** @var array{key:string,value:?array}|null */
    private ?array $interpretCache = null;

    public function __construct(
        private readonly SemanticTurnPlanNormalizer $normalizer,
        private readonly AiActionLogService $actionLogs,
        private readonly AiProviderChain $providers,
    ) {}

    /**
     * @return array{semantic:array<string,mixed>,enforcement:array<string,mixed>}|null
     */
    public function interpret(
        User $user,
        int $organizationId,
        string $message,
        ?AiConversation $conversation = null,
    ): ?array {
        $trimmed = trim($message);
        $cacheKey = ($conversation?->id ?? 0).'|'.hash('sha256', $trimmed);
        if ($this->interpretCache !== null && $this->interpretCache['key'] === $cacheKey) {
            return $this->interpretCache['value'];
        }

        $result = $this->interpretFresh($user, $organizationId, $trimmed, $conversation);
        $this->interpretCache = ['key' => $cacheKey, 'value' => $result];

        return $result;
    }

    /**
     * @return array{semantic:array<string,mixed>,enforcement:array<string,mixed>}|null
     */
    private function interpretFresh(
        User $user,
        int $organizationId,
        string $trimmed,
        ?AiConversation $conversation = null,
    ): ?array {
        if (! config('socifusion_ai.semantic_turn_planner', true)) {
            Log::info('[Soci] Semantic planner disabled — regex fallback', [
                'user_id' => $user->id,
            ]);

            return null;
        }

        $chain = $this->providers->forAgent();
        if ($chain === []) {
            Log::warning('[Soci] Semantic planner unavailable — no LLM provider configured', [
                'user_id' => $user->id,
            ]);

            return null;
        }

        if ($trimmed === '') {
            return null;
        }

        $started = hrtime(true);
        $plannerInput = $this->plannerInput($user, $organizationId, $trimmed, $conversation);

        try {
            $response = (new SemanticTurnPlanAgent)->prompt(
                json_encode($plannerInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                provider: $chain,
            );

            $raw = is_array($response->structured ?? null) ? $response->structured : [];

            if ($raw === []) {
                Log::warning('[Soci] Semantic planner returned empty structured output — regex fallback', [
                    'user_id' => $user->id,
                ]);

                return null;
            }

            $semantic = $this->normalizer->sanitizeSemantic($raw);
            $enforcement = $this->normalizer->toEnforcementPlan($semantic, $trimmed);

            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->actionLogs->log(
                user: $user,
                organizationId: $organizationId,
                tool: 'semantic_turn_planner',
                permission: \App\V2\Ai\Enums\AiToolPermission::Read,
                status: 'success',
                conversation: $conversation,
                input: [
                    'message' => $trimmed,
                    'thread_turns' => count($plannerInput['thread']),
                    'pending_plans_waiting' => $plannerInput['pending_plans_waiting'],
                    'provider' => 'laravel_ai_structured',
                ],
                output: ['semantic' => $semantic, 'enforcement' => [
                    'goal' => $enforcement['goal'] ?? null,
                    'required_outcome' => $enforcement['required_outcome'] ?? null,
                    'side_effect_budget' => $enforcement['side_effect_budget'] ?? null,
                ]],
                error: null,
                durationMs: $durationMs,
            );

            return [
                'semantic' => $semantic,
                'enforcement' => $enforcement,
            ];
        } catch (Throwable $e) {
            Log::warning('[Soci] Semantic turn planner failed — regex fallback', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{
     *     user_message:string,
     *     thread:list<array{role:string,content:string}>,
     *     workspace:array<string,mixed>,
     *     pending_plans_waiting:bool,
     *     recent_cold_outbound:?array<string,mixed>
     * }
     */
    public function plannerInput(
        User $user,
        int $organizationId,
        string $message,
        ?AiConversation $conversation = null,
    ): array {
        $settings = app(AiEmployeeSettingsService::class)->for($user, $organizationId);
        $workspace = app(WorkspaceContextService::class);

        return [
            'user_message' => $message,
            'thread' => $this->recentThread($conversation),
            'workspace' => [
                'business' => $workspace->businessProfile($settings)['summary'] ?? null,
                'icp' => $workspace->storedIcp($settings),
                'preferred_channels' => $workspace->workspaceGoalProfile($settings)['preferred_channels'] ?? [],
            ],
            'pending_plans_waiting' => AiActionApproval::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->where('status', 'pending')
                ->exists(),
            'recent_cold_outbound' => $this->recentColdOutbound($conversation),
        ];
    }

    /**
     * @return array{channel:?string,recipient:?string,research_url:?string,has_draft:bool}|null
     */
    private function recentColdOutbound(?AiConversation $conversation): ?array
    {
        if ($conversation === null) {
            return null;
        }

        $approval = AiActionApproval::query()
            ->where('conversation_id', $conversation->id)
            ->where(function ($q): void {
                $q->where('payload->source', 'cold_outbound')
                    ->orWhere('payload->one_shot', true);
            })
            ->orderByDesc('id')
            ->first();

        if ($approval === null || ! is_array($approval->payload)) {
            return null;
        }

        $payload = $approval->payload;
        $recipient = trim((string) (
            $payload['contact_email']
            ?? $payload['contact_phone']
            ?? $payload['linkedin_url']
            ?? $payload['instagram_handle']
            ?? $payload['telegram_handle']
            ?? $payload['twitter_handle']
            ?? $payload['audience']
            ?? ''
        ));

        return [
            'channel' => isset($payload['primary_channel']) ? (string) $payload['primary_channel'] : null,
            'recipient' => $recipient !== '' ? $recipient : null,
            'research_url' => isset($payload['research_url']) ? (string) $payload['research_url'] : null,
            'has_draft' => trim((string) ($payload['message'] ?? '')) !== '',
        ];
    }

    /**
     * @return list<array{role:string,content:string}>
     */
    private function recentThread(?AiConversation $conversation): array
    {
        if ($conversation === null) {
            return [];
        }

        return AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(12)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (AiMessage $message) => [
                'role' => (string) $message->role,
                'content' => Str::limit(trim((string) $message->content), 600, ''),
            ])
            ->filter(fn (array $row) => $row['content'] !== '')
            ->values()
            ->all();
    }
}
