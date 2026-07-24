<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\V2\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessCheckController extends Controller
{
    public function __invoke(Request $request, EntitlementService $entitlements): JsonResponse
    {
        $user = $request->attributes->get('v2User');

        if (! $entitlements->canAccessCrm($user)) {
            return response()->json([
                'access' => false,
                'message' => 'FE license required. Purchase or activate your account.',
                'error_code' => 'no_fe_license',
            ], 403);
        }

        return response()->json([
            'access' => true,
            'entitlements' => $entitlements->list($user),
        ]);
    }
}
