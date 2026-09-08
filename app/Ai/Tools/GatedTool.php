<?php

namespace App\Ai\Tools;

use App\V2\Ai\AgentContext;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\AiActionLogService;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

abstract class GatedTool implements Tool
{
    public function __construct(
        protected AgentContext $context,
    ) {}

    abstract public function permission(): AiToolPermission;

    abstract public function toolName(): string;

    abstract protected function run(Request $request): Stringable|string|array;

    public function handle(Request $request): Stringable|string
    {
        $started = hrtime(true);
        $settingsService = app(AiEmployeeSettingsService::class);
        $logger = app(AiActionLogService::class);
        $settings = $this->context->activeSettings();

        if (! $settingsService->mayRun($settings, $this->permission(), $this->toolName())) {
            $logger->log(
                $this->context->user,
                $this->context->organizationId,
                $this->toolName(),
                $this->permission(),
                'denied',
                $this->context->conversation,
                ['args' => $request->all()],
                null,
                'Tool blocked by autonomy level, kill switch, or allowlist.',
            );

            return json_encode([
                'ok' => false,
                'error' => 'This action is not allowed at the current autonomy level. Create a review plan or ask the user to approve.',
            ], JSON_THROW_ON_ERROR);
        }

        try {
            $result = $this->run($request);
            $payload = is_array($result) ? $result : ['message' => (string) $result];
            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

            $logger->log(
                $this->context->user,
                $this->context->organizationId,
                $this->toolName(),
                $this->permission(),
                'success',
                $this->context->conversation,
                ['args' => $request->all()],
                $payload,
                null,
                $durationMs,
            );

            return json_encode(['ok' => true, 'data' => $payload], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $logger->log(
                $this->context->user,
                $this->context->organizationId,
                $this->toolName(),
                $this->permission(),
                'error',
                $this->context->conversation,
                ['args' => $request->all()],
                null,
                $e->getMessage(),
                $durationMs,
            );

            try {
                app(\App\V2\Ai\Services\AiErrorLogService::class)->capture(
                    $e,
                    'tool:'.$this->toolName(),
                    $this->context->user,
                    $this->context->organizationId,
                    $this->context->conversation,
                    $this->context->channel,
                    null,
                    ['args' => $request->all(), 'duration_ms' => $durationMs],
                );
            } catch (Throwable) {
                // never fail the tool path because logging failed
            }

            return json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }

    abstract public function description(): Stringable|string;

    abstract public function schema(JsonSchema $schema): array;
}
