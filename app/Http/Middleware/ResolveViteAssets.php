<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the app is accessed through an HTTPS proxy (ngrok, Cloudflare Tunnel, etc.)
 * while Vite dev is running on local HTTP, browsers block those assets (mixed content).
 * Fall back to the production build for that request — no hardcoded tunnel URLs.
 */
class ResolveViteAssets
{
    private const LOCAL_DEV_HOSTS = ['localhost', '127.0.0.1', '[::1]', '0.0.0.0'];

    public function handle(Request $request, Closure $next): Response
    {
        $hotFile = public_path('hot');

        if (is_file($hotFile)) {
            $devServerUrl = trim((string) file_get_contents($hotFile));

            if ($this->shouldServeBuiltAssets($request, $devServerUrl)) {
                Vite::useHotFile(storage_path('framework/vite-hot-disabled'));
            }
        }

        return $next($request);
    }

    private function shouldServeBuiltAssets(Request $request, string $devServerUrl): bool
    {
        $parts = parse_url($devServerUrl);

        if (! is_array($parts)) {
            return false;
        }

        $devScheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $devHost = strtolower((string) ($parts['host'] ?? ''));
        $requestHost = strtolower($request->getHost());

        $devIsLocal = in_array($devHost, self::LOCAL_DEV_HOSTS, true);
        $requestIsLocal = in_array($requestHost, self::LOCAL_DEV_HOSTS, true);

        // HTTPS page cannot load HTTP Vite assets (mixed content).
        if ($request->isSecure() && $devScheme === 'http') {
            return true;
        }

        // Tunnel/proxy host (ngrok, etc.) cannot reach a local-only Vite dev server.
        if ($devIsLocal && ! $requestIsLocal) {
            return true;
        }

        return false;
    }
}
