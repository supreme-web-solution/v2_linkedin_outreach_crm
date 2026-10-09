<?php

namespace App\Http\Middleware;

use App\V2\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEntitlement
{
    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    public function handle(Request $request, Closure $next, string $entitlement = 'FE'): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($entitlement === 'FE') {
            if (! $this->entitlements->canAccessCrm($user)) {
                return redirect()->route('license.fe')->with('error', 'Purchase FE access or activate your license to use the CRM.');
            }
        } elseif (! $this->entitlements->hasAny($user, $this->expandEntitlement($entitlement))) {
            abort(403, 'This feature requires an upgraded license.');
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    private function expandEntitlement(string $entitlement): array
    {
        return array_values(array_unique([$entitlement, 'Bundle']));
    }
}
