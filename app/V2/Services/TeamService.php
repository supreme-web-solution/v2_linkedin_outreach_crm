<?php

namespace App\V2\Services;

use App\Models\User;
use App\Models\V2OrganizationUser;
use App\Models\V2TeamInvite;
use Illuminate\Support\Str;

class TeamService
{
    /**
     * @return array<int, array{key: string, label: string, role: string, capabilities: array<int, string>}>
     */
    public function capabilityTemplates(): array
    {
        return [
            [
                'key' => 'owner',
                'label' => 'Owner',
                'role' => 'owner',
                'capabilities' => ['*'],
            ],
            [
                'key' => 'admin',
                'label' => 'Admin',
                'role' => 'admin',
                'capabilities' => [
                    'integration.read', 'integration.write',
                    'leads.read', 'leads.search',
                    'outreach.write',
                    'campaigns.read', 'campaigns.write',
                    'autoresponses.read', 'autoresponses.write',
                    'stats.read', 'stats.write',
                    'calls.read', 'calls.write',
                    'content.read', 'content.write',
                    'esp.read', 'esp.write',
                    'team.read', 'team.write',
                ],
            ],
            [
                'key' => 'operator',
                'label' => 'Operator',
                'role' => 'member',
                'capabilities' => [
                    'leads.read', 'leads.search',
                    'outreach.write',
                    'campaigns.read', 'campaigns.write',
                    'autoresponses.read', 'autoresponses.write',
                    'stats.read',
                    'calls.read', 'calls.write',
                    'content.read', 'content.write',
                ],
            ],
            [
                'key' => 'viewer',
                'label' => 'Viewer',
                'role' => 'member',
                'capabilities' => [
                    'leads.read',
                    'campaigns.read',
                    'autoresponses.read',
                    'stats.read',
                    'calls.read',
                    'content.read',
                    'esp.read',
                    'team.read',
                ],
            ],
        ];
    }

    public function membership(User $user, int $organizationId): ?V2OrganizationUser
    {
        if ($organizationId <= 0) {
            return null;
        }

        return V2OrganizationUser::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }

    public function canManageTeam(?V2OrganizationUser $membership): bool
    {
        if (!$membership) {
            return false;
        }

        $capabilities = is_array($membership->capabilities) ? $membership->capabilities : [];
        if (in_array('*', $capabilities, true) || in_array('team.write', $capabilities, true)) {
            return true;
        }

        return in_array($membership->role, ['owner', 'admin'], true);
    }

    public function canReadTeam(?V2OrganizationUser $membership): bool
    {
        if (!$membership) {
            return false;
        }

        $capabilities = is_array($membership->capabilities) ? $membership->capabilities : [];
        if (in_array('*', $capabilities, true) || in_array('team.read', $capabilities, true) || in_array('team.write', $capabilities, true)) {
            return true;
        }

        return in_array($membership->role, ['owner', 'admin', 'member'], true);
    }

