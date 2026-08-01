<?php

namespace App\V2\Services;

use Illuminate\Support\Arr;

class LinkedInProfileSocialExtractor
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array{instagram_handle: ?string, twitter_handle: ?string, telegram_handle: ?string}
     */
    public function extract(array $profile): array
    {
        $instagram = null;
        $twitter = null;
        $telegram = null;

        foreach ($this->websiteCandidates($profile) as $url) {
            $url = strtolower($url);
            if ($instagram === null && str_contains($url, 'instagram.com/')) {
                $instagram = $this->handleFromUrl($url, 'instagram.com/');
            }
            if ($twitter === null && (str_contains($url, 'twitter.com/') || str_contains($url, 'x.com/'))) {
                $twitter = $this->handleFromUrl($url, 'twitter.com/') ?: $this->handleFromUrl($url, 'x.com/');
            }
            if ($telegram === null && (str_contains($url, 't.me/') || str_contains($url, 'telegram.me/'))) {
                $telegram = $this->handleFromUrl($url, 't.me/') ?: $this->handleFromUrl($url, 'telegram.me/');
            }
        }

        return [
            'instagram_handle' => $instagram,
            'twitter_handle' => $twitter,
            'telegram_handle' => $telegram,
        ];
    }

    /**
     * @param  array<string, mixed>  $fullEnrichProfile
     * @return array{instagram_handle: ?string, twitter_handle: ?string, telegram_handle: ?string}
     */
    public function extractFromFullEnrichProfile(array $fullEnrichProfile): array
    {
        $socials = Arr::get($fullEnrichProfile, 'social_profiles', []);
        if (! is_array($socials)) {
            return ['instagram_handle' => null, 'twitter_handle' => null, 'telegram_handle' => null];
        }

        $instagram = $this->handleFromSocialNode($socials, ['instagram']);
        $twitter = $this->handleFromSocialNode($socials, ['twitter', 'x']);
        $telegram = $this->handleFromSocialNode($socials, ['telegram']);

        return [
            'instagram_handle' => $instagram,
            'twitter_handle' => $twitter,
            'telegram_handle' => $telegram,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<int, string>
     */
    private function websiteCandidates(array $profile): array
    {
        $urls = [];

        foreach (Arr::get($profile, 'websites', []) as $site) {
            if (is_string($site)) {
                $urls[] = $site;
            } elseif (is_array($site)) {
                $urls[] = (string) (Arr::get($site, 'url') ?? Arr::get($site, 'value') ?? '');
            }
        }

        foreach (Arr::get($profile, 'contact_info.websites', []) as $site) {
            if (is_string($site)) {
                $urls[] = $site;
            } elseif (is_array($site)) {
                $urls[] = (string) (Arr::get($site, 'url') ?? Arr::get($site, 'value') ?? '');
            }
        }

        return array_values(array_filter(array_map('trim', $urls)));
    }

    /**
     * @param  array<string, mixed>  $socials
     * @param  array<int, string>  $keys
     */
    private function handleFromSocialNode(array $socials, array $keys): ?string
    {
        foreach ($keys as $key) {
            $node = Arr::get($socials, $key);
            if (! is_array($node)) {
                continue;
            }

            $handle = trim((string) (Arr::get($node, 'handle') ?? ''));
            if ($handle !== '') {
                return ltrim($handle, '@');
            }

            $url = trim((string) (Arr::get($node, 'url') ?? ''));
            if ($url !== '') {
                $parsed = $this->handleFromUrl(strtolower($url), $key.'.com/');

                if ($parsed) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    private function handleFromUrl(string $url, string $needle): ?string
    {
        $pos = strpos($url, $needle);
        if ($pos === false) {
            return null;
        }

        $rest = substr($url, $pos + strlen($needle));
        $handle = trim(explode('/', explode('?', $rest)[0])[0], '/@');

        return $handle !== '' && $handle !== 'in' ? ltrim($handle, '@') : null;
    }
}
