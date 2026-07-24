<?php

namespace App\Http\Middleware;

use App\V2\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->entitlements->isPlatformAdmin($user)) {
            abort(403, 'Platform admin access required.');
        }

        return $next($request);
    }
}
