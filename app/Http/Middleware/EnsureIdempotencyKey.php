<?php

namespace App\Http\Middleware;

use App\Models\V2IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotencyKey
{
    public function handle(Request $request, Closure $next, string $scope = 'default'): Response
    {
        $rawKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($rawKey === '') {
            return response()->json(['message' => 'Missing Idempotency-Key header.'], 422);
        }

        if (strlen($rawKey) > 255) {
            return response()->json(['message' => 'Invalid Idempotency-Key header length.'], 422);
        }

        $user = $request->attributes->get('v2User');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        try {
            V2IdempotencyKey::query()->create([
                'user_id' => $user->id,
                'scope' => $scope,
                'key_hash' => hash('sha256', $rawKey),
            ]);
        } catch (QueryException $exception) {
            return response()->json([
                'message' => 'Duplicate idempotent request blocked.',
                'scope' => $scope,
            ], 409);
        }

        return $next($request);
    }
}
