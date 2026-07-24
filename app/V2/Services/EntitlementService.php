<?php

namespace App\V2\Services;

use App\Models\User;

class EntitlementService
{
    /** @var list<string> */
    public const ALL = ['FE', 'OTO1', 'OTO2', 'OTO3', 'OTO4', 'OTO5', 'OTO6', 'OTO7', 'OTO8', 'Bundle'];

    public function isPlatformAdmin(User $user): bool
    {
        if ($user->is_platform_admin) {
            return true;
        }

        $emails = config('billing.platform_admin_emails', []);

        return in_array(strtolower($user->email), array_map('strtolower', $emails), true);
    }

    public function isReseller(User $user): bool
    {
        return $this->hasAny($user, ['OTO5', 'OTO8', 'Bundle']) || $this->isPlatformAdmin($user);
    }

    public function has(User $user, string $entitlement): bool
    {
        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        return in_array($entitlement, $this->list($user), true);
    }

    /**
     * @param list<string> $entitlements
     */
    public function hasAny(User $user, array $entitlements): bool
    {
        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        foreach ($entitlements as $entitlement) {
            if ($this->has($user, $entitlement)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function list(User $user): array
    {
        $stored = is_array($user->entitlements) ? $user->entitlements : [];

        return array_values(array_unique(array_filter($stored, fn ($e) => is_string($e) && $e !== '')));
    }

    /**
     * @param list<string> $entitlements
     */
    public function grant(User $user, array $entitlements): void
    {
        $merged = array_values(array_unique(array_merge($this->list($user), $entitlements)));
        $user->forceFill(['entitlements' => $merged])->save();
    }

    /**
     * @param list<string> $entitlements
     */
    public function revoke(User $user, array $entitlements): void
    {
        $remaining = array_values(array_diff($this->list($user), $entitlements));
        $user->forceFill(['entitlements' => $remaining])->save();
    }

    public function canAccessCrm(User $user): bool
    {
        if (! config('billing.require_entitlement', true)) {
            return true;
        }

        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        return $this->has($user, 'FE') || $this->has($user, 'Bundle');
    }

    /**
     * @return list<string>
     */
    public function orgCapabilitiesFor(User $user): array
    {
        if (! config('billing.require_entitlement', true)) {
            return ['*'];
        }

        if ($this->isPlatformAdmin($user) || $this->has($user, 'Bundle') || $this->has($user, 'OTO8')) {
            return ['*'];
        }

        if ($this->has($user, 'FE')) {
            return [
                'leads.read', 'leads.write',
                'campaigns.read', 'campaigns.write',
                'calls.read', 'calls.write',
                'content.read', 'content.write',
                'conversations.read', 'conversations.write',
                'team.read', 'team.write',
                'integrations.read', 'integrations.write',
            ];
        }

        return [];
    }
}
