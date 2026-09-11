<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Support\SenderIdentity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Update the human sender name Soci uses when signing emails/DMs.
 */
class UpdateSenderProfileTool extends GatedTool
{
    public function toolName(): string
    {
        return 'update_sender_profile';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Execute;
    }

    public function description(): Stringable|string
    {
        return 'Save the preferred sender display name for outbound emails/DMs (e.g. "William Victor"). '
            .'Use when the user says what name to sign as, or asks to change the name on messages. '
            .'Never send [Your Name] placeholders — ask for this if a signature name is needed and unknown.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'sender_display_name' => $schema->string()->required()->description(
                'Name to sign outbound messages with (empty string clears override and falls back to account name)',
            ),
        ];
    }

    protected function run(Request $request): array
    {
        $name = trim((string) ($request['sender_display_name'] ?? ''));
        $settings = app(AiEmployeeSettingsService::class)->updateForUser(
            $this->context->user,
            $this->context->organizationId,
            ['sender_display_name' => $name],
        );

        $resolved = SenderIdentity::displayName($this->context->user, $this->context->organizationId);

        return [
            'ok' => true,
            'sender_display_name' => is_array($settings->meta) ? ($settings->meta['sender_display_name'] ?? null) : null,
            'resolved_sender_name' => $resolved !== '' ? $resolved : null,
            'message' => $resolved !== ''
                ? 'Outbound messages will sign as "'.$resolved.'".'
                : 'Sender name override cleared. Ask the user for a name before signing emails, or use their account profile name.',
        ];
    }
}
