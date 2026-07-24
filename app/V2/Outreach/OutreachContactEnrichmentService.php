<?php

namespace App\V2\Outreach;

use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\User;
use App\Models\V2LeadContactOverlay;
use App\Models\V2OutreachImportLead;
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
    public function whatsAppVerifyCandidates(array $leadLists, ?int $userId = null): array
    {
        $rows = $this->readiness->collectLeadRows($leadLists, $userId);
        $candidates = [];

        foreach ($rows as $row) {
            if (($row['whatsapp_provider_id'] ?? '') !== '') {
                continue;
            }
            if (($row['whatsapp_verify_status'] ?? '') === 'unreachable') {
                continue;
            }
            $phone = trim((string) ($row['phone'] ?? ''));
            if ($phone === '') {
                continue;
            }

            $candidates[] = [
                'src' => (string) $row['src'],
                'list_hash' => (string) $row['list_hash'],
                'record_id' => (int) $row['record_id'],
                'phone' => $phone,
                'linkedin_key' => (string) ($row['linkedin_key'] ?? ''),
            ];
        }

        return $candidates;
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @return array<int, array{channel: string, src: string, list_hash: string, record_id: int, identifier: string, linkedin_key: string}>
     */
    public function handleResolveCandidates(array $leadLists, ?int $userId = null): array
    {
        $rows = $this->readiness->collectLeadRows($leadLists, $userId);
        $candidates = [];

        foreach ($rows as $row) {
            foreach (['instagram', 'telegram', 'twitter'] as $channel) {
                $providerField = "{$channel}_provider_id";
                $handleField = "{$channel}_handle";
                if (($row[$providerField] ?? '') !== '') {
                    continue;
                }
                $handle = trim((string) ($row[$handleField] ?? ''));
                if ($handle === '') {
                    continue;
                }

                $candidates[] = [
                    'channel' => $channel,
                    'src' => (string) $row['src'],
                    'list_hash' => (string) $row['list_hash'],
                    'record_id' => (int) $row['record_id'],
                    'identifier' => $handle,
                    'linkedin_key' => (string) ($row['linkedin_key'] ?? ''),
                ];
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

        foreach (array_slice($candidates, 0, $limit) as $candidate) {
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

        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $providerId = $this->contactService->resolvePlatformIdentifier(
                $user,
                $candidate['channel'],
                $candidate['identifier'],
            );

            if ($providerId) {
                $this->persistPlatformId($candidate, $providerId, $user->id);
                $resolved++;
            } else {
                $failed++;
            }
        }

        return ['resolved' => $resolved, 'failed' => $failed, 'remaining' => max(0, count($candidates) - $limit)];
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
