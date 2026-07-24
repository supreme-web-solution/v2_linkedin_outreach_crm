<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\V2IntegrationAccount;
use App\V2\Services\LinkedInConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SocialAccountWebController extends Controller
{
    public function __construct(private readonly LinkedInConnectionService $linkedin)
    {
    }

    public function index(): Response
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $unipileAccounts = V2IntegrationAccount::where('user_id', $user->id)
            ->where('provider', 'linkedin')
            ->latest()
            ->get()
            ->map(fn ($a) => $this->linkedin->serializeAccount($a));

        return Inertia::render('settings/SocialAccounts', [
            'unipileAccounts' => $unipileAccounts,
            'connected' => request()->boolean('connected'),
            'connectionError' => request()->boolean('error'),
            'unipileConfigured' => $this->linkedin->isUnipileConfigured(),
        ]);
    }

    public function disconnectUnipile(string $id): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        try {
            $this->linkedin->disconnect($user, (int) $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return back()->with('error', 'Account not found.');
        }

        return back()->with('success', 'LinkedIn account disconnected from Unipile.');
    }
}
