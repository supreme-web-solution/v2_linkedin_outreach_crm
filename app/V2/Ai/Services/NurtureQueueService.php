<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2OutreachLead;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class NurtureQueueService
{
    /**
     * @return array{
     *     items: list<array<string,mixed>>,
     *     counts: array<string,int>,
     *     summary: string,
     *     nurture_brief: array<string,mixed>
     * }
     */
    public function forUser(User $user, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $leads = $this->nurtureLeadsQuery($user)->with(['campaign', 'progress'])->get();
        $conversations = $this->conversationsForLeads($user, $leads->pluck('id')->all());

        $items = [];
        foreach ($leads as $lead) {
            $conversation = $conversations->get($lead->id);
            $items[] = $this->mapLead($lead, $conversation);
        }

        usort($items, function (array $a, array $b) {
            $ta = $a['follow_up_at'] ?? '';
            $tb = $b['follow_up_at'] ?? '';

            return strcmp((string) $ta, (string) $tb);
        });

        $counts = $this->countsFromItems($items);
        $brief = $this->briefFromCounts($counts);

        return [
            'items' => array_slice($items, 0, $limit),
            'counts' => $counts,
            'summary' => $this->summaryFromCounts($counts),
            'nurture_brief' => $brief,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function briefForUser(User $user): array
    {
        $leads = $this->nurtureLeadsQuery($user)->get(['id', 'meta']);
        $items = $leads->map(fn (V2OutreachLead $lead) => $this->mapLead($lead, null))->all();
        $counts = $this->countsFromItems($items);

        return $this->briefFromCounts($counts);
    }

    /**
     * Nurture leads due now or overdue for follow-up.
     *
     * @return array{
     *     items: list<array<string,mixed>>,
     *     counts: array<string,int>,
     *     summary: string,
     *     alex_starter: string
     * }
     */
    public function dueForFollowUp(User $user, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $queue = $this->forUser($user, 100);
        $dueItems = array_values(array_filter(
            $queue['items'],
            fn (array $item) => in_array($item['status'] ?? '', ['overdue', 'due_soon'], true),
        ));

        usort($dueItems, function (array $a, array $b) {
            if (($a['status'] ?? '') === ($b['status'] ?? '')) {
                return strcmp((string) ($a['follow_up_at'] ?? ''), (string) ($b['follow_up_at'] ?? ''));
            }

            return ($a['status'] ?? '') === 'overdue' ? -1 : 1;
        });

        $overdue = count(array_filter($dueItems, fn ($i) => ($i['status'] ?? '') === 'overdue'));
        $dueSoon = count(array_filter($dueItems, fn ($i) => ($i['status'] ?? '') === 'due_soon'));

        $summary = $dueItems === []
            ? 'Nobody is due for nurture follow-up right now.'
            : sprintf(
                '%d due for nurture follow-up%s.',
                count($dueItems),
                ($parts = array_filter([
                    $overdue > 0 ? "{$overdue} overdue" : null,
                    $dueSoon > 0 ? "{$dueSoon} this week" : null,
                ])) !== [] ? ' ('.implode(', ', $parts).')' : '',
            );

        return [
            'items' => array_slice($dueItems, 0, $limit),
            'counts' => [
                'due_total' => count($dueItems),
                'overdue' => $overdue,
                'due_this_week' => $dueSoon,
            ],
            'summary' => $summary,
            'alex_starter' => "Who's due for nurture follow-up?",
            'inbox_nurture_url' => url('/inbox?tab=nurture'),
        ];
    }

    /**
     * @param  list<int>  $leadIds
     */
    private function conversationsForLeads(User $user, array $leadIds): \Illuminate\Support\Collection
    {
        if ($leadIds === []) {
            return collect();
        }

        return V2Conversation::query()
            ->where('user_id', $user->id)
            ->forUnifiedInbox()
            ->whereIn('meta->outreach_lead_id', $leadIds)
            ->get()
            ->keyBy(fn (V2Conversation $c) => (int) Arr::get($c->meta ?? [], 'outreach_lead_id', 0));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<V2OutreachLead>
     */
    private function nurtureLeadsQuery(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return V2OutreachLead::query()
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $user->id))
            ->where('meta->qualification->stage', 'nurture');
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLead(V2OutreachLead $lead, ?V2Conversation $conversation): array
    {
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $qualification = is_array($meta['qualification'] ?? null) ? $meta['qualification'] : [];
        $followUpRaw = (string) ($qualification['nurture_follow_up_at'] ?? '');
        $followUpAt = $followUpRaw !== '' ? Carbon::parse($followUpRaw) : null;
        $daysUntil = $followUpAt ? (int) now()->startOfDay()->diffInDays($followUpAt->startOfDay(), false) : null;

        $channel = $conversation?->provider;
        $status = 'scheduled';
        if ($daysUntil !== null) {
            if ($daysUntil < 0) {
                $status = 'overdue';
            } elseif ($daysUntil <= 7) {
                $status = 'due_soon';
            }
        }

        $campaign = $lead->campaign;

        return [
            'outreach_lead_id' => $lead->id,
            'conversation_id' => $conversation?->id,
            'prospect_name' => $lead->full_name ?: Arr::get($meta, 'prospect_name', 'Prospect'),
            'channel' => $channel,
            'channel_label' => $channel ? OutreachChannelRegistry::channelLabel((string) $channel) : null,
            'campaign_id' => $campaign?->id,
            'campaign_name' => $campaign?->name ?? 'Campaign',
            'reason' => (string) ($qualification['notes'] ?? 'Moved to nurture'),
            'follow_up_at' => $followUpAt?->toIso8601String(),
            'follow_up_days' => (int) ($qualification['nurture_follow_up_days'] ?? 90),
            'days_until_follow_up' => $daysUntil,
            'status' => $status,
            'inbox_url' => $conversation
                ? url('/inbox/'.$conversation->provider.'/'.$conversation->id)
                : null,
            'campaign_url' => $campaign ? url('/outreach/'.$campaign->id) : null,
            'alex_starter' => 'Follow up with '.($lead->full_name ?: 'this lead').' — they asked to reconnect later.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string, int>
     */
    private function countsFromItems(array $items): array
    {
        $dueThisWeek = 0;
        $overdue = 0;

        foreach ($items as $item) {
            if (($item['status'] ?? '') === 'overdue') {
                $overdue++;
            } elseif (($item['status'] ?? '') === 'due_soon') {
                $dueThisWeek++;
            }
        }

        return [
            'total' => count($items),
            'due_this_week' => $dueThisWeek,
            'overdue' => $overdue,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function briefFromCounts(array $counts): array
    {
        $total = (int) ($counts['total'] ?? 0);
        $dueThisWeek = (int) ($counts['due_this_week'] ?? 0);
        $overdue = (int) ($counts['overdue'] ?? 0);

        if ($total === 0) {
            return [
                'headline' => 'No leads in nurture.',
                'total' => 0,
                'due_this_week' => 0,
                'overdue' => 0,
            ];
        }

        $parts = [sprintf('%d in nurture', $total)];
        if ($overdue > 0) {
            $parts[] = sprintf('%d overdue', $overdue);
        } elseif ($dueThisWeek > 0) {
            $parts[] = sprintf('%d due this week', $dueThisWeek);
        }

        return [
            'headline' => implode(' · ', $parts),
            'total' => $total,
            'due_this_week' => $dueThisWeek,
            'overdue' => $overdue,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function summaryFromCounts(array $counts): string
    {
        $total = (int) ($counts['total'] ?? 0);
        if ($total === 0) {
            return 'Nobody is in nurture right now.';
        }

        $dueThisWeek = (int) ($counts['due_this_week'] ?? 0);
        $overdue = (int) ($counts['overdue'] ?? 0);

        return sprintf(
            '%d in nurture%s%s.',
            $total,
            $overdue > 0 ? " ({$overdue} overdue)" : '',
            $dueThisWeek > 0 && $overdue === 0 ? " ({$dueThisWeek} due this week)" : '',
        );
    }
}
