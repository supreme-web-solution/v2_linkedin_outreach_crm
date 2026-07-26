<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\V2MiniStat;
use App\Models\V2UserActivity;
use App\V2\Services\DailyUsageQuotaService;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsWebController extends Controller
{
    public function index(DailyUsageQuotaService $quotas): Response
    {
        $user = auth()->user();
        $orgId = $user->current_organization_id;

        $latestMini = $orgId
            ? V2MiniStat::where('organization_id', $orgId)->latest()->first()
            : null;

        $moduleActivity = $orgId
            ? V2UserActivity::where('organization_id', $orgId)
                ->select('module', DB::raw('SUM(stat) as total'), DB::raw('COUNT(*) as events'))
                ->groupBy('module')
                ->orderByDesc('total')
                ->get()
            : collect();

        $dailyActivity = $orgId
            ? V2UserActivity::where('organization_id', $orgId)
                ->where('created_at', '>=', now()->subDays(14))
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('COUNT(*) as events'),
                    DB::raw('SUM(stat) as total')
                )
                ->groupBy('date')
                ->orderBy('date')
                ->get()
            : collect();

        $webhookActivity = $orgId
            ? V2UserActivity::where('organization_id', $orgId)
                ->where('module', 'webhook')
                ->select('identifier', DB::raw('COUNT(*) as count'))
                ->groupBy('identifier')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
            : collect();

        return Inertia::render('crm/Analytics', [
            'latestMini' => $latestMini,
            'moduleActivity' => $moduleActivity,
            'dailyActivity' => $dailyActivity,
            'webhookActivity' => $webhookActivity,
            'hasOrg' => (bool) $orgId,
            'dailyQuotas' => $quotas->forUser($user),
        ]);
    }
}
