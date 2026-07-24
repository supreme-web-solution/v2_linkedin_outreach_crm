<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureV2Capability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        if (! config('billing.require_entitlement', true)) {
            return $next($request);
        }

        $membership = $request->attributes->get('v2Membership');
        if (!$membership) {
            return response()->json(['message' => 'Missing tenant membership context.'], 403);
        }

        $capabilities = $membership->capabilities ?? [];
        if (!is_array($capabilities)) {
            $capabilities = [];
        }

        $hasAccess = in_array('*', $capabilities, true) || in_array($capability, $capabilities, true);
        if (!$hasAccess) {
            return response()->json([
                'message' => 'Missing required capability.',
                'required_capability' => $capability,
            ], 403);
        }

        return $next($request);
    }
}
