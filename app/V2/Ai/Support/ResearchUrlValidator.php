<?php

namespace App\V2\Ai\Support;

/**
 * Reject placeholder / scheme-only URLs (e.g. "https://") from research and profile context.
 */
final class ResearchUrlValidator
{
    public static function isUsable(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        if (preg_match('~^https?://\s*$~i', $url)) {
            return false;
        }

        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://'.$url;
        }

        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? '')));
        if ($host === '' || in_array($host, ['http', 'https'], true)) {
            return false;
        }

        if (! str_contains($host, '.') && ! in_array($host, ['localhost'], true)) {
            return false;
        }

        return strlen($host) >= 4;
    }

    public static function sanitize(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://'.$url;
        }

        return self::isUsable($url) ? $url : null;
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    public static function filterList(array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }
            $clean = self::sanitize($url);
            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return array_values(array_unique($out));
    }
}
