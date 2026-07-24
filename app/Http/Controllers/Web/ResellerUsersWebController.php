<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\V2\Services\EntitlementService;
use App\V2\Services\UserBootstrapService;
use App\V2\Services\UserDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ResellerUsersWebController extends Controller
{
    public function __construct(
        private readonly UserBootstrapService $bootstrap,
        private readonly UserDeletionService $deletion,
    ) {
    }

    public function index(Request $request): Response
    {
        /** @var User $reseller */
        $reseller = $request->user();
        $search = (string) $request->query('email', '');

        $query = User::query()
            ->where('created_by', $reseller->id)
            ->latest();

        if ($search !== '') {
            $query->where('email', 'like', '%'.$search.'%');
        }

        $users = $query->paginate(15)->through(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'linkedin_public_id' => $u->linkedin_public_id,
            'entitlements' => app(EntitlementService::class)->list($u),
            'created_at' => $u->created_at?->toIso8601String(),
        ]);

        return Inertia::render('reseller/Users', [
            'users' => $users,
            'filters' => ['email' => $search],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $reseller */
        $reseller = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            'created_by' => $reseller->id,
            'entitlements' => ['FE'],
        ]);

        $this->bootstrap->ensurePersonalOrganization($user);

        return back()->with('success', 'Sub-user created with FE access.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        /** @var User $reseller */
        $reseller = $request->user();

        $user = User::query()
            ->where('created_by', $reseller->id)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$id],
            'linkedin_public_id' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'linkedin_public_id' => $data['linkedin_public_id'] ?? null,
        ]);

        if (! empty($data['password'])) {
            $user->password = bcrypt($data['password']);
        }

        $user->save();

        return back()->with('success', 'User updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        /** @var User $reseller */
        $reseller = $request->user();

        $user = User::query()
            ->where('created_by', $reseller->id)
            ->where('id', $id)
            ->firstOrFail();

        $this->deletion->delete($user);

        return back()->with('success', 'User deleted.');
    }
}
