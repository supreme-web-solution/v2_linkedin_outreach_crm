<?php

namespace App\V2\Services;

/**
 * Maps raw OpenAI / HTTP failures to safe user-facing copy.
 */
class OpenAiUserError
{
    public const UNAVAILABLE = 'AI features are temporarily unavailable. Please contact your administrator.';

    public const BUSY = 'AI is busy right now. Please try again in a moment.';

    public const NOT_CONFIGURED = 'AI is not available right now. Please contact your administrator.';

    /** Payment / quota exhausted — $ is a quiet admin signal, not a provider name. */
    public const SOCI_CONTACT_ADMIN_BILLING = 'Soci could not finish that — contact admin $';

    public const SOCI_TRY_AGAIN = 'Soci could not finish that — try again in a moment.';

    public const SOCI_GENERIC = 'I hit an error processing that. Please try again in a moment.';

    public static function fromHttp(?int $status, string $body = ''): string
    {
        $haystack = strtolower($body.' '.(string) $status);

        if (
            $status === 429
            || str_contains($haystack, 'rate_limit')
            || str_contains($haystack, 'rate limit')
            || str_contains($haystack, 'too many requests')
        ) {
            return self::BUSY;
        }

        if (
            $status === 402
            || self::looksLikeBilling($haystack)
            || str_contains($haystack, 'invalid_api_key')
            || str_contains($haystack, 'incorrect api key')
            || str_contains($haystack, 'openai request failed')
            || str_contains($haystack, 'ai image generation failed')
        ) {
            return self::UNAVAILABLE;
        }

        return self::UNAVAILABLE;
    }

    public static function fromThrowable(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (in_array($message, [
            self::UNAVAILABLE,
            self::BUSY,
            self::NOT_CONFIGURED,
            self::SOCI_CONTACT_ADMIN_BILLING,
            self::SOCI_TRY_AGAIN,
            self::SOCI_GENERIC,
        ], true)) {
            return $message;
        }

        if (
            str_contains(strtolower($message), 'openai is not configured')
            || str_contains(strtolower($message), 'openai_api_key')
            || str_contains(strtolower($message), 'api key is missing')
        ) {
            return self::NOT_CONFIGURED;
        }

        $status = null;
        if (method_exists($e, 'getCode')) {
            $code = (int) $e->getCode();
            if ($code >= 400 && $code < 600) {
                $status = $code;
            }
        }

        $blob = $message.' '.($e->getPrevious()?->getMessage() ?? '');

        return self::fromHttp($status, $blob);
    }

    /**
     * Chat / agent replies — never name providers, keys, or billing dashboards.
     */
    public static function forSociAgent(\Throwable $e): string
    {
        $blob = strtolower(trim($e->getMessage().' '.($e->getPrevious()?->getMessage() ?? '')));

        if (self::looksLikeBilling($blob)) {
            return self::SOCI_CONTACT_ADMIN_BILLING;
        }

        if (
            str_contains($blob, 'rate limit')
            || str_contains($blob, 'rate_limit')
            || str_contains($blob, '429')
            || str_contains($blob, 'too many requests')
            || str_contains($blob, 'ratelimited')
        ) {
            return self::SOCI_TRY_AGAIN;
        }

        return self::SOCI_GENERIC;
    }

    public static function looksLikeBilling(string $blob): bool
    {
        $haystack = strtolower($blob);

        return str_contains($haystack, 'no credits')
            || str_contains($haystack, 'insufficient_quota')
            || str_contains($haystack, 'credit_balance')
            || str_contains($haystack, 'billing_hard_limit')
            || str_contains($haystack, 'insufficient_funds')
            || str_contains($haystack, 'exceeded your current quota')
            || str_contains($haystack, 'billing')
            || str_contains($haystack, 'payment required')
            || str_contains($haystack, '402');
    }
}
