<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Mail\TeamInviteMail;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2TeamInvite;
use App\V2\Services\TeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class TeamWebController extends Controller
{
    public function __construct(private readonly TeamService $teamService)
    {
    }

    public function index(): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;
        $membership = $this->teamService->membership($user, $orgId);

        $organization = $orgId ? V2Organization::find($orgId) : null;

        $members = $orgId && $this->teamService->canReadTeam($membership)
            ? V2OrganizationUser::where('organization_id', $orgId)
                ->with('user:id,name,email')
                ->orderByDesc('id')
                ->get()
                ->map(fn (V2OrganizationUser $m) => $this->teamService->serializeMember($m, $user->id))
            : collect();

        $invites = $orgId && $this->teamService->canReadTeam($membership)
            ? V2TeamInvite::where('organization_id', $orgId)
                ->whereIn('status', ['pending', 'accepted'])
                ->latest()
                ->limit(30)
                ->get()
                ->map(fn (V2TeamInvite $i) => $this->teamService->serializeInvite($i))
            : collect();

        $myOrgs = V2OrganizationUser::where('user_id', $user->id)
            ->where('status', 'active')
            ->with('organization:id,name')
            ->get()
            ->map(fn (V2OrganizationUser $m) => [
                'id' => $m->organization_id,
                'name' => $m->organization?->name,
                'role' => $m->role,
                'is_current' => (int) $m->organization_id === $orgId,
            ]);

        $stats = $orgId ? [
            'total_members' => $members->count(),
            'pending_invites' => $invites->where('status', 'pending')->count(),
            'admins' => $members->whereIn('role', ['owner', 'admin'])->count(),
        ] : ['total_members' => 0, 'pending_invites' => 0, 'admins' => 0];

        return Inertia::render('crm/Team', [
            'organization' => $organization ? ['id' => $organization->id, 'name' => $organization->name] : null,
            'members' => $members,
            'invites' => $invites,
            'myOrgs' => $myOrgs,
            'templates' => $this->teamService->capabilityTemplates(),
            'canManage' => $this->teamService->canManageTeam($membership),
            'currentMembership' => $membership ? [
                'role' => $membership->role,
                'template' => $this->teamService->inferTemplateKey(
                    (string) $membership->role,
                    is_array($membership->capabilities) ? $membership->capabilities : []
                ),
            ] : null,
            'stats' => $stats,
            'hasOrg' => (bool) $orgId,
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;
        $membership = $this->teamService->membership($user, $orgId);

        if (!$this->teamService->canManageTeam($membership)) {
            return back()->with('error', 'You do not have permission to invite team members.');
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
            'template' => ['required', 'string', 'max:50'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $invite = $this->teamService->invite(
            $user,
            $orgId,
            $data['email'],
            $data['template'],
            null,
            null,
            (int) ($data['expires_in_days'] ?? 7)
        );

        if ($invite->status === 'pending') {
            $organization = V2Organization::find($orgId);
            if ($organization) {
                try {
                    Mail::to($invite->invitee_email)->send(new TeamInviteMail($invite, $user, $organization));
                } catch (\Throwable $e) {
                    Log::warning('Team invite email failed', ['error' => $e->getMessage(), 'invite_id' => $invite->id]);
                }
            }
        }

        return back()->with('success', $invite->status === 'accepted'
            ? 'Member added — they already had an account.'
            : 'Invitation sent.');
    }

    public function updateMember(Request $request, int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;
        $membership = $this->teamService->membership($user, $orgId);

        if (!$this->teamService->canManageTeam($membership)) {
            return back()->with('error', 'Permission denied.');
        }

        $data = $request->validate([
            'template' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $target = V2OrganizationUser::where('organization_id', $orgId)->where('id', $id)->firstOrFail();
        if ($target->role === 'owner' && $membership->role !== 'owner') {
            return back()->with('error', 'Only owners can modify other owners.');
        }

        $this->teamService->updateMember(
            $orgId,
            $id,
            $data['template'] ?? null,
            null,
            null,
            $data['status'] ?? null
        );

        return back()->with('success', 'Member updated.');
    }

    public function removeMember(int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;
        $membership = $this->teamService->membership($user, $orgId);

        if (!$this->teamService->canManageTeam($membership)) {
            return back()->with('error', 'Permission denied.');
        }

        try {
            $this->teamService->removeMember($orgId, $id, $user);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Member removed.');
    }

    public function revokeInvite(int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;
        $membership = $this->teamService->membership($user, $orgId);

        if (!$this->teamService->canManageTeam($membership)) {
            return back()->with('error', 'Permission denied.');
        }

        try {
            $this->teamService->revokeInvite($orgId, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return back()->with('error', 'Invite not found.');
        }

        return back()->with('success', 'Invitation revoked.');
    }

    public function resendInvite(int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;
        $membership = $this->teamService->membership($user, $orgId);

        if (!$this->teamService->canManageTeam($membership)) {
            return back()->with('error', 'Permission denied.');
        }

        $invite = V2TeamInvite::where('organization_id', $orgId)
            ->where('id', $id)
            ->where('status', 'pending')
            ->firstOrFail();

        $organization = V2Organization::find($orgId);
        if ($organization) {
            try {
                Mail::to($invite->invitee_email)->send(new TeamInviteMail($invite, $user, $organization));
            } catch (\Throwable $e) {
                Log::warning('Team invite resend failed', ['error' => $e->getMessage()]);

                return back()->with('error', 'Could not send email — check mail configuration.');
            }
        }

        return back()->with('success', 'Invitation resent.');
    }

    public function switchOrganization(int $orgId): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $this->teamService->switchOrganization($user, $orgId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return back()->with('error', 'You are not a member of that organization.');
        }

        return redirect('/team')->with('success', 'Workspace switched.');
    }

    public function showAcceptInvite(string $token): Response|RedirectResponse
    {
        $invite = V2TeamInvite::query()
            ->where('token', $token)
            ->where('status', 'pending')
            ->with('organization:id,name')
            ->first();

        if (!$invite) {
            return redirect('/team')->with('error', 'Invite not found or already used.');
        }

        if ($invite->expires_at && $invite->expires_at->isPast()) {
            return redirect('/team')->with('error', 'This invitation has expired.');
        }

        /** @var User|null $user */
        $user = auth()->user();

        return Inertia::render('crm/TeamAcceptInvite', [
            'invite' => [
                'email' => $invite->invitee_email,
                'role' => $invite->role,
                'organization_name' => $invite->organization?->name,
                'expires_at' => $invite->expires_at?->toIso8601String(),
                'token' => $token,
            ],
            'isLoggedIn' => (bool) $user,
            'emailMatches' => $user && strtolower($user->email) === strtolower($invite->invitee_email),
        ]);
    }

    public function acceptInvite(string $token): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $this->teamService->acceptInvite($user, $token);
        } catch (\RuntimeException $e) {
            return redirect('/team/accept/'.$token)->with('error', $e->getMessage());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return redirect('/team')->with('error', 'Invite not found.');
        }

        return redirect('/dashboard')->with('success', 'Welcome to the team!');
    }
}
