<?php

namespace App\V2\Services;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use Illuminate\Database\Eloquent\Builder;

/**
 * End-to-end acquisition funnel for dashboard and sales briefs.
 *
 * targeted → contacted → responses → conversations → qualified → demos → customers
 */
class AcquisitionFunnelService
{
    public function __construct(
        private readonly DashboardStatsService $dashboardStats,
    ) {}

    /**
     * @return array{
     *     period: string,
     *     headline: string,
     *     stages: list<array{key:string,label:string,count:int,rate_from_previous:?float,href:?string}>,
     *     summary: array<string, int|float>
     * }
     */
    public function forUser(User $user, string $period = 'all'): array
    {
        $orgId = (int) ($user->current_organization_id ?? 0);
        $since = $this->periodStart($period);

        $targeted = $this->countTargeted($user, $orgId, $since);
        if ($targeted === 0) {
            $targeted = $this->dashboardStats->leadCountForUser($user->id);
        }

        $contacted = $this->countContacted($user, $orgId, $since);
        $responses = $this->countResponses($user, $orgId, $since);
        $conversations = $this->countMeaningfulConversations($user, $since);
        $qualified = $this->countQualifiedOpportunities($user, $orgId, $since);
        $demos = $this->countDemos($user, $orgId, $since);
        $customers = $this->countCustomers($user, $orgId, $since);

        $raw = [
            ['key' => 'targeted', 'label' => 'Targeted prospects', 'count' => $targeted, 'href' => '/leads'],
            ['key' => 'contacted', 'label' => 'Successfully contacted', 'count' => $contacted, 'href' => '/outreach'],
            ['key' => 'responses', 'label' => 'Responses', 'count' => $responses, 'href' => '/conversations'],
            ['key' => 'conversations', 'label' => 'Meaningful conversations', 'count' => $conversations, 'href' => '/conversations'],
            ['key' => 'qualified', 'label' => 'Qualified opportunities', 'count' => $qualified, 'href' => '/calls'],
            ['key' => 'demos', 'label' => 'Demos booked', 'count' => $demos, 'href' => '/calls'],
            ['key' => 'customers', 'label' => 'Customers won', 'count' => $customers, 'href' => '/calls'],
        ];

        $stages = [];
        $previous = null;
        foreach ($raw as $row) {
            $rate = ($previous !== null && $previous > 0)
                ? round(($row['count'] / $previous) * 100, 1)
                : null;
            $stages[] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'count' => $row['count'],
                'rate_from_previous' => $rate,
                'href' => $row['href'],
            ];
            $previous = max($previous ?? 0, $row['count']);
        }

        $responseRate = $contacted > 0 ? round(($responses / $contacted) * 100, 1) : 0.0;
        $qualifiedRate = $targeted > 0 ? round(($qualified / $targeted) * 100, 1) : 0.0;

