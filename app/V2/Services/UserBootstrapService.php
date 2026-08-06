<?php

namespace App\V2\Services;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use Illuminate\Support\Str;

class UserBootstrapService
{
    public function ensurePersonalOrganization(User $user): V2Organization
    {
        $organization = null;

        if ($user->current_organization_id) {
            $organization = V2Organization::query()->find($user->current_organization_id);
        }

        if (! $organization) {
            $slugBase = Str::slug($user->name ?: explode('@', $user->email)[0]).'-'.$user->id;

            $organization = V2Organization::query()->firstOrCreate(
                ['slug' => $slugBase],
                [
                    'name' => ($user->name ?: 'User').' Workspace',
                    'status' => 'active',
                ]
            );
        }

        $capabilities = app(EntitlementService::class)->orgCapabilitiesFor($user);

        V2OrganizationUser::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ],
            [
                'role' => 'owner',
                'capabilities' => $capabilities,
                'status' => 'active',
            ]
        );

        if ((int) $user->current_organization_id !== (int) $organization->id) {
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        }

        return $organization;
    }
}
