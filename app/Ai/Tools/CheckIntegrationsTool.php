<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\AiChannelPolicyService;
use App\V2\Outreach\OutreachChannelGuard;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class CheckIntegrationsTool extends GatedTool
{
    public function toolName(): string
    {
        return 'check_integrations';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Check which outreach channels are connected (LinkedIn, Email, WhatsApp, Instagram, Telegram). Use before Launch or when the user asks about Integrations.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'channels' => $schema->string()->nullable()->description('Optional plan channels string to check only what a campaign needs'),
        ];
    }

    protected function run(Request $request): array
    {
        $policy = app(AiChannelPolicyService::class);
        $guard = app(OutreachChannelGuard::class);
        $userId = $this->context->user->id;

        $readiness = $policy->readiness($userId, $guard);
        $channelsHint = trim((string) ($request['channels'] ?? ''));

        $relevantKeys = $channelsHint !== ''
            ? $policy->mentionedInText(Str::lower($channelsHint))
            : [];

        if ($relevantKeys === []) {
            $relevantKeys = array_merge($policy->primaryKeys(), $policy->secondaryKeys());
        }

        $missing = [];
        $connected = [];
        foreach ($readiness as $row) {
            $key = (string) ($row['key'] ?? '');
            if (! in_array($key, $relevantKeys, true)) {
                continue;
            }
            if (! ($row['enabled'] ?? true)) {
                continue;
            }
            if ($row['connected'] ?? false) {
                $connected[] = (string) ($row['label'] ?? $key);
            } else {
                $missing[] = (string) ($row['label'] ?? $key);
            }
        }

        $summary = $missing === []
            ? 'All required integrations are connected.'
            : 'Still need: '.implode(', ', $missing).'. Open Integrations to connect before Launch can send.';

        return [
            'integrations' => $readiness,
            'connected' => $connected,
            'missing' => $missing,
            'summary' => $summary,
            'integrations_url' => url('/integrations'),
            'launch_blocked' => $missing !== [],
            'hint' => $missing !== []
                ? 'Call check_integrations before staging outreach. Launch is blocked until missing channels are connected.'
                : null,
        ];
    }
}
