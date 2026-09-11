<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\V2Organization;
use App\Models\V2UserActivity;
use App\V2\Ai\Services\AcquisitionExperimentService;
use App\V2\Ai\Services\OnboardingWizardService;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\AcquisitionFunnelService;
use App\V2\Services\ChannelConnectionService;
use App\V2\Services\DashboardStatsService;
use App\V2\Services\IntegrationUserErrorMapper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        DashboardStatsService $stats,
        OnboardingWizardService $onboarding,
        ChannelConnectionService $channels,
        AcquisitionFunnelService $funnel,
        AcquisitionExperimentService $experiment,
    ): Response|RedirectResponse {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);

        // Same completion path as Integrations: Unipile returns account_id on success_redirect_url.
        $accountId = trim((string) $request->query('account_id', ''));
        $channelKey = trim((string) $request->query('channel', ''));
        $connectedFlag = trim((string) $request->query('connected', ''));
        $provider = strtoupper(trim((string) $request->query('provider', '')));

        if ($channelKey === '' && $connectedFlag !== '' && $connectedFlag !== '1') {
            $channelKey = $connectedFlag;
        }

        if ($accountId !== '' && $request->query('onboarding') === '1' && ($channelKey !== '' || $connectedFlag !== '')) {
            if ($channelKey === '') {
                $channelKey = OutreachChannelRegistry::channelKeyForUnipileType($provider) ?? '';
            }

            try {
                $account = $channels->completeHostedConnection(
                    $user,
                    $accountId,
                    $channelKey !== '' ? $channelKey : null,
                );
                $resolvedChannel = (string) (is_array($account->meta) ? ($account->meta['channel_key'] ?? $channelKey) : $channelKey);

                return redirect()->route('dashboard', [
                    'onboarding' => 1,
                    'connected' => 1,
                    'channel' => $resolvedChannel !== '' ? $resolvedChannel : $channelKey,
                ]);
            } catch (\Throwable $e) {
                IntegrationUserErrorMapper::log($e, 'onboarding_complete_hosted', [
                    'channel' => $channelKey,
                    'account_id' => $accountId,
                ]);

                return redirect()->route('dashboard', [
                    'onboarding' => 1,
                    'error' => 1,
                    'channel' => $channelKey !== '' ? $channelKey : null,
                ]);
            }
        }

        $recentActivity = $orgId
            ? V2UserActivity::where('organization_id', $orgId)
                ->latest()
                ->limit(5)
                ->get(['module', 'identifier', 'stat', 'created_at'])
            : collect();

        $org = $orgId ? V2Organization::find($orgId) : null;
        $onboardingStatus = $orgId > 0 ? $onboarding->status($user, $orgId) : ['show' => false, 'completed' => true];

        return Inertia::render('Dashboard', [
            'stats' => $stats->forUser($user),
            'acquisitionFunnel' => $funnel->forUser($user),
            'acquisitionExperiment' => $orgId > 0 ? $experiment->activeExperiment($user, $orgId) : null,
            'recentActivity' => $recentActivity,
            'organization' => $org,
            'hasOrg' => (bool) $orgId,
            'onboarding' => $onboardingStatus,
        ]);
    }
}
