<?php

namespace App\V2\Web;

use App\Models\AudienceList;
use App\Models\SnLead;

/**
 * SQL aggregates for lead list pages — never hydrates the full list into PHP.
 */
class LeadListStatsService
{
    /**
     * @return array<string, int>
     */
    public function emailFilterCountsForAudience(string $audienceId): array
    {
        $row = AudienceList::query()
            ->where('audience_id', $audienceId)
            ->selectRaw("
                COUNT(*) as total_all,
                SUM(CASE WHEN con_email IS NOT NULL AND con_email != '' THEN 1 ELSE 0 END) as with_email,
                SUM(CASE
                    WHEN (con_email IS NULL OR con_email = '')
                        AND (email_fetch_status = 'completed' OR email_fetch_attempted_at IS NOT NULL)
                    THEN 1 ELSE 0 END) as without_email,
                SUM(CASE WHEN email_fetch_status IS NULL AND email_fetch_attempted_at IS NULL THEN 1 ELSE 0 END) as not_fetched,
                SUM(CASE WHEN email_fetch_status IN ('pending', 'processing') THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        return [
            'all' => (int) ($row->total_all ?? 0),
            'with_email' => (int) ($row->with_email ?? 0),
            'without_email' => (int) ($row->without_email ?? 0),
            'not_fetched' => (int) ($row->not_fetched ?? 0),
            'pending' => (int) ($row->pending ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contactStatsForAudience(string $audienceId, int $queuePending): array
    {
        $row = AudienceList::query()
            ->where('audience_id', $audienceId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN con_email IS NOT NULL AND con_email != '' THEN 1 ELSE 0 END) as emails_found,
                SUM(CASE WHEN con_phone IS NOT NULL AND con_phone != '' THEN 1 ELSE 0 END) as phones_found,
                SUM(CASE WHEN email_fetch_status IN ('pending', 'processing') THEN 1 ELSE 0 END) as email_pending,
                SUM(CASE WHEN phone_fetch_status IN ('pending', 'processing') THEN 1 ELSE 0 END) as phone_pending,
                SUM(CASE WHEN email_fetch_attempted_at IS NOT NULL THEN 1 ELSE 0 END) as email_searched,
                SUM(CASE WHEN phone_fetch_attempted_at IS NOT NULL THEN 1 ELSE 0 END) as phone_searched,
                SUM(CASE
                    WHEN (con_email IS NULL OR con_email = '')
                        AND (email_fetch_status IS NULL OR email_fetch_status NOT IN ('pending', 'processing'))
                        AND email_fetch_attempted_at IS NULL
                    THEN 1 ELSE 0 END) as fetchable
            ")
            ->first();

        return $this->formatContactStats($row, $queuePending);
    }

    /**
     * @return array<string, mixed>
     */
    public function contactStatsForSnList(string $listHash, int $queuePending): array
    {
        $row = SnLead::query()
            ->where('sn_list_id', $listHash)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as emails_found,
                SUM(CASE WHEN phone IS NOT NULL AND phone != '' THEN 1 ELSE 0 END) as phones_found,
                SUM(CASE WHEN email_fetch_status IN ('pending', 'processing') THEN 1 ELSE 0 END) as email_pending,
                SUM(CASE WHEN phone_fetch_status IN ('pending', 'processing') THEN 1 ELSE 0 END) as phone_pending,
                SUM(CASE
                    WHEN email_fetch_attempted_at IS NOT NULL
                        OR email_fetch_status = 'completed'
                        OR ((email_fetch_status IS NULL OR email_fetch_status = '') AND phone_fetch_attempted_at IS NOT NULL)
                    THEN 1 ELSE 0 END) as email_searched,
                SUM(CASE WHEN phone_fetch_attempted_at IS NOT NULL THEN 1 ELSE 0 END) as phone_searched,
                SUM(CASE
                    WHEN (email IS NULL OR email = '')
                        AND (email_fetch_status IS NULL OR email_fetch_status NOT IN ('pending', 'processing', 'completed'))
                        AND email_fetch_attempted_at IS NULL
                        AND NOT (
                            (email_fetch_status IS NULL OR email_fetch_status = '')
                            AND phone_fetch_attempted_at IS NOT NULL
                        )
                    THEN 1 ELSE 0 END) as fetchable
            ")
            ->first();

        return $this->formatContactStats($row, $queuePending);
    }

    /**
     * @param  object|null  $row
     * @return array<string, mixed>
     */
    private function formatContactStats(?object $row, int $queuePending): array
    {
        $total = (int) ($row->total ?? 0);
        $emailsFound = (int) ($row->emails_found ?? 0);
        $phonesFound = (int) ($row->phones_found ?? 0);
        $emailPending = max((int) ($row->email_pending ?? 0), $queuePending);
        $phonePending = (int) ($row->phone_pending ?? 0);
        $emailSearched = (int) ($row->email_searched ?? 0);
        $phoneSearched = (int) ($row->phone_searched ?? 0);
        $fetchable = (int) ($row->fetchable ?? 0);
        $processed = min($total, $emailSearched + $emailPending);

        return [
            'total' => $total,
            'running' => $emailPending > 0 || $phonePending > 0,
            'processed' => $processed,
            'fetchable' => $fetchable,
            'emails' => [
                'found' => $emailsFound,
                'total' => $total,
                'pending' => $emailPending,
                'searched' => $emailSearched,
                'fill_percent' => $total > 0 ? (int) round($emailsFound / $total * 100) : 0,
                'hit_rate' => $emailSearched > 0 ? (int) round($emailsFound / $emailSearched * 100) : 0,
            ],
            'phones' => [
                'found' => $phonesFound,
                'total' => $total,
                'pending' => $phonePending,
                'searched' => $phoneSearched,
                'fill_percent' => $total > 0 ? (int) round($phonesFound / $total * 100) : 0,
                'hit_rate' => $phoneSearched > 0 ? (int) round($phonesFound / $phoneSearched * 100) : 0,
            ],
        ];
    }
}