    /**
     * @param mixed $capabilities
     * @return array{0: string, 1: array<int, string>}
     */
    public function resolveTemplate(string $templateKey, string $role, mixed $capabilities): array
    {
        $templates = collect($this->capabilityTemplates())->keyBy('key');

        if ($templateKey !== '' && $templates->has($templateKey)) {
            $template = $templates->get($templateKey);

            return [(string) $template['role'], $template['capabilities']];
        }

        $normalizedCapabilities = [];
        if (is_array($capabilities)) {
            foreach ($capabilities as $value) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $normalizedCapabilities[] = $text;
                }
            }
        }

        return [trim($role) ?: 'member', $normalizedCapabilities];
    }

    public function inferTemplateKey(string $role, mixed $capabilities): string
    {
        $caps = is_array($capabilities) ? $capabilities : [];
        sort($caps);

        foreach ($this->capabilityTemplates() as $template) {
            $templateCaps = $template['capabilities'];
            sort($templateCaps);
            if ($template['role'] === $role && $templateCaps === $caps) {
                return $template['key'];
            }
        }

        return 'custom';
    }

    public function invite(
        User $inviter,
        int $organizationId,
        string $email,
        string $templateKey = 'operator',
        ?string $role = null,
        mixed $capabilities = null,
        int $expiresInDays = 7
    ): V2TeamInvite {
        $email = strtolower(trim($email));
        [$resolvedRole, $resolvedCapabilities] = $this->resolveTemplate(
            $templateKey,
            $role ?? 'member',
            $capabilities ?? []
        );

        $invitee = User::query()->where('email', $email)->first();

        $invite = V2TeamInvite::query()->create([
            'organization_id' => $organizationId,
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee?->id,
            'invitee_email' => $email,
            'role' => $resolvedRole,
            'capabilities' => $resolvedCapabilities,
            'status' => $invitee ? 'accepted' : 'pending',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(max(1, min(30, $expiresInDays))),
            'accepted_at' => $invitee ? now() : null,
            'meta' => [
                'auto_accepted' => (bool) $invitee,
                'template' => $templateKey,
            ],
        ]);

        if ($invitee) {
            V2OrganizationUser::query()->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'user_id' => $invitee->id,
                ],
                [
                    'role' => $resolvedRole,
                    'capabilities' => $resolvedCapabilities,
                    'status' => 'active',
                ]
            );
        }

        return $invite;
    }

    public function acceptInvite(User $user, string $token): V2TeamInvite
    {
        $invite = V2TeamInvite::query()
            ->where('token', $token)
            ->where('status', 'pending')
            ->firstOrFail();

        if ($invite->expires_at && $invite->expires_at->isPast()) {
            throw new \RuntimeException('Invite has expired.');
        }

        if (strtolower($invite->invitee_email) !== strtolower((string) $user->email)) {
            throw new \RuntimeException('Invite email does not match your account.');
        }

        V2OrganizationUser::query()->updateOrCreate(
            [
                'organization_id' => $invite->organization_id,
                'user_id' => $user->id,
            ],
            [
                'role' => $invite->role ?: 'member',
                'capabilities' => is_array($invite->capabilities) ? $invite->capabilities : [],
                'status' => 'active',
            ]
        );

        $invite->forceFill([
            'invitee_user_id' => $user->id,
            'status' => 'accepted',
            'accepted_at' => now(),
        ])->save();

        $user->forceFill(['current_organization_id' => $invite->organization_id])->save();

        return $invite;
    }

    public function updateMember(
        int $organizationId,
        int $memberId,
        ?string $templateKey = null,
        ?string $role = null,
        mixed $capabilities = null,
        ?string $status = null
    ): V2OrganizationUser {
        $member = V2OrganizationUser::query()
            ->where('organization_id', $organizationId)
            ->where('id', $memberId)
            ->firstOrFail();

        $payload = [];

        if ($templateKey !== null || $role !== null || $capabilities !== null) {
            [$resolvedRole, $resolvedCapabilities] = $this->resolveTemplate(
                $templateKey ?? '',
                $role ?? $member->role,
                $capabilities ?? $member->capabilities ?? []
            );
            $payload['role'] = $resolvedRole;
            $payload['capabilities'] = $resolvedCapabilities;
        }

        if ($status !== null) {
            $payload['status'] = $status;
        }

        if (!empty($payload)) {
            $member->forceFill($payload)->save();
        }

        return $member->fresh(['user:id,name,email']);
    }

    public function removeMember(int $organizationId, int $memberId, User $currentUser): void
    {
        $member = V2OrganizationUser::query()
            ->where('organization_id', $organizationId)
            ->where('id', $memberId)
            ->firstOrFail();

        if ((int) $member->user_id === (int) $currentUser->id) {
            throw new \RuntimeException('You cannot remove yourself from the organization.');
        }

        if ($member->role === 'owner') {
            $ownerCount = V2OrganizationUser::query()
                ->where('organization_id', $organizationId)
                ->where('role', 'owner')
                ->where('status', 'active')
                ->count();

            if ($ownerCount <= 1) {
                throw new \RuntimeException('Cannot remove the only owner.');
            }
        }

        $member->delete();
    }

    public function revokeInvite(int $organizationId, int $inviteId): void
    {
        $invite = V2TeamInvite::query()
            ->where('organization_id', $organizationId)
            ->where('id', $inviteId)
            ->where('status', 'pending')
            ->firstOrFail();

        $invite->forceFill(['status' => 'revoked'])->save();
    }

    public function switchOrganization(User $user, int $organizationId): V2OrganizationUser
    {
        $membership = V2OrganizationUser::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->firstOrFail();

        $user->forceFill(['current_organization_id' => $organizationId])->save();

        return $membership;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMember(V2OrganizationUser $member, ?int $currentUserId = null): array
    {
        $capabilities = is_array($member->capabilities) ? $member->capabilities : [];

        return [
            'id' => $member->id,
            'role' => $member->role,
            'status' => $member->status,
            'capabilities' => $capabilities,
            'template' => $this->inferTemplateKey((string) $member->role, $capabilities),
            'is_self' => $currentUserId !== null && (int) $member->user_id === $currentUserId,
            'user' => $member->user ? [
                'id' => $member->user->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeInvite(V2TeamInvite $invite): array
    {
        return [
            'id' => $invite->id,
            'invitee_email' => $invite->invitee_email,
            'role' => $invite->role,
            'status' => $invite->status,
            'template' => is_array($invite->meta) ? ($invite->meta['template'] ?? $this->inferTemplateKey($invite->role, $invite->capabilities)) : 'operator',
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'created_at' => $invite->created_at?->toIso8601String(),
            'accept_url' => url('/team/accept/'.$invite->token),
        ];
    }
}
