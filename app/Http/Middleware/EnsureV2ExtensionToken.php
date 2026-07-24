<?php

namespace App\Http\Middleware;

use App\Models\V2ExtensionToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureV2ExtensionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = (string) $request->header('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'Missing extension token.'], 401);
        }

        $plainToken = trim(substr($authHeader, 7));
        if ($plainToken === '') {
            return response()->json(['message' => 'Invalid extension token.'], 401);
        }

        $tokenHash = hash('sha256', $plainToken);

        $record = V2ExtensionToken::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->with('user')
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Unauthorized token.'], 401);
        }

        if ($record->expires_at && now()->greaterThan($record->expires_at)) {
            return response()->json(['message' => 'Token expired.'], 401);
        }

        $record->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('v2User', $record->user);
        $request->attributes->set('v2Token', $record);

        return $next($request);
    }
}
