<?php

namespace App\Http\Middleware;

use App\Models\V2OrganizationUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureV2TenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('v2User');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $orgIdHeader = $request->header('X-Organization-Id');
        $organizationId = $orgIdHeader ? (int) $orgIdHeader : (int) ($user->current_organization_id ?? 0);

        if ($organizationId <= 0) {
            return response()->json(['message' => 'Organization context is required.'], 422);
        }

        $membership = V2OrganizationUser::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (!$membership) {
            return response()->json(['message' => 'User is not a member of this organization.'], 403);
        }

        $request->attributes->set('v2OrganizationId', $organizationId);
        $request->attributes->set('v2Membership', $membership);

        return $next($request);
    }
}
