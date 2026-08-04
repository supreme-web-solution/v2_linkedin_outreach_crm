<?php

namespace App\V2\Outreach;

use App\Jobs\FetchAudienceEmailBatchJob;
use App\Jobs\FetchAudiencePhoneBatchJob;
use App\Jobs\FetchSnPhoneBatchJob;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\User;
use App\Models\V2LeadContactOverlay;
use App\Models\V2OutreachImportLead;
use App\V2\Services\EmailEnrichmentLimiter;
use App\V2\Services\UnipileProfileContactService;

class OutreachContactEnrichmentService
{
    public function __construct(
        private readonly OutreachLeadContactResolver $resolver,
        private readonly OutreachLeadReadinessService $readiness,
        private readonly UnipileProfileContactService $contactService,
    ) {}

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @return array<int, array{list_hash: string, list_src: string, record_ids: array<int>}>
     */
    public function phoneFetchBatches(array $leadLists, ?int $userId = null): array
    {
        $preview = $this->readiness->previewForLists($leadLists, [], $userId);

        return $preview['phone_fetch']['batches'] ?? [];
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @return array<int, array{src: string, list_hash: string, record_id: int, phone: string}>
     */
    public function whatsAppVerifyCandidates(array $leadLists, ?int $userId = null, int $limit = 25): array
    {
        $candidates = [];
        $remaining = max(1, $limit);

        foreach ($leadLists as $list) {
            if ($remaining <= 0) {
                break;
            }
            $hash = (string) ($list['list_hash'] ?? '');
            $src = (string) ($list['list_src'] ?? '');
            if ($hash === '' || ! in_array($src, ['aud', 'sn', 'csv'], true)) {
                continue;
            }

            if ($src === 'csv') {
                if (! $userId) {
                    continue;
                }
                $importList = \App\Models\V2OutreachImportList::query()
                    ->where('user_id', $userId)
                    ->where('list_hash', $hash)
                    ->first();
                if (! $importList) {
                    continue;
                }
                $rows = V2OutreachImportLead::query()
                    ->where('import_list_id', $importList->id)
                    ->whereNotNull('phone')->where('phone', '!=', '')
                    ->where(fn ($q) => $q->whereNull('whatsapp_provider_id')->orWhere('whatsapp_provider_id', ''))
                    ->where(fn ($q) => $q->whereNull('whatsapp_verify_status')->orWhere('whatsapp_verify_status', '!=', 'unreachable'))
                    ->orderBy('id')
                    ->limit($remaining)
                    ->get(['id', 'phone', 'linkedin_id']);
                foreach ($rows as $row) {
                    $candidates[] = [
                        'src' => 'csv',
                        'list_hash' => $hash,
                        'record_id' => (int) $row->id,
                        'phone' => trim((string) $row->phone),
                        'linkedin_key' => $this->resolver->normalizeLinkedinKey($row->linkedin_id),
                    ];
                    $remaining--;
                }
            } elseif ($src === 'aud') {
                $rows = AudienceList::query()
                    ->where('audience_id', $hash)
                    ->whereNotNull('con_phone')->where('con_phone', '!=', '')
                    ->where(fn ($q) => $q->whereNull('whatsapp_provider_id')->orWhere('whatsapp_provider_id', ''))
                    ->orderBy('id')
                    ->limit($remaining)
                    ->get(['id', 'con_phone', 'con_public_identifier', 'con_id']);
                foreach ($rows as $row) {
                    $profileId = trim((string) ($row->con_public_identifier ?: $row->con_id ?: ''));
                    $candidates[] = [
                        'src' => 'aud',
                        'list_hash' => $hash,
                        'record_id' => (int) $row->id,
                        'phone' => trim((string) $row->con_phone),
                        'linkedin_key' => $this->resolver->normalizeLinkedinKey($profileId),
                    ];
                    $remaining--;
                }
            } else {
                $rows = SnLead::query()
                    ->where('sn_list_id', $hash)
                    ->whereNotNull('phone')->where('phone', '!=', '')
                    ->where(fn ($q) => $q->whereNull('whatsapp_provider_id')->orWhere('whatsapp_provider_id', ''))
                    ->orderBy('id')
                    ->limit($remaining)
                    ->get(['id', 'phone', 'lid', 'sn_lid']);
                foreach ($rows as $row) {
                    $candidates[] = [
                        'src' => 'sn',
                        'list_hash' => $hash,
                        'record_id' => (int) $row->id,
                        'phone' => trim((string) $row->phone),
                        'linkedin_key' => $this->resolver->normalizeLinkedinKey($row->lid ?: $row->sn_lid),
                    ];
                    $remaining--;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @param  array<int, string>|null  $requiredChannels
     * @return array<int, array{channel: string, src: string, list_hash: string, record_id: int, identifier: string, linkedin_key: string}>
     */
    public function handleResolveCandidates(array $leadLists, ?int $userId = null, ?array $requiredChannels = null, int $limit = 25): array
    {
        $candidates = [];
        $remaining = max(1, $limit);
        $channels = $requiredChannels !== null
            ? array_values(array_intersect(OutreachChannelRegistry::enabledSocialHandleChannels(), $requiredChannels))
            : OutreachChannelRegistry::enabledSocialHandleChannels();

        if ($channels === []) {
            return [];
        }

        foreach ($leadLists as $list) {
            if ($remaining <= 0) {
                break;
            }
            $hash = (string) ($list['list_hash'] ?? '');
            $src = (string) ($list['list_src'] ?? '');
            if ($hash === '' || ! in_array($src, ['csv', 'sn'], true)) {
                continue;
            }

            if ($src === 'csv') {
                if (! $userId) {
                    continue;
                }
                $importList = \App\Models\V2OutreachImportList::query()
                    ->where('user_id', $userId)
                    ->where('list_hash', $hash)
                    ->first();
                if (! $importList) {
                    continue;
                }

                $query = V2OutreachImportLead::query()->where('import_list_id', $importList->id)->where(function ($q) use ($channels) {
                    foreach ($channels as $channel) {
                        $h = "{$channel}_handle";
                        $p = "{$channel}_provider_id";
                        $q->orWhere(function ($inner) use ($h, $p) {
                            $inner->whereNotNull($h)->where($h, '!=', '')
                                ->where(fn ($pQ) => $pQ->whereNull($p)->orWhere($p, ''));
                        });
                    }
                })->orderBy('id')->limit($remaining);

                foreach ($query->get() as $row) {
                    foreach ($channels as $channel) {
                        if ($remaining <= 0) {
                            break 2;
                        }
                        $handle = trim((string) ($row->{"{$channel}_handle"} ?? ''));
                        $provider = trim((string) ($row->{"{$channel}_provider_id"} ?? ''));
                        if ($handle === '' || $provider !== '') {
                            continue;
                        }
                        $candidates[] = [
                            'channel' => $channel,
                            'src' => 'csv',
                            'list_hash' => $hash,
                            'record_id' => (int) $row->id,
                            'identifier' => $handle,
                            'linkedin_key' => $this->resolver->normalizeLinkedinKey($row->linkedin_id),
                        ];
                        $remaining--;
                    }
                }
            } else {
                $query = SnLead::query()->where('sn_list_id', $hash)->where(function ($q) use ($channels) {
                    foreach ($channels as $channel) {
                        $h = "{$channel}_handle";
                        $p = "{$channel}_provider_id";
                        $q->orWhere(function ($inner) use ($h, $p) {
                            $inner->whereNotNull($h)->where($h, '!=', '')
                                ->where(fn ($pQ) => $pQ->whereNull($p)->orWhere($p, ''));
                        });
                    }
                })->orderBy('id')->limit($remaining);

                foreach ($query->get() as $row) {
                    foreach ($channels as $channel) {
                        if ($remaining <= 0) {
                            break 2;
                        }
                        $handle = trim((string) ($row->{"{$channel}_handle"} ?? ''));
                        $provider = trim((string) ($row->{"{$channel}_provider_id"} ?? ''));
                        if ($handle === '' || $provider !== '') {
                            continue;
                        }
                        $candidates[] = [
                            'channel' => $channel,
                            'src' => 'sn',
                            'list_hash' => $hash,
                            'record_id' => (int) $row->id,
                            'identifier' => $handle,
                            'linkedin_key' => $this->resolver->normalizeLinkedinKey($row->lid ?: $row->sn_lid),
                        ];
                        $remaining--;
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @return array{matched: int, skipped: int, updated: int}
     */
    public function importCsv(User $user, string $csvContent, array $leadLists): array
    {
        $allowedKeys = collect($this->resolver->collectLinkedinKeys($leadLists))
            ->map(fn ($k) => $this->resolver->normalizeLinkedinKey($k))
            ->filter()
            ->flip();

        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];
        if ($lines === []) {
            return ['matched' => 0, 'skipped' => 0, 'updated' => 0];
        }

        $headers = str_getcsv(array_shift($lines) ?: '');
        $headerMap = $this->mapCsvHeaders($headers);

        $matched = 0;
        $skipped = 0;
        $updated = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cols = str_getcsv($line);
            $row = $this->parseCsvRow($cols, $headerMap);
            $linkedinKey = $this->resolver->normalizeLinkedinKey(
                $row['linkedin_url'] ?? $row['linkedin_id'] ?? $row['linkedin'] ?? ''
            );

            if ($linkedinKey === '' || ! isset($allowedKeys[$linkedinKey])) {
                $skipped++;

                continue;
            }

            $matched++;
            $payload = array_filter([
                'email' => $row['email'] ?? null,
                'phone' => isset($row['phone']) ? app(\App\V2\Integrations\Unipile\UnipileProvider::class)->normalizePhone($row['phone']) : null,
                'instagram_handle' => $this->cleanHandle($row['instagram'] ?? $row['instagram_handle'] ?? null),
                'telegram_handle' => $this->cleanHandle($row['telegram'] ?? $row['telegram_handle'] ?? null),
                'twitter_handle' => $this->cleanHandle($row['twitter'] ?? $row['twitter_handle'] ?? $row['x'] ?? null),
            ], fn ($v) => $v !== null && $v !== '');

            if ($payload === []) {
                continue;
            }

            V2LeadContactOverlay::updateOrCreate(
                ['user_id' => $user->id, 'linkedin_key' => $linkedinKey],
                $payload,
            );

            $this->applyOverlayToSourceRows($user->id, $linkedinKey, $payload);
            $updated++;
        }

        return compact('matched', 'skipped', 'updated');
    }

    public function verifyWhatsAppBatch(User $user, array $candidates, int $limit = 25): array
    {
        $verified = 0;
        $failed = 0;

        foreach (array_slice($candidates, 0, $limit) as $index => $candidate) {
            if ($index > 0) {
                $this->paceMessagingLookup();
            }

            try {
                $result = $this->contactService->verifyWhatsAppForUser($user, $candidate['phone']);
            } catch (\Throwable) {
                $failed++;

                continue;
            }

            if ($result['verified'] && $result['provider_id']) {
                $this->persistWhatsApp($candidate, $result['provider_id'], $user->id, $candidate['linkedin_key'] ?? '');
                $verified++;
            } else {
                $this->markWhatsAppUnreachable($candidate);
                $failed++;
            }
        }

        return ['verified' => $verified, 'failed' => $failed, 'remaining' => max(0, count($candidates) - $limit)];
    }

    public function resolveHandlesBatch(User $user, array $candidates, int $limit = 25): array
    {
        $resolved = 0;
        $failed = 0;
        $skipped = 0;

        foreach (array_slice($candidates, 0, $limit) as $index => $candidate) {
            if ($index > 0) {
                $this->paceMessagingLookup();
            }

            try {
                $providerId = $this->contactService->resolvePlatformIdentifier(
                    $user,
                    $candidate['channel'],
                    $candidate['identifier'],
                );
            } catch (\Throwable) {
                $skipped++;

                continue;
            }

            if ($providerId) {
                $this->persistPlatformId($candidate, $providerId, $user->id);
                $resolved++;
            } else {
                $failed++;
            }
        }

        return [
            'resolved' => $resolved,
            'failed' => $failed,
            'skipped' => $skipped,
            'remaining' => max(0, count($candidates) - $limit),
        ];
    }

    /**
     * One paced batch: extract from LinkedIn (when applicable), verify WhatsApp phones, resolve social handles.
     *
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @param  array<int, array<string, mixed>>  $nodeModel
     * @return array<string, mixed>
     */
    public function prepareContactsBatch(User $user, array $leadLists, array $nodeModel, ?int $batchSize = null): array
    {
        $batchSize = max(1, min(50, $batchSize ?? app(\App\V2\Services\EmailEnrichmentLimiter::class)->batchSize()));
        $preview = $this->readiness->previewForLists($leadLists, $nodeModel, $user->id);
        $required = $this->readiness->requiredChannels($nodeModel);

        $emailsQueued = in_array('email', $required, true)
            ? $this->queueEmailBatch($user, $leadLists, $nodeModel, $batchSize)
            : 0;
        $phonesQueued = (in_array('email', $required, true) || in_array('whatsapp', $required, true))
            ? $this->queuePhoneBatch($leadLists, $user->id, $batchSize)
            : 0;

        $whatsapp = ['verified' => 0, 'failed' => 0, 'remaining' => 0];
        if ($preview['whatsapp_verify']['can_verify'] ?? false) {
            $waCandidates = $this->whatsAppVerifyCandidates($leadLists, $user->id);
            $whatsapp = $this->verifyWhatsAppBatch($user, $waCandidates, $batchSize);
        }

        $handles = ['resolved' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0];
        if ($preview['handle_resolve']['can_resolve'] ?? false) {
            $handleCandidates = $this->handleResolveCandidates($leadLists, $user->id, $required);
            $handles = $this->resolveHandlesBatch($user, $handleCandidates, $batchSize);
        }

        $remainingAfter = $this->readiness->previewForLists($leadLists, $nodeModel, $user->id);
        $remainingTotal = (int) ($remainingAfter['contact_prep']['remaining_total'] ?? 0);

        $parts = [];
        if ($emailsQueued > 0) {
            $parts[] = "{$emailsQueued} email lookup".($emailsQueued === 1 ? '' : 's').' queued';
        }
        if ($phonesQueued > 0) {
            $parts[] = "{$phonesQueued} phone lookup".($phonesQueued === 1 ? '' : 's').' queued';
        }
        if ($whatsapp['verified'] > 0 || $whatsapp['failed'] > 0) {
            $parts[] = "{$whatsapp['verified']} WhatsApp verified, {$whatsapp['failed']} failed";
        }
        if ($handles['resolved'] > 0 || $handles['failed'] > 0) {
            $parts[] = "{$handles['resolved']} handle(s) resolved, {$handles['failed']} failed";
        }
        if (($handles['skipped'] ?? 0) > 0) {
            $parts[] = "{$handles['skipped']} skipped (connect that channel under Integrations)";
        }

        $message = $parts !== []
            ? 'Batch complete: '.implode(' · ', $parts).'.'
            : 'Nothing left to prepare in this batch.';

        if ($remainingTotal > 0) {
            $message .= " About {$remainingTotal} item(s) remaining — run another batch.";
        }

        return [
            'batch_size' => $batchSize,
            'emails_queued' => $emailsQueued,
            'phones_queued' => $phonesQueued,
            'whatsapp' => $whatsapp,
            'handles' => $handles,
            'remaining_total' => $remainingTotal,
            'message' => $message,
        ];
    }

    /**
     * Verify messaging channels for a single lead row (used by Leads page enrich).
     *
     * @param  array<string, mixed>  $row
     * @return array{whatsapp_verified: bool, handles_resolved: int, handles_failed: int, handles_skipped: int}
     */
    public function verifyContactsForRow(User $user, array $row): array
    {
        $result = [
            'whatsapp_verified' => false,
            'handles_resolved' => 0,
            'handles_failed' => 0,
            'handles_skipped' => 0,
        ];

        $phone = trim((string) ($row['phone'] ?? ''));
        if ($phone !== '' && trim((string) ($row['whatsapp_provider_id'] ?? '')) === '') {
            try {
                $candidate = [
                    'src' => (string) ($row['src'] ?? ''),
                    'list_hash' => (string) ($row['list_hash'] ?? ''),
                    'record_id' => (int) ($row['record_id'] ?? 0),
                    'phone' => $phone,
                    'linkedin_key' => (string) ($row['linkedin_key'] ?? ''),
                ];
                $wa = $this->contactService->verifyWhatsAppForUser($user, $phone);
                if ($wa['verified'] && $wa['provider_id']) {
                    $this->persistWhatsApp($candidate, $wa['provider_id'], $user->id, $candidate['linkedin_key']);
                    $result['whatsapp_verified'] = true;
                } else {
                    $this->markWhatsAppUnreachable($candidate);
                }
            } catch (\Throwable) {
                // Channel not connected or lookup failed — skip silently
            }
        }

        foreach (OutreachChannelRegistry::enabledSocialHandleChannels() as $channel) {
            $handle = trim((string) ($row["{$channel}_handle"] ?? ''));
            if ($handle === '' || trim((string) ($row["{$channel}_provider_id"] ?? '')) !== '') {
                continue;
            }

            $candidate = [
                'channel' => $channel,
                'src' => (string) ($row['src'] ?? ''),
                'list_hash' => (string) ($row['list_hash'] ?? ''),
                'record_id' => (int) ($row['record_id'] ?? 0),
                'identifier' => $handle,
                'linkedin_key' => (string) ($row['linkedin_key'] ?? ''),
            ];

            try {
                $providerId = $this->contactService->resolvePlatformIdentifier($user, $channel, $handle);
            } catch (\Throwable) {
                $result['handles_skipped']++;

                continue;
            }

            if ($providerId) {
                $this->persistPlatformId($candidate, $providerId, $user->id);
                $result['handles_resolved']++;
            } else {
                $result['handles_failed']++;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @param  array<int, array<string, mixed>>  $nodeModel
     */
    private function queueEmailBatch(User $user, array $leadLists, array $nodeModel, int $batchSize): int
    {
        $limiter = app(EmailEnrichmentLimiter::class);
        $preview = $this->readiness->previewForLists($leadLists, $nodeModel, $user->id);

        if (! ($preview['email_fetch']['can_batch_fetch'] ?? false)) {
            return 0;
        }

        $batches = $preview['email_fetch']['batches'] ?? [];
        $allIds = [];
        foreach ($batches as $batch) {
            foreach ($batch['audience_list_ids'] ?? [] as $id) {
                $allIds[] = (int) $id;
            }
        }

        if ($allIds === []) {
            return 0;
        }

        $capacity = $limiter->queueCapacity($user, min(count($allIds), $batchSize));
        if (! ($capacity['allowed'] ?? false)) {
            return 0;
        }

        $allowedIds = array_slice($allIds, 0, min($batchSize, $capacity['max_queue_now'] ?? 0));
        if ($allowedIds === []) {
            return 0;
        }

        $allowedSet = array_flip($allowedIds);
        $queued = 0;

        foreach ($batches as $batch) {
            $ids = array_values(array_filter(
                $batch['audience_list_ids'] ?? [],
                fn ($id) => isset($allowedSet[(int) $id]),
            ));

            if ($ids === []) {
                continue;
            }

            FetchAudienceEmailBatchJob::dispatch($ids, $user->id);
            $queued += count($ids);
        }

        return $queued;
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     */
    private function queuePhoneBatch(array $leadLists, int $userId, int $batchSize): int
    {
        $batches = $this->phoneFetchBatches($leadLists, $userId);
        $queued = 0;

        foreach ($batches as $batch) {
            if ($queued >= $batchSize) {
                break;
            }

            $remaining = $batchSize - $queued;
            $ids = array_slice($batch['record_ids'] ?? [], 0, $remaining);
            if ($ids === []) {
                continue;
            }

            if (($batch['list_src'] ?? 'aud') === 'aud') {
                FetchAudiencePhoneBatchJob::dispatch($ids, $userId);
            } else {
                FetchSnPhoneBatchJob::dispatch($ids, $userId, $batch['list_hash']);
            }

            $queued += count($ids);
        }

        return $queued;
    }

    private function paceMessagingLookup(): void
    {
        $min = (int) config('services.unipile_pacing.profile_lookup_delay_min_ms', 1000);
        $max = (int) config('services.unipile_pacing.profile_lookup_delay_max_ms', 3000);
        if ($max < $min) {
            $max = $min;
        }

        usleep(random_int($min, $max) * 1000);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyOverlayToSourceRows(int $userId, string $linkedinKey, array $payload): void
    {
        foreach (AudienceList::where('con_public_identifier', $linkedinKey)->orWhere('con_id', $linkedinKey)->get() as $row) {
            $updates = [];
            if (! empty($payload['email']) && empty($row->con_email)) {
                $updates['con_email'] = $payload['email'];
            }
            if (! empty($payload['phone']) && empty($row->con_phone)) {
                $updates['con_phone'] = $payload['phone'];
            }
            if ($updates !== []) {
                $row->update($updates);
            }
        }

        foreach (SnLead::where('lid', $linkedinKey)->orWhere('sn_lid', $linkedinKey)->get() as $row) {
            $updates = [];
            if (! empty($payload['email']) && empty($row->email)) {
                $updates['email'] = $payload['email'];
            }
            if (! empty($payload['phone']) && empty($row->phone)) {
                $updates['phone'] = $payload['phone'];
            }
            foreach (['instagram_handle', 'telegram_handle', 'twitter_handle'] as $field) {
                if (! empty($payload[$field]) && empty($row->{$field})) {
                    $updates[$field] = $payload[$field];
                }
            }
            if ($updates !== []) {
                $row->update($updates);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function persistWhatsApp(array $candidate, string $providerId, int $userId, string $linkedinKey): void
    {
        if ($linkedinKey !== '') {
            V2LeadContactOverlay::updateOrCreate(
                ['user_id' => $userId, 'linkedin_key' => $linkedinKey],
                ['whatsapp_provider_id' => $providerId],
            );
        }

        if (($candidate['src'] ?? '') === 'aud') {
            AudienceList::where('id', $candidate['record_id'])->update(['whatsapp_provider_id' => $providerId]);
        } elseif (($candidate['src'] ?? '') === 'csv') {
            V2OutreachImportLead::where('id', $candidate['record_id'])->update([
                'whatsapp_provider_id' => $providerId,
                'whatsapp_verify_status' => 'verified',
            ]);
        } else {
            SnLead::where('id', $candidate['record_id'])->update(['whatsapp_provider_id' => $providerId]);
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function markWhatsAppUnreachable(array $candidate): void
    {
        if (($candidate['src'] ?? '') !== 'csv') {
            return;
        }

        V2OutreachImportLead::where('id', $candidate['record_id'])->update([
            'whatsapp_verify_status' => 'unreachable',
        ]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function persistPlatformId(array $candidate, string $providerId, int $userId): void
    {
        $channel = (string) ($candidate['channel'] ?? '');
        $field = "{$channel}_provider_id";
        $linkedinKey = (string) ($candidate['linkedin_key'] ?? '');

        if ($linkedinKey !== '') {
            V2LeadContactOverlay::updateOrCreate(
                ['user_id' => $userId, 'linkedin_key' => $linkedinKey],
                [$field => $providerId],
            );
        }

        if (($candidate['src'] ?? '') === 'sn') {
            SnLead::where('id', $candidate['record_id'])->update([$field => $providerId]);
        } elseif (($candidate['src'] ?? '') === 'csv') {
            V2OutreachImportLead::where('id', $candidate['record_id'])->update([$field => $providerId]);
        }
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int>
     */
    private function mapCsvHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $key = strtolower(trim(preg_replace('/[^a-z0-9_]+/i', '_', $header) ?? '', '_'));
            $map[$key] = $index;
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $cols
     * @param  array<string, int>  $headerMap
     * @return array<string, string>
     */
    private function parseCsvRow(array $cols, array $headerMap): array
    {
        $row = [];
        foreach ($headerMap as $key => $index) {
            $row[$key] = trim((string) ($cols[$index] ?? ''));
        }

        return $row;
    }

    private function cleanHandle(?string $value): ?string
    {
        $value = ltrim(trim((string) $value), '@');

        return $value !== '' ? $value : null;
    }
}
