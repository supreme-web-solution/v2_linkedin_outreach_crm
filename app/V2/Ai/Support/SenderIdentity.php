<?php

namespace App\V2\Ai\Support;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\Models\V2OutreachCampaign;
use App\V2\Ai\Services\AiEmployeeSettingsService;

/**
 * Resolve the human sender name Alex should sign emails/DMs with.
 *
 * Priority (highest first):
 * 1. Explicit name the user told Alex for this send/campaign
 * 2. Saved preferred sender (update_sender_profile)
 * 3. Registration / profile name
 * 4. Connected email account display name
 */
final class SenderIdentity
{
    public static function displayName(
        User $user,
        ?int $organizationId = null,
        ?V2OutreachCampaign $campaign = null,
        ?string $explicit = null,
    ): string {
        $explicit = trim((string) $explicit);
        if ($explicit !== '' && ! self::looksLikePlaceholder($explicit)) {
            return $explicit;
        }

        if ($campaign !== null) {
            $meta = is_array($campaign->meta) ? $campaign->meta : [];
            foreach (['sender_display_name', 'sender_name', 'sign_as'] as $key) {
                $fromCampaign = trim((string) ($meta[$key] ?? ''));
                if ($fromCampaign !== '' && ! self::looksLikePlaceholder($fromCampaign)) {
                    return $fromCampaign;
                }
            }

            $fromPlan = self::extractFromText(self::planTextBlob($meta['ai_plan'] ?? null));
            if ($fromPlan !== '') {
                return $fromPlan;
            }
        }

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

    /**
     * Pull a user-specified signing name from free text / plan fields.
     * Examples: "sign as William Victor", "use the name John Smith", "from: Ada Lovelace".
     */
    public static function extractFromText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }

        $patterns = [
            '/\b(?:sign(?:\s+off)?\s+as|use(?:\s+the)?\s+name|signing\s+name|sender\s+name|from(?:\s+name)?)\s*[:\-]?\s*[\"\'“”]?([A-Z][\p{L} .\'\-]{1,60})/u',
            '/\b(?:my\s+name\s+is|i\s+am)\s+[\"\'“”]?([A-Z][\p{L} .\'\-]{1,60})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $name = trim($m[1], " \t\"'“”.,;:");
                $name = preg_replace(
                    '/\b(?:please|thanks|thank you|for|and|on|in|to|the|this|that|when|while)\b.*$/iu',
                    '',
                    $name
                ) ?? $name;
                $name = trim($name, " \t\"'“”.,;:");
                // Keep at most first + middle + last (3 tokens) for signature names.
                $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (count($parts) > 3) {
                    $name = implode(' ', array_slice($parts, 0, 3));
                }
                if ($name !== '' && ! self::looksLikePlaceholder($name) && mb_strlen($name) >= 2) {
                    return $name;
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $plan
     */
    public static function extractFromPlan(?array $plan): string
    {
        if ($plan === null) {
            return '';
        }

        foreach (['sender_name', 'sender_display_name', 'sign_as'] as $key) {
            $value = trim((string) ($plan[$key] ?? ''));
            if ($value !== '' && ! self::looksLikePlaceholder($value)) {
                return $value;
            }
        }

        return self::extractFromText(self::planTextBlob($plan));
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

    /**
     * @param  array<string, mixed>|mixed  $plan
     */
    private static function planTextBlob(mixed $plan): string
    {
        if (! is_array($plan)) {
            return is_string($plan) ? $plan : '';
        }

        return trim(implode(' ', array_filter([
            (string) ($plan['goal'] ?? ''),
            (string) ($plan['message'] ?? ''),
            (string) ($plan['body'] ?? ''),
            (string) ($plan['draft_text'] ?? ''),
            (string) ($plan['ai_context'] ?? ''),
            (string) ($plan['sender_name'] ?? ''),
            (string) ($plan['sign_as'] ?? ''),
        ])));
    }

    private static function looksLikePlaceholder(string $name): bool
    {
        return $name === ''
            || preg_match('/^\[.*\]$/', $name)
            || preg_match('/^\{\{.*\}\}$/', $name)
            || strcasecmp($name, 'Your Name') === 0;
    }
}