        return [
            'period' => $period,
            'headline' => 'Acquisition engine',
            'stages' => $stages,
            'summary' => [
                'targeted' => $targeted,
                'contacted' => $contacted,
                'responses' => $responses,
                'conversations' => $conversations,
                'qualified' => $qualified,
                'demos' => $demos,
                'customers' => $customers,
                'response_rate' => $responseRate,
                'qualified_per_100_targeted' => $qualifiedRate,
            ],
        ];
    }

    private function periodStart(string $period): ?\Carbon\CarbonInterface
    {
        return match ($period) {
            'today' => now()->startOfDay(),
            'week' => now()->subDays(7),
            'month' => now()->subDays(30),
            default => null,
        };
    }

    private function countTargeted(User $user, int $orgId, ?\Carbon\CarbonInterface $since): int
    {
        return (int) $this->outreachLeadQuery($user, $orgId)
            ->when($since, fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->whereNotIn('status', ['skipped'])
            ->count();
    }

    private function countContacted(User $user, int $orgId, ?\Carbon\CarbonInterface $since): int
    {
        $fromLeads = (int) $this->outreachLeadQuery($user, $orgId)
            ->when($since, fn (Builder $q) => $q->where('updated_at', '>=', $since))
            ->whereIn('status', ['running', 'done', 'replied'])
            ->count();

        if ($fromLeads > 0) {
            return $fromLeads;
        }

        return (int) V2Message::query()
            ->where('direction', 'outbound')
            ->when($since, fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->whereHas('conversation', fn (Builder $q) => $q
                ->where('user_id', $user->id)
                ->forOutreachInbox())
            ->distinct('conversation_id')
            ->count('conversation_id');
    }

    private function countResponses(User $user, int $orgId, ?\Carbon\CarbonInterface $since): int
    {
        $repliedLeads = (int) $this->outreachLeadQuery($user, $orgId)
            ->when($since, fn (Builder $q) => $q->where('updated_at', '>=', $since))
            ->where('status', 'replied')
            ->count();

        if ($repliedLeads > 0) {
            return $repliedLeads;
        }

        return (int) V2Conversation::query()
            ->where('user_id', $user->id)
            ->forOutreachInbox()
            ->when($since, fn (Builder $q) => $q->where('updated_at', '>=', $since))
            ->whereHas('messages', fn (Builder $q) => $q->where('direction', 'inbound'))
            ->count();
    }

    private function countMeaningfulConversations(User $user, ?\Carbon\CarbonInterface $since): int
    {
        return (int) V2Conversation::query()
            ->where('user_id', $user->id)
            ->forOutreachInbox()
            ->when($since, fn (Builder $q) => $q->where('updated_at', '>=', $since))
            ->whereHas('messages', fn (Builder $q) => $q->where('direction', 'inbound'))
            ->whereHas('messages', fn (Builder $q) => $q->where('direction', 'outbound'))
            ->count();
    }

    private function countQualifiedOpportunities(User $user, int $orgId, ?\Carbon\CarbonInterface $since): int
    {
        return (int) $this->outreachLeadQuery($user, $orgId)
            ->when($since, fn (Builder $q) => $q->where('updated_at', '>=', $since))
            ->where(function (Builder $q) {
                foreach (['sql', 'qualified', 'meeting_booked'] as $stage) {
                    $q->orWhere('meta->qualification->stage', $stage);
                }
            })
            ->count();
    }

    private function countDemos(User $user, int $orgId, ?\Carbon\CarbonInterface $since): int
    {
        if ($orgId <= 0) {
            return 0;
        }

        return (int) V2Call::query()
            ->where('organization_id', $orgId)
            ->when($since, fn (Builder $q) => $q->where('updated_at', '>=', $since))
            ->whereIn('status', ['booked', 'completed'])
            ->whereNotNull('scheduled_call_at')
            ->count();
    }

    private function countCustomers(User $user, int $orgId, ?\Carbon\CarbonInterface $since): int
    {
        $fromLeads = (int) $this->outreachLeadQuery($user, $orgId)
            ->when($since, fn (Builder $q) => $q->where('updated_at', '>=', $since))
            ->where(function (Builder $q) {
                $q->where('meta->qualification->stage', 'customer')
                    ->orWhere('meta->conversion->stage', 'customer');
            })
            ->count();

        $fromCalls = $orgId > 0
            ? (int) V2Call::query()
                ->where('organization_id', $orgId)
                ->when($since, fn (Builder $q) => $q->where('updated_at', '>=', $since))
                ->where('meta->outcome', 'customer')
                ->count()
            : 0;

        return $fromLeads + $fromCalls;
    }

    private function outreachLeadQuery(User $user, int $orgId): Builder
    {
        return V2OutreachLead::query()->whereHas('campaign', function (Builder $q) use ($user, $orgId) {
            if ($orgId > 0) {
                $q->where(function (Builder $inner) use ($orgId, $user) {
                    $inner->where('organization_id', $orgId)
                        ->orWhere(function (Builder $legacy) use ($user) {
                            $legacy->whereNull('organization_id')->where('user_id', $user->id);
                        });
                });
            } else {
                $q->where('user_id', $user->id);
            }
        });
    }
}
