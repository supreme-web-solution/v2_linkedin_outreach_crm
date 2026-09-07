<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\V2Organization;
use App\Models\V2UserActivity;
use App\V2\Ai\Services\OnboardingWizardService;
use App\V2\Services\DashboardStatsService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(DashboardStatsService $stats, OnboardingWizardService $onboarding): Response
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);

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
            'recentActivity' => $recentActivity,
            'organization' => $org,
            'hasOrg' => (bool) $orgId,
            'onboarding' => $onboardingStatus,
        ]);
    }
}
