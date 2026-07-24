<?php

namespace App\Http\Middleware;

use App\V2\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureReseller
{
    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->entitlements->isReseller($user)) {
            abort(403, 'Reseller license required.');
        }

        return $next($request);
    }
}
