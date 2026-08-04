<?php

namespace App\V2\Outreach;

use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use Illuminate\Support\Facades\DB;

/**
 * SQL-backed readiness / enrichment stats — never hydrates full lists into PHP.
 */
class OutreachLeadReadinessAggregator
{
    public function __construct(
        private readonly OutreachLeadContactResolver $contactResolver,
    ) {}

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @param  array<int, array<string, mixed>>  $nodeModel
     * @return array<string, mixed>
     */
    public function previewForLists(array $leadLists, array $nodeModel, ?int $userId = null): array
    {
        $required = OutreachChannelRegistry::contactRequiredChannelsForNodes($nodeModel);
        $totals = $this->aggregateListTotals($leadLists, $userId, $required);
        $total = $totals['total'];
        $fullyReady = $totals['fully_ready'];
        $channelStats = $totals['channels'];
        $audienceListsSelected = collect($leadLists)->contains(fn ($l) => ($l['list_src'] ?? '') === 'aud');

        $emailFetch = $this->emailFetchStatsSql($leadLists, $audienceListsSelected);
        $phoneFetch = $this->phoneFetchStatsSql($leadLists);
        $whatsapp = $this->whatsAppVerifyStatsSql($leadLists, $required, $userId);
        $handles = $this->handleResolveStatsSql($leadLists, $required, $userId);
        $contactPrep = $this->contactPrepFromParts($required, $emailFetch, $phoneFetch, $whatsapp, $handles);
        $warnings = $this->buildWarnings($required, $channelStats, $total);

        return [
            'total_leads' => $total,
            'fully_ready' => $fullyReady,
            'will_skip_any' => max(0, $total - $fullyReady),
            'required_channels' => $required,
            'channels' => $channelStats,
            'email_fetch' => $emailFetch,
            'phone_fetch' => $phoneFetch,
            'whatsapp_verify' => $whatsapp,
            'handle_resolve' => $handles,
            'contact_prep' => $contactPrep,
            'warnings' => $warnings,
            'can_launch' => $total > 0,
            'should_confirm_launch' => ($total - $fullyReady) > 0 && $total > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function enrichmentStatsForImportList(string $listHash, int $userId): array
    {
        $importList = V2OutreachImportList::query()
            ->where('user_id', $userId)
            ->where('list_hash', $listHash)
            ->first();

        if (! $importList) {
            return [
                'total' => 0,
                'whatsapp_verify' => [
                    'with_phone' => 0,
                    'verified' => 0,
                    'needs_verify' => 0,
                    'can_verify' => false,
                ],
                'handle_resolve' => [
                    'needs_resolve' => 0,
                    'can_resolve' => false,
                    'channels' => OutreachChannelRegistry::enabledSocialHandleChannels(),
                ],
                'can_enrich' => false,
                'fetchable' => 0,
            ];
        }

        $socialChannels = OutreachChannelRegistry::enabledSocialHandleChannels();
        $handleCases = [];
        foreach ($socialChannels as $channel) {
            $h = "{$channel}_handle";
            $p = "{$channel}_provider_id";
            $handleCases[] = "CASE WHEN {$h} IS NOT NULL AND {$h} != '' AND ({$p} IS NULL OR {$p} = '') THEN 1 ELSE 0 END";
        }
        $needsResolveExpr = $handleCases === [] ? '0' : '('.implode(' + ', $handleCases).')';

        $row = V2OutreachImportLead::query()
            ->where('import_list_id', $importList->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN phone IS NOT NULL AND phone != '' THEN 1 ELSE 0 END) as with_phone,
                SUM(CASE WHEN phone IS NOT NULL AND phone != '' AND whatsapp_provider_id IS NOT NULL AND whatsapp_provider_id != '' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN phone IS NOT NULL AND phone != '' AND (whatsapp_provider_id IS NULL OR whatsapp_provider_id = '') THEN 1 ELSE 0 END) as needs_verify,
                SUM({$needsResolveExpr}) as needs_resolve,
                SUM(CASE
                    WHEN (phone IS NOT NULL AND phone != '' AND (whatsapp_provider_id IS NULL OR whatsapp_provider_id = ''))
                      OR ({$needsResolveExpr}) > 0
                    THEN 1 ELSE 0 END) as fetchable
            ")
            ->first();

        $needsVerify = (int) ($row->needs_verify ?? 0);
        $needsResolve = (int) ($row->needs_resolve ?? 0);
        $fetchable = (int) ($row->fetchable ?? 0);

        return [
            'total' => (int) ($row->total ?? 0),
            'whatsapp_verify' => [
                'with_phone' => (int) ($row->with_phone ?? 0),
                'verified' => (int) ($row->verified ?? 0),
                'needs_verify' => $needsVerify,
                'can_verify' => $needsVerify > 0,
            ],
            'handle_resolve' => [
                'needs_resolve' => $needsResolve,
                'can_resolve' => $needsResolve > 0,
                'channels' => $socialChannels,
            ],
            'can_enrich' => $fetchable > 0,
            'fetchable' => $fetchable,
        ];
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @param  array<int, string>  $required
     * @return array{total: int, fully_ready: int, channels: array<string, array<string, mixed>>}
     */
    private function aggregateListTotals(array $leadLists, ?int $userId, array $required): array
    {
        $total = 0;
        $readyByChannel = array_fill_keys($required, 0);

        foreach ($leadLists as $list) {
            $hash = (string) ($list['list_hash'] ?? '');
            $src = (string) ($list['list_src'] ?? '');
            if ($hash === '' || ! in_array($src, ['aud', 'sn', 'csv'], true)) {
                continue;
            }

            $stats = match ($src) {
                'csv' => $this->csvListChannelStats($hash, $userId, $required),
                'aud' => $this->audListChannelStats($hash, $userId, $required),
                'sn' => $this->snListChannelStats($hash, $userId, $required),
                default => ['total' => 0, 'ready' => []],
            };

            $total += $stats['total'];
            foreach ($required as $channel) {
                $readyByChannel[$channel] += (int) ($stats['ready'][$channel] ?? 0);
            }
        }

        $channelStats = [];
        foreach ($required as $channel) {
            $ready = $readyByChannel[$channel] ?? 0;
            $meta = $this->channelMeta($channel);
            $channelStats[$channel] = [
                'channel' => $channel,
                'label' => OutreachChannelRegistry::channelLabel($channel),
                'ready' => $ready,
                'missing' => max(0, $total - $ready),
                'total' => $total,
                'percent' => $total > 0 ? (int) round(($ready / $total) * 100) : 0,
                'field_label' => $meta['field_label'],
                'help' => $meta['help'],
                'is_messaging' => in_array($channel, OutreachChannelRegistry::enabledMessagingChannels(), true),
            ];
        }

        // Approximate fully_ready as min of channel ready counts (exact would need row-wise AND).
        $fullyReady = $total;
        if ($required !== [] && $total > 0) {
            $fullyReady = min(array_map(fn ($c) => $readyByChannel[$c] ?? 0, $required));
        } elseif ($required === []) {
            $fullyReady = $total;
        }

        return [
            'total' => $total,
            'fully_ready' => max(0, $fullyReady),
            'channels' => $channelStats,
        ];
    }

    /**
     * @param  array<int, string>  $required
     * @return array{total: int, ready: array<string, int>}
     */
    private function csvListChannelStats(string $listHash, ?int $userId, array $required): array
    {
        if (! $userId) {
            return ['total' => 0, 'ready' => []];
        }

        $importList = V2OutreachImportList::query()
            ->where('user_id', $userId)
            ->where('list_hash', $listHash)
            ->first();
        if (! $importList) {
            return ['total' => 0, 'ready' => []];
        }

        $selects = ['COUNT(*) as total'];
        foreach ($required as $channel) {
            $selects[] = $this->csvReadyCase($channel)." as ready_{$channel}";
        }

        $row = V2OutreachImportLead::query()
            ->where('import_list_id', $importList->id)
            ->selectRaw(implode(', ', $selects))
            ->first();

        $ready = [];
        foreach ($required as $channel) {
            $ready[$channel] = (int) ($row->{"ready_{$channel}"} ?? 0);
        }

        return ['total' => (int) ($row->total ?? 0), 'ready' => $ready];
    }

    private function csvReadyCase(string $channel): string
    {
        return match ($channel) {
            'linkedin' => "SUM(CASE WHEN linkedin_id IS NOT NULL AND linkedin_id != '' THEN 1 ELSE 0 END)",
            'email' => "SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END)",
            'whatsapp' => "SUM(CASE WHEN whatsapp_provider_id IS NOT NULL AND whatsapp_provider_id != '' THEN 1 ELSE 0 END)",
            'instagram' => "SUM(CASE WHEN instagram_provider_id IS NOT NULL AND instagram_provider_id != '' THEN 1 ELSE 0 END)",
            'telegram' => "SUM(CASE WHEN (telegram_provider_id IS NOT NULL AND telegram_provider_id != '') OR (phone IS NOT NULL AND phone != '') THEN 1 ELSE 0 END)",
            'twitter' => "SUM(CASE WHEN twitter_provider_id IS NOT NULL AND twitter_provider_id != '' THEN 1 ELSE 0 END)",
            default => 'SUM(0)',
        };
    }

    /**
     * Audience rows: base columns + overlay email/phone/provider via LEFT JOIN.
     *
     * @param  array<int, string>  $required
     * @return array{total: int, ready: array<string, int>}
     */
    private function audListChannelStats(string $audienceId, ?int $userId, array $required): array
    {
        $userId = (int) $userId;
        $keyExpr = "LOWER(COALESCE(NULLIF(TRIM(a.con_public_identifier), ''), NULLIF(TRIM(a.con_id), ''), ''))";

        $query = DB::table('audience_lists as a')
            ->leftJoin('v2_lead_contact_overlays as o', function ($join) use ($userId, $keyExpr) {
                $join->whereRaw("o.user_id = ? AND LOWER(o.linkedin_key) = {$keyExpr}", [$userId]);
            })
            ->where('a.audience_id', $audienceId);

        $selects = ['COUNT(*) as total'];
        foreach ($required as $channel) {
            $selects[] = $this->audReadyCase($channel)." as ready_{$channel}";
        }

        $row = $query->selectRaw(implode(', ', $selects))->first();
        $ready = [];
        foreach ($required as $channel) {
            $ready[$channel] = (int) ($row->{"ready_{$channel}"} ?? 0);
        }

        return ['total' => (int) ($row->total ?? 0), 'ready' => $ready];
    }

    private function audReadyCase(string $channel): string
    {
        return match ($channel) {
            'linkedin' => "SUM(CASE WHEN (a.con_public_identifier IS NOT NULL AND a.con_public_identifier != '') OR (a.con_id IS NOT NULL AND a.con_id != '') THEN 1 ELSE 0 END)",
            'email' => "SUM(CASE WHEN (a.con_email IS NOT NULL AND a.con_email != '') OR (o.email IS NOT NULL AND o.email != '') THEN 1 ELSE 0 END)",
            'whatsapp' => "SUM(CASE WHEN (a.whatsapp_provider_id IS NOT NULL AND a.whatsapp_provider_id != '') OR (o.whatsapp_provider_id IS NOT NULL AND o.whatsapp_provider_id != '') THEN 1 ELSE 0 END)",
            'instagram' => "SUM(CASE WHEN o.instagram_provider_id IS NOT NULL AND o.instagram_provider_id != '' THEN 1 ELSE 0 END)",
            'telegram' => "SUM(CASE WHEN (o.telegram_provider_id IS NOT NULL AND o.telegram_provider_id != '') OR (a.con_phone IS NOT NULL AND a.con_phone != '') OR (o.phone IS NOT NULL AND o.phone != '') THEN 1 ELSE 0 END)",
            'twitter' => "SUM(CASE WHEN o.twitter_provider_id IS NOT NULL AND o.twitter_provider_id != '' THEN 1 ELSE 0 END)",
            default => 'SUM(0)',
        };
    }

    /**
     * @param  array<int, string>  $required
     * @return array{total: int, ready: array<string, int>}
     */
    private function snListChannelStats(string $listHash, ?int $userId, array $required): array
    {
        $userId = (int) $userId;
        $keyExpr = "LOWER(COALESCE(NULLIF(TRIM(s.lid), ''), NULLIF(TRIM(s.sn_lid), ''), ''))";

        $query = DB::table('sn_leads as s')
            ->leftJoin('v2_lead_contact_overlays as o', function ($join) use ($userId, $keyExpr) {
                $join->whereRaw("o.user_id = ? AND LOWER(o.linkedin_key) = {$keyExpr}", [$userId]);
            })
            ->where('s.sn_list_id', $listHash);

        $selects = ['COUNT(*) as total'];
        foreach ($required as $channel) {
            $selects[] = $this->snReadyCase($channel)." as ready_{$channel}";
        }

        $row = $query->selectRaw(implode(', ', $selects))->first();
        $ready = [];
        foreach ($required as $channel) {
            $ready[$channel] = (int) ($row->{"ready_{$channel}"} ?? 0);
        }

        return ['total' => (int) ($row->total ?? 0), 'ready' => $ready];
    }

    private function snReadyCase(string $channel): string
    {
        return match ($channel) {
            'linkedin' => "SUM(CASE WHEN (s.lid IS NOT NULL AND s.lid != '') OR (s.sn_lid IS NOT NULL AND s.sn_lid != '') THEN 1 ELSE 0 END)",
            'email' => "SUM(CASE WHEN (s.email IS NOT NULL AND s.email != '') OR (o.email IS NOT NULL AND o.email != '') THEN 1 ELSE 0 END)",
            'whatsapp' => "SUM(CASE WHEN (s.whatsapp_provider_id IS NOT NULL AND s.whatsapp_provider_id != '') OR (o.whatsapp_provider_id IS NOT NULL AND o.whatsapp_provider_id != '') THEN 1 ELSE 0 END)",
            'instagram' => "SUM(CASE WHEN (s.instagram_provider_id IS NOT NULL AND s.instagram_provider_id != '') OR (o.instagram_provider_id IS NOT NULL AND o.instagram_provider_id != '') THEN 1 ELSE 0 END)",
            'telegram' => "SUM(CASE WHEN (s.telegram_provider_id IS NOT NULL AND s.telegram_provider_id != '') OR (o.telegram_provider_id IS NOT NULL AND o.telegram_provider_id != '') OR (s.phone IS NOT NULL AND s.phone != '') OR (o.phone IS NOT NULL AND o.phone != '') THEN 1 ELSE 0 END)",
            'twitter' => "SUM(CASE WHEN (s.twitter_provider_id IS NOT NULL AND s.twitter_provider_id != '') OR (o.twitter_provider_id IS NOT NULL AND o.twitter_provider_id != '') THEN 1 ELSE 0 END)",
            default => 'SUM(0)',
        };
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @return array<string, mixed>
     */
    private function emailFetchStatsSql(array $leadLists, bool $hasAudienceLists): array
    {
        $missingEmail = 0;
        $fetchable = 0;
        $pending = 0;
        $batches = [];

        foreach ($leadLists as $list) {
            if (($list['list_src'] ?? '') !== 'aud') {
                $hash = (string) ($list['list_hash'] ?? '');
                $src = (string) ($list['list_src'] ?? '');
                if ($src === 'sn' && $hash !== '') {
                    $missingEmail += (int) SnLead::query()
                        ->where('sn_list_id', $hash)
                        ->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))
                        ->count();
                } elseif ($src === 'csv' && $hash !== '') {
                    // counted via import below if needed — skip for email fetch (aud only)
                }
                continue;
            }

            $hash = (string) ($list['list_hash'] ?? '');
            if ($hash === '') {
                continue;
            }

            $row = AudienceList::query()
                ->where('audience_id', $hash)
                ->selectRaw("
                    SUM(CASE WHEN con_email IS NULL OR con_email = '' THEN 1 ELSE 0 END) as missing_email,
                    SUM(CASE WHEN (con_email IS NULL OR con_email = '') AND email_fetch_status IN ('pending','processing') THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN (con_email IS NULL OR con_email = '') AND email_fetch_attempted_at IS NULL AND (email_fetch_status IS NULL OR email_fetch_status NOT IN ('pending','processing')) THEN 1 ELSE 0 END) as fetchable
                ")
                ->first();

            $missingEmail += (int) ($row->missing_email ?? 0);
            $pending += (int) ($row->pending ?? 0);
            $listFetchable = (int) ($row->fetchable ?? 0);
            $fetchable += $listFetchable;

            if ($listFetchable > 0) {
                // Only IDs for one batch wave — never the whole list.
                $ids = app(OutreachLeadReadinessService::class)
                    ->nextAudienceListIdsForEmailFetch($hash, 50);
                if ($ids !== []) {
                    $batches[] = [
                        'list_hash' => $hash,
                        'audience_list_ids' => $ids,
                        'count' => count($ids),
                    ];
                }
            }
        }

        return [
            'missing_email' => $missingEmail,
            'fetchable' => $fetchable,
            'pending' => $pending,
            'can_batch_fetch' => $hasAudienceLists && $fetchable > 0,
            'sn_only_hint' => ! $hasAudienceLists && $missingEmail > 0,
            'batches' => $batches,
        ];
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @return array<string, mixed>
     */
    private function phoneFetchStatsSql(array $leadLists): array
    {
        $missingPhone = 0;
        $fetchable = 0;
        $pending = 0;
        $batches = [];

        foreach ($leadLists as $list) {
            $hash = (string) ($list['list_hash'] ?? '');
            $src = (string) ($list['list_src'] ?? '');
            if ($hash === '' || ! in_array($src, ['aud', 'sn'], true)) {
                continue;
            }

            if ($src === 'aud') {
                $row = AudienceList::query()
                    ->where('audience_id', $hash)
                    ->selectRaw("
                        SUM(CASE WHEN (con_phone IS NULL OR con_phone = '') AND ((con_public_identifier IS NOT NULL AND con_public_identifier != '') OR (con_id IS NOT NULL AND con_id != '')) THEN 1 ELSE 0 END) as missing_phone,
                        SUM(CASE WHEN (con_phone IS NULL OR con_phone = '') AND phone_fetch_status = 'processing' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN (con_phone IS NULL OR con_phone = '') AND phone_fetch_attempted_at IS NULL AND (phone_fetch_status IS NULL OR phone_fetch_status != 'processing') AND ((con_public_identifier IS NOT NULL AND con_public_identifier != '') OR (con_id IS NOT NULL AND con_id != '')) THEN 1 ELSE 0 END) as fetchable
                    ")
                    ->first();
                $missingPhone += (int) ($row->missing_phone ?? 0);
                $pending += (int) ($row->pending ?? 0);
                $listFetchable = (int) ($row->fetchable ?? 0);
                $fetchable += $listFetchable;
                if ($listFetchable > 0) {
                    $ids = AudienceList::query()
                        ->where('audience_id', $hash)
                        ->where(fn ($q) => $q->whereNull('con_phone')->orWhere('con_phone', ''))
                        ->whereNull('phone_fetch_attempted_at')
                        ->orderBy('id')
                        ->limit(50)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                    if ($ids !== []) {
                        $batches[] = [
                            'list_hash' => $hash,
                            'list_src' => 'aud',
                            'record_ids' => $ids,
                            'count' => count($ids),
                        ];
                    }
                }
            } else {
                $row = SnLead::query()
                    ->where('sn_list_id', $hash)
                    ->selectRaw("
                        SUM(CASE WHEN (phone IS NULL OR phone = '') AND ((lid IS NOT NULL AND lid != '') OR (sn_lid IS NOT NULL AND sn_lid != '')) THEN 1 ELSE 0 END) as missing_phone,
                        SUM(CASE WHEN (phone IS NULL OR phone = '') AND phone_fetch_status = 'processing' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN (phone IS NULL OR phone = '') AND phone_fetch_attempted_at IS NULL AND (phone_fetch_status IS NULL OR phone_fetch_status != 'processing') AND ((lid IS NOT NULL AND lid != '') OR (sn_lid IS NOT NULL AND sn_lid != '')) THEN 1 ELSE 0 END) as fetchable
                    ")
                    ->first();
                $missingPhone += (int) ($row->missing_phone ?? 0);
                $pending += (int) ($row->pending ?? 0);
                $listFetchable = (int) ($row->fetchable ?? 0);
                $fetchable += $listFetchable;
                if ($listFetchable > 0) {
                    $ids = SnLead::query()
                        ->where('sn_list_id', $hash)
                        ->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))
                        ->whereNull('phone_fetch_attempted_at')
                        ->orderBy('id')
                        ->limit(50)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                    if ($ids !== []) {
                        $batches[] = [
                            'list_hash' => $hash,
                            'list_src' => 'sn',
                            'record_ids' => $ids,
                            'count' => count($ids),
                        ];
                    }
                }
            }
        }

        return [
            'missing_phone' => $missingPhone,
            'fetchable' => $fetchable,
            'pending' => $pending,
            'can_batch_fetch' => $fetchable > 0,
            'batches' => $batches,
        ];
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function whatsAppVerifyStatsSql(array $leadLists, array $required, ?int $userId): array
    {
        if ($required !== [] && ! in_array('whatsapp', $required, true)) {
            return [
                'with_phone' => 0,
                'verified' => 0,
                'needs_verify' => 0,
                'can_verify' => false,
            ];
        }

        $withPhone = 0;
        $verified = 0;
        $needsVerify = 0;

        foreach ($leadLists as $list) {
            $hash = (string) ($list['list_hash'] ?? '');
            $src = (string) ($list['list_src'] ?? '');
            if ($hash === '') {
                continue;
            }

            if ($src === 'csv' && $userId) {
                $importList = V2OutreachImportList::query()
                    ->where('user_id', $userId)
                    ->where('list_hash', $hash)
                    ->first();
                if (! $importList) {
                    continue;
                }
                $row = V2OutreachImportLead::query()
                    ->where('import_list_id', $importList->id)
                    ->selectRaw("
                        SUM(CASE WHEN phone IS NOT NULL AND phone != '' THEN 1 ELSE 0 END) as with_phone,
                        SUM(CASE WHEN phone IS NOT NULL AND phone != '' AND whatsapp_provider_id IS NOT NULL AND whatsapp_provider_id != '' THEN 1 ELSE 0 END) as verified,
                        SUM(CASE WHEN phone IS NOT NULL AND phone != '' AND (whatsapp_provider_id IS NULL OR whatsapp_provider_id = '') THEN 1 ELSE 0 END) as needs_verify
                    ")
                    ->first();
                $withPhone += (int) ($row->with_phone ?? 0);
                $verified += (int) ($row->verified ?? 0);
                $needsVerify += (int) ($row->needs_verify ?? 0);
            } elseif ($src === 'aud') {
                $row = AudienceList::query()
                    ->where('audience_id', $hash)
                    ->selectRaw("
                        SUM(CASE WHEN con_phone IS NOT NULL AND con_phone != '' THEN 1 ELSE 0 END) as with_phone,
                        SUM(CASE WHEN con_phone IS NOT NULL AND con_phone != '' AND whatsapp_provider_id IS NOT NULL AND whatsapp_provider_id != '' THEN 1 ELSE 0 END) as verified,
                        SUM(CASE WHEN con_phone IS NOT NULL AND con_phone != '' AND (whatsapp_provider_id IS NULL OR whatsapp_provider_id = '') THEN 1 ELSE 0 END) as needs_verify
                    ")
                    ->first();
                $withPhone += (int) ($row->with_phone ?? 0);
                $verified += (int) ($row->verified ?? 0);
                $needsVerify += (int) ($row->needs_verify ?? 0);
            } elseif ($src === 'sn') {
                $row = SnLead::query()
                    ->where('sn_list_id', $hash)
                    ->selectRaw("
                        SUM(CASE WHEN phone IS NOT NULL AND phone != '' THEN 1 ELSE 0 END) as with_phone,
                        SUM(CASE WHEN phone IS NOT NULL AND phone != '' AND whatsapp_provider_id IS NOT NULL AND whatsapp_provider_id != '' THEN 1 ELSE 0 END) as verified,
                        SUM(CASE WHEN phone IS NOT NULL AND phone != '' AND (whatsapp_provider_id IS NULL OR whatsapp_provider_id = '') THEN 1 ELSE 0 END) as needs_verify
                    ")
                    ->first();
                $withPhone += (int) ($row->with_phone ?? 0);
                $verified += (int) ($row->verified ?? 0);
                $needsVerify += (int) ($row->needs_verify ?? 0);
            }
        }

        return [
            'with_phone' => $withPhone,
            'verified' => $verified,
            'needs_verify' => $needsVerify,
            'can_verify' => $needsVerify > 0,
        ];
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function handleResolveStatsSql(array $leadLists, array $required, ?int $userId): array
    {
        $channels = array_values(array_intersect(
            OutreachChannelRegistry::enabledSocialHandleChannels(),
            $required
        ));
        $needsResolve = 0;

        if ($channels === []) {
            return [
                'needs_resolve' => 0,
                'can_resolve' => false,
                'channels' => [],
            ];
        }

        foreach ($leadLists as $list) {
            $hash = (string) ($list['list_hash'] ?? '');
            $src = (string) ($list['list_src'] ?? '');
            if ($hash === '' || $src !== 'csv' || ! $userId) {
                // Handles for aud/sn live mainly on overlays/imports — CSV is the primary source.
                if ($src === 'sn' && $hash !== '') {
                    foreach ($channels as $channel) {
                        $h = "{$channel}_handle";
                        $p = "{$channel}_provider_id";
                        $needsResolve += (int) SnLead::query()
                            ->where('sn_list_id', $hash)
                            ->whereNotNull($h)->where($h, '!=', '')
                            ->where(fn ($q) => $q->whereNull($p)->orWhere($p, ''))
                            ->count();
                    }
                }
                continue;
            }

            $importList = V2OutreachImportList::query()
                ->where('user_id', $userId)
                ->where('list_hash', $hash)
                ->first();
            if (! $importList) {
                continue;
            }

            foreach ($channels as $channel) {
                $h = "{$channel}_handle";
                $p = "{$channel}_provider_id";
                $needsResolve += (int) V2OutreachImportLead::query()
                    ->where('import_list_id', $importList->id)
                    ->whereNotNull($h)->where($h, '!=', '')
                    ->where(fn ($q) => $q->whereNull($p)->orWhere($p, ''))
                    ->count();
            }
        }

        return [
            'needs_resolve' => $needsResolve,
            'can_resolve' => $needsResolve > 0,
            'channels' => $channels,
        ];
    }

    /**
     * @param  array<int, string>  $required
     * @param  array<string, mixed>  $email
     * @param  array<string, mixed>  $phone
     * @param  array<string, mixed>  $whatsapp
     * @param  array<string, mixed>  $handles
     * @return array<string, mixed>
     */
    private function contactPrepFromParts(array $required, array $email, array $phone, array $whatsapp, array $handles): array
    {
        $emailRemaining = in_array('email', $required, true) ? (int) ($email['fetchable'] ?? 0) : 0;
        $phoneRemaining = (in_array('email', $required, true) || in_array('whatsapp', $required, true))
            ? (int) ($phone['fetchable'] ?? 0)
            : 0;
        $whatsappRemaining = in_array('whatsapp', $required, true) ? (int) ($whatsapp['needs_verify'] ?? 0) : 0;
        $remaining = $emailRemaining + $phoneRemaining + $whatsappRemaining + (int) ($handles['needs_resolve'] ?? 0);
        $batchSize = app(\App\V2\Services\EmailEnrichmentLimiter::class)->batchSize();

        return [
            'batch_size' => $batchSize,
            'remaining_total' => $remaining,
            'can_prepare' => $remaining > 0
                || (in_array('email', $required, true) && ((int) ($email['pending'] ?? 0) > 0))
                || ($phoneRemaining > 0 && ((int) ($phone['pending'] ?? 0) > 0)),
            'pending_async' => (in_array('email', $required, true) ? (int) ($email['pending'] ?? 0) : 0)
                + ($phoneRemaining > 0 ? (int) ($phone['pending'] ?? 0) : 0),
        ];
    }

    /**
     * @return array{field_label: string, help: string}
     */
    private function channelMeta(string $channel): array
    {
        return match ($channel) {
            'linkedin' => [
                'field_label' => 'LinkedIn profile ID',
                'help' => 'Included automatically from your LinkedIn lists.',
            ],
            'email' => [
                'field_label' => 'Email address',
                'help' => 'From the list, fetched from LinkedIn profile, or imported via CSV.',
            ],
            'whatsapp' => [
                'field_label' => 'WhatsApp (verified)',
                'help' => 'Needs a phone number, then Verify WhatsApp to confirm the number is on WhatsApp.',
            ],
            'instagram' => [
                'field_label' => 'Instagram (resolved ID)',
                'help' => 'Add usernames in your import, then Resolve handles to look up messaging IDs.',
            ],
            'telegram' => [
                'field_label' => 'Telegram (resolved ID or phone)',
                'help' => 'Import a phone or @username, then Resolve handles if needed.',
            ],
            'twitter' => [
                'field_label' => 'X (resolved ID)',
                'help' => 'Add usernames in your import, then Resolve handles to look up messaging IDs.',
            ],
            default => [
                'field_label' => 'Contact info',
                'help' => '',
            ],
        };
    }

    /**
     * @param  array<int, string>  $required
     * @param  array<string, array<string, mixed>>  $channelStats
     * @return array<int, string>
     */
    private function buildWarnings(array $required, array $channelStats, int $total): array
    {
        $warnings = [];
        if ($total === 0) {
            return ['Select at least one lead list with profiles.'];
        }

        foreach ($required as $channel) {
            $stats = $channelStats[$channel] ?? null;
            if ($stats === null || ($stats['missing'] ?? 0) === 0) {
                continue;
            }
            if (in_array($channel, OutreachChannelRegistry::enabledMessagingChannels(), true)) {
                $warnings[] = sprintf(
                    '%d of %d leads are missing %s — %s steps will be skipped for them.',
                    $stats['missing'],
                    $total,
                    $stats['field_label'],
                    $stats['label'],
                );
            } elseif ($channel === 'email') {
                $warnings[] = sprintf(
                    '%d of %d leads have no email — use Fetch emails or import a CSV.',
                    $stats['missing'],
                    $total,
                );
            } elseif ($channel === 'linkedin') {
                $warnings[] = sprintf(
                    '%d leads are missing a LinkedIn profile ID and cannot receive LinkedIn steps.',
                    $stats['missing'],
                );
            }
        }

        return $warnings;
    }
}
