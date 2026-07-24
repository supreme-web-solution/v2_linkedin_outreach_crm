<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetRequestRootUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $root = $request->getSchemeAndHttpHost();

        URL::forceRootUrl($root);
        URL::forceScheme($request->getScheme());

        config([
            'session.secure' => $request->isSecure(),
        ]);

        if ($request->hasSession()) {
            foreach (['url.intended', '_previous'] as $key) {
                $stored = $request->session()->get($key);

                if (! is_string($stored) || $stored === '') {
                    continue;
                }

                $normalized = $this->normalizeUrl($stored, $request);

                if ($normalized !== $stored) {
                    $request->session()->put($key, $normalized);
                }
            }
        }

        return $next($request);
    }

    private function normalizeUrl(string $url, Request $request): string
    {
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return rtrim($request->getSchemeAndHttpHost(), '/').'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        if (strcasecmp((string) $parts['host'], $request->getHost()) !== 0) {
            return $url;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $request->getSchemeAndHttpHost().$path.$query;
    }
}
