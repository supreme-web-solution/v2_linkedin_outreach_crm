<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\V2Call;
use App\Models\V2Campaign;
use App\Models\V2Conversation;
use App\Models\V2Lead;
use App\Models\V2Message;
use App\Models\V2Organization;
use App\Models\V2UserActivity;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();
        $orgId = $user->current_organization_id;

        $stats = [
            'leads' => V2Lead::where('user_id', $user->id)->count(),
            'campaigns' => $orgId ? V2Campaign::where('organization_id', $orgId)->count() : 0,
            'conversations' => V2Conversation::where('user_id', $user->id)->count(),
            'calls' => $orgId ? V2Call::where('organization_id', $orgId)->count() : 0,
            'messages_sent' => V2Message::where('direction', 'outbound')
                ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id))
                ->count(),
            'unread_conversations' => V2Conversation::where('user_id', $user->id)
                ->where('status', 'active')
                ->count(),
        ];

        $recentActivity = $orgId
            ? V2UserActivity::where('organization_id', $orgId)
                ->latest()
                ->limit(5)
                ->get(['module', 'identifier', 'stat', 'created_at'])
            : collect();

        $org = $orgId ? V2Organization::find($orgId) : null;

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentActivity' => $recentActivity,
            'organization' => $org,
            'hasOrg' => (bool) $orgId,
        ]);
    }
}
