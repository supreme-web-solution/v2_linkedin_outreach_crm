<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\V2\Services\EntitlementService;
use App\V2\Services\UserBootstrapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LicenseSignupWebController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly UserBootstrapService $bootstrap,
    ) {
    }

    public function showFe(): Response
    {
        return Inertia::render('auth/FeSignup', ['variant' => 'fe']);
    }

    public function showBundle(): Response
    {
        return Inertia::render('auth/BundleSignup', ['variant' => 'bundle']);
    }

    public function showReseller(): Response
    {
        return Inertia::render('auth/ResellerSignup');
    }

    public function storeFe(Request $request): RedirectResponse
    {
        return $this->store($request, config('billing.bundles.fe', ['FE']), 'FE access activated.');
    }

    public function storeBundle(Request $request): RedirectResponse
    {
        return $this->store($request, config('billing.bundles.full', []), 'Full bundle access activated.');
    }

    public function storeReseller(Request $request): RedirectResponse
    {
        return $this->store($request, config('billing.bundles.reseller', ['FE', 'OTO5']), 'Reseller access activated.');
    }

    /**
     * @param list<string> $entitlements
     */
    private function store(Request $request, array $entitlements, string $message): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user) {
            $user->forceFill([
                'name' => $data['name'],
                'password' => bcrypt($data['password']),
            ])->save();
        } else {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => bcrypt($data['password']),
                'created_by' => 1,
            ]);
        }

        $this->entitlements->grant($user, $entitlements);
        $this->bootstrap->ensurePersonalOrganization($user);

        if ($request->user()?->is($user)) {
            return redirect()->route('dashboard')->with('success', $message);
        }

        return redirect()->route('login')->with('success', $message);
    }
}
