<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2OrganizationUser;
use App\Models\V2TeamInvite;
use App\V2\Services\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function __construct(private readonly TeamService $teamService)
    {
    }

    public function capabilityTemplates(): JsonResponse
    {
        return response()->json(['data' => $this->teamService->capabilityTemplates()]);
    }

    public function roleMatrix(Request $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $rows = V2OrganizationUser::query()
            ->where('organization_id', $organizationId)
            ->select('role')
            ->selectRaw('COUNT(*) as members')
            ->groupBy('role')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function previewTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template' => ['required', 'string', 'max:50'],
        ]);

        [$role, $capabilities] = $this->teamService->resolveTemplate($data['template'], 'member', []);

        return response()->json([
            'data' => [
                'template' => $data['template'],
                'role' => $role,
                'capabilities' => $capabilities,
            ],
        ]);
    }

    public function bulkApplyTemplate(Request $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer'],
            'template' => ['required', 'string', 'max:50'],
        ]);

        [$resolvedRole, $resolvedCapabilities] = $this->teamService->resolveTemplate($data['template'], 'member', []);

        $updated = V2OrganizationUser::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $data['member_ids'])
            ->update([
                'role' => $resolvedRole,
                'capabilities' => $resolvedCapabilities,
            ]);

        return response()->json([
            'data' => [
                'updated' => $updated,
                'template' => $data['template'],
                'role' => $resolvedRole,
                'capabilities' => $resolvedCapabilities,
            ],
        ]);
    }

    public function members(Request $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $user = $request->attributes->get('v2User');

        $rows = V2OrganizationUser::query()
            ->with('user:id,name,email,current_organization_id')
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (V2OrganizationUser $m) => $this->teamService->serializeMember($m, $user->id));

        return response()->json(['data' => $rows]);
    }

    public function invites(Request $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $rows = V2TeamInvite::query()
            ->where('organization_id', $organizationId)
            ->latest('id')
            ->get()
            ->map(fn (V2TeamInvite $i) => $this->teamService->serializeInvite($i));

        return response()->json(['data' => $rows]);
    }

    public function invite(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['nullable', 'string', 'max:50'],
            'capabilities' => ['nullable', 'array'],
            'template' => ['nullable', 'string', 'max:50'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $invite = $this->teamService->invite(
            $user,
            $organizationId,
            $data['email'],
            (string) ($data['template'] ?? 'operator'),
            $data['role'] ?? null,
            $data['capabilities'] ?? [],
            (int) ($data['expires_in_days'] ?? 7)
        );

        return response()->json(['data' => $invite], 201);
    }

    public function acceptInvite(Request $request, string $token): JsonResponse
    {
        $user = $request->attributes->get('v2User');

        try {
            $invite = $this->teamService->acceptInvite($user, $token);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $invite]);
    }

    public function updateMember(Request $request, int $memberId): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'role' => ['nullable', 'string', 'max:50'],
            'capabilities' => ['nullable', 'array'],
            'template' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $member = $this->teamService->updateMember(
            $organizationId,
            $memberId,
            $data['template'] ?? null,
            $data['role'] ?? null,
            $data['capabilities'] ?? null,
            $data['status'] ?? null
        );

        return response()->json(['data' => $member]);
    }

    public function removeMember(Request $request, int $memberId): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $currentUser = $request->attributes->get('v2User');

        try {
            $this->teamService->removeMember($organizationId, $memberId, $currentUser);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Member removed.']);
    }

    public function revokeInvite(Request $request, int $inviteId): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        try {
            $this->teamService->revokeInvite($organizationId, $inviteId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'Invite not found.'], 404);
        }

        return response()->json(['message' => 'Invite revoked.']);
    }

    public function switchOrganization(Request $request, int $organizationId): JsonResponse
    {
        $user = $request->attributes->get('v2User');

        try {
            $membership = $this->teamService->switchOrganization($user, $organizationId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'Not a member of target organization.'], 403);
        }

        return response()->json([
            'data' => [
                'organization_id' => $organizationId,
                'role' => $membership->role,
            ],
        ]);
    }
}
