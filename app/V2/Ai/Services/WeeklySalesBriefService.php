<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2OutreachCampaign;
use App\V2\Services\DashboardStatsService;
use Illuminate\Support\Carbon;

class WeeklySalesBriefService
{
    public function __construct(
        private readonly AttentionQueueService $attention,
        private readonly DashboardStatsService $dashboard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user, int $organizationId): array
    {
        $since = Carbon::now()->subDays(7);
        $dash = $this->dashboard->forUser($user);

        $newReplies = (int) V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->withCount(['outreachLeads as replied_count' => fn ($q) => $q
                ->where('status', 'replied')
                ->where('updated_at', '>=', $since)])
            ->get()
            ->sum('replied_count');

        $activeOutreach = V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'running', 'paused'])
            ->count();

        $upcomingCalls = V2Call::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'booked')
            ->where('scheduled_call_at', '>=', now())
            ->where('scheduled_call_at', '<=', now()->addDays(7))
            ->orderBy('scheduled_call_at')
            ->limit(5)
            ->get()
            ->map(fn (V2Call $call) => [
                'call_id' => $call->id,
                'prospect_name' => $call->prospect_name,
                'scheduled_call_at' => $call->scheduled_call_at?->toIso8601String(),
                'call_url' => url('/calls/'.$call->id),
            ])
            ->all();

        $attention = $this->attention->forUser($user, $organizationId, 5);

        $lines = [
            'Weekly sales brief (last 7 days):',
            '• New replies: '.$newReplies,
            '• Unread inbox: '.($dash['unread_conversations'] ?? 0),
            '• Hot leads: '.($attention['counts']['hot'] ?? 0),
            '• Upcoming calls (7d): '.count($upcomingCalls),
            '• Active outreach campaigns: '.$activeOutreach,
        ];

        if (($attention['counts']['hot'] ?? 0) > 0) {
            $lines[] = '→ Say "attention" and draft replies for hot leads.';
        }

        if ($upcomingCalls !== []) {
            $lines[] = '→ Say "meeting brief" before your next call.';
        }

        return [
            'period' => 'week',
            'metrics' => [
                'new_replies_7d' => $newReplies,
                'unread_conversations' => $dash['unread_conversations'] ?? 0,
                'active_outreach' => $activeOutreach,
                'upcoming_calls_7d' => count($upcomingCalls),
            ],
            'attention' => $attention['counts'],
            'upcoming_calls' => $upcomingCalls,
            'brief' => implode("\n", $lines),
        ];
    }
}
