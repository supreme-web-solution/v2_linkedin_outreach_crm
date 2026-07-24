<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\V2OrganizationUser;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * @return array<int, string>
     */
    private function timezoneOptions(): array
    {
        return [
            'UTC',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'America/Toronto',
            'Europe/London',
            'Europe/Paris',
            'Europe/Berlin',
            'Asia/Dubai',
            'Asia/Kolkata',
            'Asia/Singapore',
            'Asia/Tokyo',
            'Australia/Sydney',
        ];
    }

    public function edit(Request $request): Response
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $orgId = (int) ($user->current_organization_id ?? 0);

        $membership = $orgId
            ? V2OrganizationUser::where('organization_id', $orgId)
                ->where('user_id', $user->id)
                ->with('organization:id,name')
                ->first()
            : null;

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'timezones' => $this->timezoneOptions(),
            'profile' => [
                'timezone' => $user->timezone,
                'linkedin_public_id' => $user->linkedin_public_id,
            ],
            'workspace' => $membership ? [
                'id' => $membership->organization_id,
                'name' => $membership->organization?->name,
                'role' => $membership->role,
            ] : null,
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    public function destroy(\App\Http\Requests\Settings\ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
