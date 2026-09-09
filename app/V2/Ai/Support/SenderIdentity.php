<?php

namespace App\V2\Ai\Support;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\V2\Ai\Services\AiEmployeeSettingsService;

/**
 * Resolve the human sender name Alex should sign emails/DMs with.
 */
final class SenderIdentity
{
    public static function displayName(User $user, ?int $organizationId = null): string
    {
        $orgId = $organizationId ?? (int) ($user->current_organization_id ?? 0);

        if ($orgId > 0) {
            $settings = app(AiEmployeeSettingsService::class)->for($user, $orgId);
            $fromMeta = self::fromSettingsMeta($settings);
            if ($fromMeta !== '') {
                return $fromMeta;
            }
        }

        $userName = trim((string) ($user->name ?? ''));
        if ($userName !== '' && ! self::looksLikePlaceholder($userName)) {
            return $userName;
        }

        if ($orgId > 0) {
            $fromEmail = self::fromEmailIntegration($user->id, $orgId);
            if ($fromEmail !== '') {
                return $fromEmail;
            }
        }

        return '';
    }

    public static function setPreferredName(AiEmployeeSetting $settings, string $name): void
    {
        $name = trim($name);
        $meta = is_array($settings->meta) ? $settings->meta : [];
        if ($name === '') {
            unset($meta['sender_display_name']);
        } else {
            $meta['sender_display_name'] = $name;
        }
        $settings->meta = $meta;
        $settings->save();
    }

    private static function fromSettingsMeta(AiEmployeeSetting $settings): string
    {
        $meta = is_array($settings->meta) ? $settings->meta : [];
        $name = trim((string) ($meta['sender_display_name'] ?? ''));

        return self::looksLikePlaceholder($name) ? '' : $name;
    }

    private static function fromEmailIntegration(int $userId, int $organizationId): string
    {
        $account = V2IntegrationAccount::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->where(function ($q) {
                $q->whereIn('provider', ['gmail', 'outlook', 'smtp', 'unipile_gmail', 'unipile_outlook', 'email'])
                    ->orWhere('provider', 'like', '%mail%');
            })
            ->orderByDesc('id')
            ->first();

        if (! $account) {
            return '';
        }

        $meta = is_array($account->meta) ? $account->meta : [];
        foreach (['from_name', 'display_name', 'name'] as $key) {
            $name = trim((string) ($meta[$key] ?? ''));
            if ($name !== '' && ! self::looksLikePlaceholder($name)) {
                return $name;
            }
        }

        return '';
    }

    private static function looksLikePlaceholder(string $name): bool
    {
        return $name === ''
            || preg_match('/^\[.*\]$/', $name)
            || preg_match('/^\{\{.*\}\}$/', $name)
            || strcasecmp($name, 'Your Name') === 0;
    }
}
