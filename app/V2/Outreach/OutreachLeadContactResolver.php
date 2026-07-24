<?php

namespace App\V2\Outreach;

use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\V2LeadContactOverlay;

class OutreachLeadContactResolver
{
    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>|null  $overlay
     * @return array<string, mixed>
     */
    public function mergeRow(array $base, ?array $overlay): array
    {
        if ($overlay === null) {
            return $base;
        }

        foreach (['email', 'phone', 'whatsapp_provider_id', 'instagram_handle', 'instagram_provider_id', 'telegram_handle', 'telegram_provider_id', 'twitter_handle', 'twitter_provider_id'] as $field) {
            $value = trim((string) ($overlay[$field] ?? ''));
            if ($value !== '' && empty($base[$field])) {
                $base[$field] = $value;
            }
        }

        return $base;
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @return array<string, array<string, mixed>>
     */
    public function overlaysForLists(int $userId, array $leadLists): array
    {
        $keys = collect($this->collectLinkedinKeys($leadLists))->filter()->unique()->values()->all();
        if ($keys === []) {
            return [];
        }

        return V2LeadContactOverlay::query()
            ->where('user_id', $userId)
            ->whereIn('linkedin_key', $keys)
            ->get()
            ->keyBy(fn (V2LeadContactOverlay $row) => strtolower($row->linkedin_key))
            ->map(fn (V2LeadContactOverlay $row) => $row->only([
                'email', 'phone', 'whatsapp_provider_id',
                'instagram_handle', 'instagram_provider_id',
                'telegram_handle', 'telegram_provider_id',
                'twitter_handle', 'twitter_provider_id',
            ]))
            ->all();
    }

    /**
     * @param  array<int, array{list_hash: string, list_src: string}>  $leadLists
     * @return array<int, string>
     */
    public function collectLinkedinKeys(array $leadLists): array
    {
        $keys = [];

        foreach ($leadLists as $list) {
            $hash = (string) ($list['list_hash'] ?? '');
            $src = (string) ($list['list_src'] ?? '');
            if ($hash === '' || ! in_array($src, ['aud', 'sn'], true)) {
                continue;
            }

            if ($src === 'aud') {
                foreach (AudienceList::where('audience_id', $hash)->get(['con_public_identifier', 'con_id']) as $row) {
                    $key = $this->normalizeLinkedinKey($row->con_public_identifier ?: $row->con_id);
                    if ($key !== '') {
                        $keys[] = $key;
                    }
                }
            } else {
                foreach (SnLead::where('sn_list_id', $hash)->get(['lid', 'sn_lid']) as $row) {
                    $key = $this->normalizeLinkedinKey($row->lid ?: $row->sn_lid);
                    if ($key !== '') {
                        $keys[] = $key;
                    }
                }
            }
        }

        return $keys;
    }

    public function normalizeLinkedinKey(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/linkedin\.com\/in\/([^\/\?]+)/i', $value, $m)) {
            return strtolower($m[1]);
        }

        return strtolower($value);
    }

    public function messagingRecipientId(array $row, string $channel): ?string
    {
        return match ($channel) {
            'whatsapp' => trim((string) ($row['whatsapp_provider_id'] ?? '')) ?: null,
            'instagram' => trim((string) ($row['instagram_provider_id'] ?? '')) ?: null,
            'telegram' => trim((string) ($row['telegram_provider_id'] ?? ''))
                ?: trim((string) ($row['telegram_handle'] ?? ''))
                ?: trim((string) ($row['phone'] ?? '')) ?: null,
            'twitter' => trim((string) ($row['twitter_provider_id'] ?? '')) ?: null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function isReadyForChannel(array $row, string $channel): bool
    {
        return match ($channel) {
            'linkedin' => (bool) ($row['has_linkedin_id'] ?? false),
            'email' => ($row['email'] ?? '') !== '',
            'whatsapp' => ($row['whatsapp_provider_id'] ?? '') !== '',
            'instagram' => ($row['instagram_provider_id'] ?? '') !== '',
            'telegram' => ($row['telegram_provider_id'] ?? '') !== ''
                || ($row['phone'] ?? '') !== '',
            'twitter' => ($row['twitter_provider_id'] ?? '') !== '',
            default => false,
        };
    }

    /**
     * Phone digits formatted for WhatsApp — used only by the verify step, not for send/readiness.
     */
    public function whatsappIdFromPhone(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        if (str_contains($phone, '@')) {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 8 ? $digits.'@s.whatsapp.net' : null;
    }
    /**
     * Build contact fields for V2OutreachLead from merged row.
     *
     * @param  array<string, mixed>  $row
     * @return array{email: ?string, phone: ?string, meta: array<string, mixed>}
     */
    public function toLeadAttributes(array $row): array
    {
        $meta = array_filter([
            'whatsapp_provider_id' => $row['whatsapp_provider_id'] ?? null,
            'instagram_handle' => $row['instagram_handle'] ?? null,
            'instagram_provider_id' => $row['instagram_provider_id'] ?? null,
            'telegram_handle' => $row['telegram_handle'] ?? null,
            'telegram_provider_id' => $row['telegram_provider_id'] ?? null,
            'twitter_handle' => $row['twitter_handle'] ?? null,
            'twitter_provider_id' => $row['twitter_provider_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return [
            'email' => ($row['email'] ?? '') !== '' ? $row['email'] : null,
            'phone' => ($row['phone'] ?? '') !== '' ? $row['phone'] : null,
            'meta' => $meta,
        ];
    }
}
