<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\V2\Services\EntitlementService;
use App\V2\Services\UserBootstrapService;
use App\V2\Services\UserDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AdminUsersWebController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly UserBootstrapService $bootstrap,
        private readonly UserDeletionService $deletion,
    ) {
    }

    public function index(Request $request): Response
    {
        $search = (string) $request->query('email', '');

        $query = User::query()->latest();
        if ($search !== '') {
            $query->where('email', 'like', '%'.$search.'%');
        }

        $users = $query->paginate(15)->through(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'linkedin_public_id' => $u->linkedin_public_id,
            'entitlements' => $this->entitlements->list($u),
            'created_at' => $u->created_at?->toIso8601String(),
        ]);

        return Inertia::render('admin/Users', [
            'users' => $users,
            'entitlementOptions' => config('billing.entitlements', EntitlementService::ALL),
            'filters' => ['email' => $search],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            'created_by' => $request->user()->id,
            'entitlements' => ['FE'],
        ]);

        $this->bootstrap->ensurePersonalOrganization($user);

        return back()->with('success', 'User created.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $user = User::query()->findOrFail($id);

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

    public function destroy(int $id): RedirectResponse
    {
        if ($id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $this->deletion->delete(User::query()->findOrFail($id));

        return back()->with('success', 'User deleted.');
    }

    public function assignEntitlements(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'entitlements' => ['nullable', 'array'],
            'entitlements.*' => ['string', 'max:50'],
        ]);

        $user = User::query()->findOrFail($data['user_id']);
        $allowed = config('billing.entitlements', EntitlementService::ALL);
        $selected = array_values(array_intersect($data['entitlements'] ?? [], $allowed));

        $user->forceFill(['entitlements' => $selected])->save();
        $this->bootstrap->ensurePersonalOrganization($user);

        return back()->with('success', 'Entitlements updated.');
    }

    public function impersonate(int $id): RedirectResponse
    {
        $admin = auth()->user();
        $target = User::query()->findOrFail($id);

        if ($target->id === $admin->id) {
            return back()->with('error', 'Cannot impersonate yourself.');
        }

        session(['impersonator_id' => $admin->id]);
        Auth::logout();
        Auth::login($target);
        session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Impersonating '.$target->email);
    }

    public function stopImpersonating(): RedirectResponse
    {
        $impersonatorId = session('impersonator_id');
        if (! $impersonatorId) {
            return redirect()->route('dashboard');
        }

        $admin = User::query()->find($impersonatorId);
        session()->forget('impersonator_id');

        if ($admin) {
            Auth::logout();
            Auth::login($admin);
            session()->regenerate();
        }

        return redirect()->route('admin.users')->with('success', 'Returned to admin account.');
    }

    public function permissions(int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $options = config('billing.entitlements', EntitlementService::ALL);
        $current = $this->entitlements->list($user);

        return response()->json([
            'options' => $options,
            'assigned' => $current,
        ]);
    }
}
