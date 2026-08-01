<?php

namespace App\V2\Outreach;

use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\V2OutreachImportList;

class OutreachLeadReadinessService
{
    /**
     * @return array<int, string>
     */
    private function enabledMessagingChannels(): array
    {
        return OutreachChannelRegistry::enabledMessagingChannels();
    }

    public function __construct(
        private readonly OutreachLeadContactResolver $contactResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $nodeModel
     * @return array<int, string>
     */
    public function requiredChannels(array $nodeModel): array
    {
        return OutreachChannelRegistry::contactRequiredChannelsForNodes($nodeModel);
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @param  array<int, array<string, mixed>>  $nodeModel
     * @return array<string, mixed>
     */
    public function previewForLists(array $leadLists, array $nodeModel, ?int $userId = null): array
    {
        $required = $this->requiredChannels($nodeModel);
        $rows = $this->collectLeadRows($leadLists, $userId);

        $total = count($rows);
        $channelStats = [];

        foreach ($required as $channel) {
            $channelStats[$channel] = $this->statsForChannel($channel, $rows);
        }

        $fullyReady = 0;
        foreach ($rows as $row) {
            if ($this->isReadyForAllChannels($row, $required)) {
                $fullyReady++;
            }
        }

        $willSkipAny = $total - $fullyReady;
        $warnings = $this->buildWarnings($required, $channelStats, $total);
        $audienceListsSelected = collect($leadLists)->contains(fn ($l) => ($l['list_src'] ?? '') === 'aud');

        return [
            'total_leads' => $total,
            'fully_ready' => $fullyReady,
            'will_skip_any' => $willSkipAny,
            'required_channels' => $required,
            'channels' => $channelStats,
            'email_fetch' => $this->emailFetchStats($rows, $audienceListsSelected),
            'phone_fetch' => $this->phoneFetchStats($rows, $leadLists),
            'whatsapp_verify' => $this->whatsAppVerifyStats($rows, $required),
            'handle_resolve' => $this->handleResolveStats($rows, $required),
            'contact_prep' => $this->contactPrepStats($rows, $required, $audienceListsSelected, $leadLists, $userId),
            'warnings' => $warnings,
            'can_launch' => $total > 0,
            'should_confirm_launch' => $willSkipAny > 0 && $total > 0,
        ];
    }

    /**
     * Enrichment stats for an imported CSV list (WhatsApp verify + social handle resolve).
     *
     * @return array<string, mixed>
     */
    public function enrichmentStatsForImportList(string $listHash, int $userId): array
    {
        $leadLists = [['list_hash' => $listHash, 'list_src' => 'csv']];
        $rows = $this->collectLeadRows($leadLists, $userId);
        $required = array_values(array_unique(array_merge(
            ['whatsapp'],
            OutreachChannelRegistry::enabledSocialHandleChannels(),
        )));

        $whatsapp = $this->whatsAppVerifyStats($rows, $required);
        $handles = $this->handleResolveStats($rows, $required);

        $fetchable = $this->countImportLeadsNeedingEnrichment($rows);

        return [
            'total' => count($rows),
            'whatsapp_verify' => $whatsapp,
            'handle_resolve' => $handles,
            'can_enrich' => $fetchable > 0,
            'fetchable' => $fetchable,
        ];
    }

    /**
     * @return array<int, int>
     */
    public function nextImportLeadIdsForEnrichment(int $importListId, int $limit = 25): array
    {
        $query = \App\Models\V2OutreachImportLead::query()
            ->where('import_list_id', $importListId)
            ->where(function ($q) {
                $q->where(function ($phone) {
                    $phone->whereNotNull('phone')
                        ->where('phone', '!=', '')
                        ->where(function ($wa) {
                            $wa->whereNull('whatsapp_provider_id')->orWhere('whatsapp_provider_id', '');
                        });
                });

                foreach (OutreachChannelRegistry::enabledSocialHandleChannels() as $channel) {
                    $handleCol = "{$channel}_handle";
                    $providerCol = "{$channel}_provider_id";
                    $q->orWhere(function ($handle) use ($handleCol, $providerCol) {
                        $handle->whereNotNull($handleCol)
                            ->where($handleCol, '!=', '')
                            ->where(function ($provider) use ($providerCol) {
                                $provider->whereNull($providerCol)->orWhere($providerCol, '');
                            });
                    });
                }
            })
            ->orderBy('id')
            ->limit($limit);

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function countImportLeadsNeedingEnrichment(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if ($this->importLeadRowNeedsEnrichment($row)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function importLeadRowNeedsEnrichment(array $row): bool
    {
        $phone = trim((string) ($row['phone'] ?? ''));
        if ($phone !== '' && trim((string) ($row['whatsapp_provider_id'] ?? '')) === '') {
            return true;
        }

        foreach (OutreachChannelRegistry::enabledSocialHandleChannels() as $channel) {
            $handle = trim((string) ($row["{$channel}_handle"] ?? ''));
            if ($handle !== '' && trim((string) ($row["{$channel}_provider_id"] ?? '')) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    public function nextAudienceListIdsForEmailFetch(string $audienceId, int $limit = 25): array
    {
        return AudienceList::query()
            ->where('audience_id', $audienceId)
            ->where(function ($q) {
                $q->whereNull('con_email')->orWhere('con_email', '');
            })
            ->whereNull('email_fetch_attempted_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public function nextSnLeadIdsForEmailFetch(string $listHash, int $limit = 25): array
    {
        return SnLead::query()
            ->where('sn_list_id', $listHash)
            ->where(function ($q) {
                $q->whereNull('email')->orWhere('email', '');
            })
            ->where(function ($q) {
                $q->whereNull('email_fetch_status')
                    ->orWhereNotIn('email_fetch_status', ['pending', 'processing', 'completed']);
            })
            ->where(function ($q) {
                $q->whereNotNull('email_fetch_status')
                    ->where('email_fetch_status', '!=', '')
                    ->orWhereNull('phone_fetch_attempted_at');
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function countEmailFetchableRows(array $rows, string $src): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if ($this->emailFetchRowNeedsEnrichment($row, $src)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function emailFetchRowNeedsEnrichment(array $row, string $src): bool
    {
        if (($row['email'] ?? '') !== '') {
            return false;
        }

        if (in_array($row['email_fetch_status'] ?? '', ['pending', 'processing'], true)) {
            return false;
        }

        if ($row['email_fetch_attempted'] ?? false) {
            return false;
        }

        if ($src === 'sn') {
            if (($row['email_fetch_status'] ?? '') === 'completed') {
                return false;
            }

            if (($row['email_fetch_status'] ?? '') === '' && ($row['phone_fetch_attempted'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @return array<int, array<string, mixed>>
     */
    public function collectLeadRows(array $leadLists, ?int $userId = null): array
    {
        $overlays = $userId ? $this->contactResolver->overlaysForLists($userId, $leadLists) : [];
        $byKey = [];

        foreach ($leadLists as $list) {
            $hash = (string) ($list['list_hash'] ?? '');
            $src = (string) ($list['list_src'] ?? '');
            if ($hash === '' || ! in_array($src, ['aud', 'sn', 'csv'], true)) {
                continue;
            }

            if ($src === 'csv') {
                if (! $userId) {
                    continue;
                }

                $importList = V2OutreachImportList::query()
                    ->where('user_id', $userId)
                    ->where('list_hash', $hash)
                    ->first();

                if (! $importList) {
                    continue;
                }

                foreach ($importList->leads()->get() as $row) {
                    $profileId = trim((string) ($row->linkedin_id ?? ''));
                    $dedupe = "csv:{$hash}:{$row->id}";
                    $byKey[$dedupe] = [
                        'src' => 'csv',
                        'list_hash' => $hash,
                        'record_id' => $row->id,
                        'linkedin_id' => $profileId,
                        'linkedin_key' => $profileId,
                        'email' => trim((string) ($row->email ?? '')),
                        'phone' => trim((string) ($row->phone ?? '')),
                        'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
                        'whatsapp_verify_status' => trim((string) ($row->whatsapp_verify_status ?? '')),
                        'instagram_handle' => trim((string) ($row->instagram_handle ?? '')),
                        'instagram_provider_id' => trim((string) ($row->instagram_provider_id ?? '')),
                        'telegram_handle' => trim((string) ($row->telegram_handle ?? '')),
                        'telegram_provider_id' => trim((string) ($row->telegram_provider_id ?? '')),
                        'twitter_handle' => trim((string) ($row->twitter_handle ?? '')),
                        'twitter_provider_id' => trim((string) ($row->twitter_provider_id ?? '')),
                        'email_fetch_attempted' => true,
                        'email_fetch_status' => '',
                        'phone_fetch_attempted' => true,
                        'phone_fetch_status' => '',
                        'has_linkedin_id' => $profileId !== '',
                    ];
                }

                continue;
            }

            if ($src === 'aud') {
                foreach (AudienceList::where('audience_id', $hash)->get() as $row) {
                    $profileId = trim((string) ($row->con_public_identifier ?: $row->con_id ?: ''));
                    $linkedinKey = $this->contactResolver->normalizeLinkedinKey($profileId);
                    $dedupe = $profileId !== '' ? "aud:{$profileId}" : "aud-row:{$row->id}";
                    $base = [
                        'src' => 'aud',
                        'list_hash' => $hash,
                        'record_id' => $row->id,
                        'linkedin_id' => $profileId,
                        'linkedin_key' => $linkedinKey,
                        'email' => trim((string) ($row->con_email ?? '')),
                        'phone' => trim((string) ($row->con_phone ?? '')),
                        'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
                        'whatsapp_verify_status' => trim((string) ($row->whatsapp_verify_status ?? '')),
                        'instagram_handle' => '',
                        'instagram_provider_id' => '',
                        'telegram_handle' => '',
                        'telegram_provider_id' => '',
                        'twitter_handle' => '',
                        'twitter_provider_id' => '',
                        'email_fetch_attempted' => ! empty($row->email_fetch_attempted_at),
                        'email_fetch_status' => (string) ($row->email_fetch_status ?? ''),
                        'phone_fetch_attempted' => ! empty($row->phone_fetch_attempted_at),
                        'phone_fetch_status' => (string) ($row->phone_fetch_status ?? ''),
                        'has_linkedin_id' => $profileId !== '',
                    ];
                    $byKey[$dedupe] = $this->contactResolver->mergeRow($base, $overlays[$linkedinKey] ?? null);
                }
            } else {
                foreach (SnLead::where('sn_list_id', $hash)->get() as $row) {
                    $profileId = trim((string) ($row->lid ?? ''));
                    $linkedinKey = $this->contactResolver->normalizeLinkedinKey($profileId ?: $row->sn_lid);
                    $dedupe = $profileId !== '' ? "sn:{$profileId}" : "sn-row:{$row->id}";
                    $base = [
                        'src' => 'sn',
                        'list_hash' => $hash,
                        'record_id' => $row->id,
                        'linkedin_id' => $profileId,
                        'linkedin_key' => $linkedinKey,
                        'email' => trim((string) ($row->email ?? '')),
                        'phone' => trim((string) ($row->phone ?? '')),
                        'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
                        'whatsapp_verify_status' => trim((string) ($row->whatsapp_verify_status ?? '')),
                        'instagram_handle' => trim((string) ($row->instagram_handle ?? '')),
                        'instagram_provider_id' => trim((string) ($row->instagram_provider_id ?? '')),
                        'telegram_handle' => trim((string) ($row->telegram_handle ?? '')),
                        'telegram_provider_id' => trim((string) ($row->telegram_provider_id ?? '')),
                        'twitter_handle' => trim((string) ($row->twitter_handle ?? '')),
                        'twitter_provider_id' => trim((string) ($row->twitter_provider_id ?? '')),
                        'email_fetch_attempted' => false,
                        'email_fetch_status' => '',
                        'phone_fetch_attempted' => ! empty($row->phone_fetch_attempted_at),
                        'phone_fetch_status' => (string) ($row->phone_fetch_status ?? ''),
                        'has_linkedin_id' => $profileId !== '',
                    ];
                    $byKey[$dedupe] = $this->contactResolver->mergeRow($base, $overlays[$linkedinKey] ?? null);
                }
            }
        }

        return array_values($byKey);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $required
     */
    private function isReadyForAllChannels(array $row, array $required): bool
    {
        foreach ($required as $channel) {
            if (! $this->contactResolver->isReadyForChannel($row, $channel)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function statsForChannel(string $channel, array $rows): array
    {
        $ready = 0;
        foreach ($rows as $row) {
            if ($this->contactResolver->isReadyForChannel($row, $channel)) {
                $ready++;
            }
        }

        $total = count($rows);
        $missing = $total - $ready;
        $meta = $this->channelMeta($channel);

        return [
            'channel' => $channel,
            'label' => OutreachChannelRegistry::channelLabel($channel),
            'ready' => $ready,
            'missing' => $missing,
            'total' => $total,
            'percent' => $total > 0 ? (int) round(($ready / $total) * 100) : 0,
            'field_label' => $meta['field_label'],
            'help' => $meta['help'],
            'is_messaging' => in_array($channel, $this->enabledMessagingChannels(), true),
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
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function emailFetchStats(array $rows, bool $hasAudienceLists): array
    {
        $missingEmail = 0;
        $fetchable = 0;
        $pending = 0;
        $byAudience = [];

        foreach ($rows as $row) {
            if (($row['email'] ?? '') !== '') {
                continue;
            }
            $missingEmail++;
            if (($row['src'] ?? '') !== 'aud' || ! ($row['has_linkedin_id'] ?? false)) {
                continue;
            }

            $hash = (string) ($row['list_hash'] ?? '');
            if ($hash === '') {
                continue;
            }

            if (! isset($byAudience[$hash])) {
                $byAudience[$hash] = ['list_hash' => $hash, 'fetchable_ids' => [], 'pending' => 0];
            }

            if (($row['email_fetch_status'] ?? '') === 'pending' || ($row['email_fetch_status'] ?? '') === 'processing') {
                $pending++;
                $byAudience[$hash]['pending']++;
            } elseif (! ($row['email_fetch_attempted'] ?? false)) {
                $fetchable++;
                $byAudience[$hash]['fetchable_ids'][] = (int) $row['record_id'];
            }
        }

        $batches = [];
        foreach ($byAudience as $aud) {
            if ($aud['fetchable_ids'] !== []) {
                $batches[] = [
                    'list_hash' => $aud['list_hash'],
                    'audience_list_ids' => $aud['fetchable_ids'],
                    'count' => count($aud['fetchable_ids']),
                ];
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
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @return array<string, mixed>
     */
    private function phoneFetchStats(array $rows, array $leadLists): array
    {
        $missingPhone = 0;
        $fetchable = 0;
        $pending = 0;
        $audBatches = [];
        $snBatches = [];

        foreach ($rows as $row) {
            if (($row['phone'] ?? '') !== '' || ! ($row['has_linkedin_id'] ?? false)) {
                continue;
            }
            $missingPhone++;

            if (($row['phone_fetch_status'] ?? '') === 'processing') {
                $pending++;
                continue;
            }
            if ($row['phone_fetch_attempted'] ?? false) {
                continue;
            }

            $fetchable++;
            $hash = (string) ($row['list_hash'] ?? '');
            if (($row['src'] ?? '') === 'aud') {
                $audBatches[$hash]['list_hash'] = $hash;
                $audBatches[$hash]['audience_list_ids'][] = (int) $row['record_id'];
            } else {
                $snBatches[$hash]['list_hash'] = $hash;
                $snBatches[$hash]['sn_lead_ids'][] = (int) $row['record_id'];
            }
        }

        $batches = [];
        foreach ($audBatches as $batch) {
            $batches[] = [
                'list_hash' => $batch['list_hash'],
                'list_src' => 'aud',
                'record_ids' => $batch['audience_list_ids'],
                'count' => count($batch['audience_list_ids']),
            ];
        }
        foreach ($snBatches as $batch) {
            $batches[] = [
                'list_hash' => $batch['list_hash'],
                'list_src' => 'sn',
                'record_ids' => $batch['sn_lead_ids'],
                'count' => count($batch['sn_lead_ids']),
            ];
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
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function whatsAppVerifyStats(array $rows, array $required = []): array
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

        foreach ($rows as $row) {
            if (($row['phone'] ?? '') === '') {
                continue;
            }
            $withPhone++;
            if (($row['whatsapp_provider_id'] ?? '') !== '') {
                $verified++;
            } else {
                $needsVerify++;
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
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function handleResolveStats(array $rows, array $required): array
    {
        $needsResolve = 0;
        $channels = array_intersect(OutreachChannelRegistry::enabledSocialHandleChannels(), $required);

        foreach ($rows as $row) {
            foreach ($channels as $channel) {
                $handle = trim((string) ($row["{$channel}_handle"] ?? ''));
                $providerId = trim((string) ($row["{$channel}_provider_id"] ?? ''));
                if ($handle !== '' && $providerId === '') {
                    $needsResolve++;
                }
            }
        }

        return [
            'needs_resolve' => $needsResolve,
            'can_resolve' => $needsResolve > 0,
            'channels' => array_values($channels),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $required
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @return array<string, mixed>
     */
    private function contactPrepStats(array $rows, array $required, bool $audienceListsSelected, array $leadLists, ?int $userId): array
    {
        $email = $this->emailFetchStats($rows, $audienceListsSelected);
        $phone = $this->phoneFetchStats($rows, $leadLists);
        $whatsapp = $this->whatsAppVerifyStats($rows, $required);
        $handles = $this->handleResolveStats($rows, $required);

        $emailRemaining = in_array('email', $required, true) ? ($email['fetchable'] ?? 0) : 0;
        $phoneRemaining = (in_array('email', $required, true) || in_array('whatsapp', $required, true))
            ? ($phone['fetchable'] ?? 0)
            : 0;
        $whatsappRemaining = in_array('whatsapp', $required, true) ? ($whatsapp['needs_verify'] ?? 0) : 0;

        $remaining = $emailRemaining
            + $phoneRemaining
            + $whatsappRemaining
            + ($handles['needs_resolve'] ?? 0);

        $batchSize = max(1, min(50, (int) config('services.unipile_pacing.contact_prep_batch_size', 25)));

        return [
            'batch_size' => $batchSize,
            'remaining_total' => $remaining,
            'can_prepare' => $remaining > 0
                || (in_array('email', $required, true) && (($email['pending'] ?? 0) > 0))
                || ($phoneRemaining > 0 && ($phone['pending'] ?? 0) > 0),
            'pending_async' => (in_array('email', $required, true) ? ($email['pending'] ?? 0) : 0)
                + ($phoneRemaining > 0 ? ($phone['pending'] ?? 0) : 0),
        ];
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
            $warnings[] = 'Select at least one lead list with profiles.';

            return $warnings;
        }

        foreach ($required as $channel) {
            $stats = $channelStats[$channel] ?? null;
            if ($stats === null || ($stats['missing'] ?? 0) === 0) {
                continue;
            }

            if (in_array($channel, $this->enabledMessagingChannels(), true)) {
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
            } elseif ($channel === 'linkedin' && ($stats['missing'] ?? 0) > 0) {
                $warnings[] = sprintf(
                    '%d leads are missing a LinkedIn profile ID and cannot receive LinkedIn steps.',
                    $stats['missing'],
                );
            }
        }

        return $warnings;
    }

    /**
     * @param  array<int, int>  $audienceListIds
     * @return array<int, array<int>>
     */
    public function chunkAudienceListIds(array $audienceListIds, int $size = 50): array
    {
        return array_chunk(array_values(array_unique($audienceListIds)), $size);
    }
}
