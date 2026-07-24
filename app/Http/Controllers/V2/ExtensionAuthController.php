<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\V2ExtensionToken;
use App\V2\Services\EntitlementService;
use App\V2\Services\UserBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ExtensionAuthController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly UserBootstrapService $bootstrap,
    ) {
    }

    public function issueToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        if (! $this->entitlements->canAccessCrm($user)) {
            return response()->json([
                'message' => 'FE license required. Activate your account at /auth/fe after purchase.',
                'error_code' => 'no_fe_license',
            ], 403);
        }

        $organization = $this->bootstrap->ensurePersonalOrganization($user);

        $plainToken = 'v2ext_'.Str::random(64);
        $token = V2ExtensionToken::query()->create([
            'user_id' => $user->id,
            'name' => $data['device_name'] ?? 'extension',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(30),
            'meta' => [
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ],
        ]);

        return response()->json([
            'token' => $plainToken,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'entitlements' => $this->entitlements->list($user),
        ]);
    }
}
