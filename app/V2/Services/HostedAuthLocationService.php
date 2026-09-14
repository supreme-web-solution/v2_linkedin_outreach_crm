<?php

namespace App\V2\Services;

use App\Models\User;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * Pick a Unipile proxy country that matches the person connecting, not a
 * hardcoded US datacenter. Instagram/Meta treat a login from the wrong
 * country as automation.
 */
class HostedAuthLocationService
{
    public function inferCountry(?User $user = null, ?Request $request = null): string
    {
        $fromTimezone = $this->countryFromTimezone((string) ($user?->timezone ?? ''));
        if ($fromTimezone !== null) {
            return $fromTimezone;
        }

        $fromRequest = $this->countryFromRequest($request);
        if ($fromRequest !== null) {
            return $fromRequest;
        }

        $fromLanguage = $this->countryFromAcceptLanguage((string) ($request?->header('Accept-Language') ?? ''));
        if ($fromLanguage !== null) {
            return $fromLanguage;
        }

        $fallback = strtoupper(trim((string) config('services.unipile.default_country', '')));

        return $this->isIsoCountry($fallback) ? $fallback : 'US';
    }

    /**
     * @return array<string, mixed>
     */
    public function hostedAuthConfig(string $provider, string $country): array
    {
        $country = $this->isIsoCountry($country) ? strtoupper($country) : 'US';
        $providerKey = strtolower(trim($provider));
        if ($providerKey === '' || str_contains($providerKey, ':') || $providerKey === '*') {
            $providerKey = '';
        }

        $config = [
            'global' => [
                'allow_user_country_override' => true,
                'allow_user_proxy_override' => false,
            ],
        ];

        if ($providerKey !== '') {
            $config[$providerKey] = [
                'auto_proxy_config' => [
                    'country' => $country,
                ],
            ];
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function applyToContext(array $context, ?User $user, ?Request $request, string $provider): array
    {
        $country = $this->inferCountry($user, $request);
        $context['country'] = $country;
        $context['config'] = $this->hostedAuthConfig($provider, $country);

        return $context;
    }

    public function countryFromTimezone(string $timezone): ?string
    {
        $timezone = trim($timezone);
        if ($timezone === '' || strtoupper($timezone) === 'UTC') {
            return null;
        }

        try {
            $location = (new DateTimeZone($timezone))->getLocation();
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($location)) {
            return null;
        }

        $code = strtoupper(trim((string) ($location['country_code'] ?? '')));

        return $this->isIsoCountry($code) ? $code : null;
    }

    private function countryFromRequest(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        foreach (['CF-IPCountry', 'X-Country-Code', 'X-Appengine-Country'] as $header) {
            $code = strtoupper(trim((string) $request->header($header, '')));
            if ($this->isIsoCountry($code)) {
                return $code;
            }
        }

        return null;
    }

    public function countryFromAcceptLanguage(string $header): ?string
    {
        foreach (preg_split('/\s*,\s*/', trim($header)) ?: [] as $part) {
            $tag = strtolower(trim(explode(';', $part)[0] ?? ''));
            if (! preg_match('/^[a-z]{2,3}-([a-z]{2})\b/', $tag, $match)) {
                continue;
            }
            $code = strtoupper($match[1]);
            if ($this->isIsoCountry($code)) {
                return $code;
            }
        }

        return null;
    }

    private function isIsoCountry(string $code): bool
    {
        $code = strtoupper(trim($code));

        return strlen($code) === 2
            && ctype_alpha($code)
            && ! in_array($code, ['XX', 'T1', 'ZZ'], true);
    }
}
