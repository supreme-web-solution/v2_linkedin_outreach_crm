<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\V2UserActivity;
use App\V2\Campaign\CampaignLinkedInGuard;
use App\V2\Services\ChannelConnectionService;
use App\V2\Services\DailyEnrichmentQuotaService;
use App\V2\Services\EntitlementService;
use App\V2\Services\InboxUnreadService;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $entitlements = app(EntitlementService::class);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'entitlements' => $user ? $entitlements->list($user) : [],
            'isPlatformAdmin' => $user ? $entitlements->isPlatformAdmin($user) : false,
            'isReseller' => $user ? $entitlements->isReseller($user) : false,
            'isImpersonating' => (bool) session('impersonator_id'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'linkedinConnection' => fn () => $user
                ? app(CampaignLinkedInGuard::class)->connectionSummary($user)
                : null,
            'connectedChannels' => fn () => $user
                ? app(ChannelConnectionService::class)->summarizeForUser($user)
                : [],
            'enabledChannels' => OutreachChannelRegistry::enabledChannelKeys(),
            'notifications' => fn () => $user && $user->current_organization_id
                ? V2UserActivity::query()
                    ->where('user_id', $user->id)
                    ->where('organization_id', $user->current_organization_id)
                    ->latest()
                    ->limit(15)
                    ->get(['id', 'module', 'identifier', 'stat', 'meta', 'created_at'])
                    ->map(fn (V2UserActivity $row) => [
                        'id' => $row->id,
                        'module' => $row->module,
                        'identifier' => $row->identifier,
                        'stat' => $row->stat,
                        'meta' => is_array($row->meta) ? $row->meta : [],
                        'created_at' => $row->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all()
                : [],
            'dailyEnrichmentQuota' => fn () => app(DailyEnrichmentQuotaService::class)->payloadForUser($user),
            'inboxUnreadCount' => fn () => $user
                ? app(InboxUnreadService::class)->unreadCountForUser($user->id)
                : 0,
        ];
    }
}
