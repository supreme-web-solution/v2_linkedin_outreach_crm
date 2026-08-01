<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\V2\Services\EntitlementService;
use App\V2\Services\UserBootstrapService;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // Always create a personal workspace so the web CRM and extension share an org.
        app(UserBootstrapService::class)->ensurePersonalOrganization($user);

        if (! config('billing.require_entitlement', true)) {
            app(EntitlementService::class)->grant($user, config('billing.bundles.fe', ['FE']));
        }

        return $user;
    }
}
