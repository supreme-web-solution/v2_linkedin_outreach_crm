<?php

namespace App\V2\Web;

use App\Models\AudienceList;
use App\V2\Outreach\OutreachLeadContactResolver;
use App\V2\Outreach\OutreachLeadReadinessService;

class AudienceListLeadPresenter
{
    /**
     * @param  array<string, array<string, mixed>>  $overlays
     * @return array<string, mixed>
     */
    public function transformRow(AudienceList $row, array $overlays = []): array
    {
        $name = trim(($row->con_first_name ?? '').' '.($row->con_last_name ?? ''));
        if ($name === '') {
            $name = $this->displayNameFromPublicIdentifier((string) ($row->con_public_identifier ?? ''));
        }
        $resolver = app(OutreachLeadContactResolver::class);
        $profileId = trim((string) ($row->con_public_identifier ?: $row->con_id ?: ''));
        $linkedinKey = $resolver->normalizeLinkedinKey($profileId);
        $contacts = $this->mergedContacts($resolver->mergeRow([
            'email' => trim((string) ($row->con_email ?? '')),
            'phone' => trim((string) ($row->con_phone ?? '')),
            'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
            'whatsapp_verify_status' => '',
            'instagram_handle' => '',
            'instagram_provider_id' => '',
            'telegram_handle' => '',
            'telegram_provider_id' => '',
            'twitter_handle' => '',
            'twitter_provider_id' => '',
            'email_fetch_status' => (string) ($row->email_fetch_status ?? ''),
            'phone_fetch_status' => (string) ($row->phone_fetch_status ?? ''),
            'phone_fetch_attempted' => ! empty($row->phone_fetch_attempted_at),
        ], $overlays[strtolower($linkedinKey)] ?? null));
        $company = $this->companyPayload($row->con_company_name, $row->con_company_url);

        return [
            'id' => $row->id,
            'name' => $name !== '' ? $name : 'Unknown',
            'email' => $contacts['email'],
            'headline' => $row->con_job_title,
            'location' => $row->con_location,
            'profileid' => $row->con_id,
            'public_identifier' => $row->con_public_identifier,
            'profile_url' => $row->con_public_identifier
                ? 'https://www.linkedin.com/in/'.$row->con_public_identifier
                : $row->con_profile_url,
            'network_distance' => $row->con_distance,
            'email_fetch_status' => $row->email_fetch_status,
            'email_fetch_attempted_at' => optional($row->email_fetch_attempted_at)->toIso8601String(),
            'contacts' => $contacts,
            'company_name' => $company['company_name'],
            'company_domain' => $company['company_domain'],
            'company_logo_url' => $company['company_logo_url'],
        ];
    }

    private function displayNameFromPublicIdentifier(string $publicId): string
    {
        $slug = trim($publicId);
        if ($slug === '' || str_starts_with($slug, 'ACo') || str_starts_with($slug, 'ADo')) {
            return '';
        }

        $slug = (string) preg_replace('/-[a-z0-9]{6,}$/i', '', $slug);
        $parts = preg_split('/[-_]+/', $slug) ?: [];
        $words = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || ctype_digit($part)) {
                continue;
            }
            $words[] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
        }

        return implode(' ', $words);
    }

    /**
     * @return array<string, int>
     */
    public function emailFilterCounts(string $audienceId): array
    {
        $base = fn () => AudienceList::where('audience_id', $audienceId);

        return [
            'all' => $base()->count(),
            'with_email' => $base()->whereNotNull('con_email')->where('con_email', '!=', '')->count(),
            'without_email' => $base()
                ->where(fn ($q) => $q->whereNull('con_email')->orWhere('con_email', '=', ''))
                ->where(fn ($q) => $q->where('email_fetch_status', 'completed')->orWhereNotNull('email_fetch_attempted_at'))
                ->count(),
            'not_fetched' => $base()->whereNull('email_fetch_status')->whereNull('email_fetch_attempted_at')->count(),
            'pending' => $base()->whereIn('email_fetch_status', ['pending', 'processing'])->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contactStatsForList(string $audienceId, int $userId, int $queuePending): array
    {
        $rows = app(OutreachLeadReadinessService::class)->collectLeadRows(
            [['list_hash' => $audienceId, 'list_src' => 'aud']],
            $userId,
        );

        $total = count($rows);
        $emailsFound = 0;
        $phonesFound = 0;
        $emailPending = 0;
        $phonePending = 0;
        $emailSearched = 0;
        $phoneSearched = 0;

        foreach ($rows as $row) {
            if (($row['email'] ?? '') !== '') {
                $emailsFound++;
            }
            if (($row['phone'] ?? '') !== '') {
                $phonesFound++;
            }
            if (in_array($row['email_fetch_status'] ?? '', ['pending', 'processing'], true)) {
                $emailPending++;
            }
            if (in_array($row['phone_fetch_status'] ?? '', ['pending', 'processing'], true)) {
                $phonePending++;
            }
            if ($row['email_fetch_attempted'] ?? false) {
                $emailSearched++;
            }
            if ($row['phone_fetch_attempted'] ?? false) {
                $phoneSearched++;
            }
        }

        $emailPending = max($emailPending, $queuePending);
        $processed = min($total, $emailSearched + $emailPending);
        $fetchable = app(OutreachLeadReadinessService::class)->countEmailFetchableRows($rows, 'aud');

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

    /**
     * @param  array<string, string>  $merged
     * @return array<string, string|null|bool>
     */
    private function mergedContacts(array $merged): array
    {
        return [
            'email' => ($merged['email'] ?? '') !== '' ? $merged['email'] : null,
            'phone' => ($merged['phone'] ?? '') !== '' ? $merged['phone'] : null,
            'whatsapp_provider_id' => ($merged['whatsapp_provider_id'] ?? '') !== '' ? $merged['whatsapp_provider_id'] : null,
            'whatsapp_verify_status' => ($merged['whatsapp_verify_status'] ?? '') !== '' ? $merged['whatsapp_verify_status'] : null,
            'instagram_handle' => ($merged['instagram_handle'] ?? '') !== '' ? $merged['instagram_handle'] : null,
            'instagram_provider_id' => ($merged['instagram_provider_id'] ?? '') !== '' ? $merged['instagram_provider_id'] : null,
            'telegram_handle' => ($merged['telegram_handle'] ?? '') !== '' ? $merged['telegram_handle'] : null,
            'telegram_provider_id' => ($merged['telegram_provider_id'] ?? '') !== '' ? $merged['telegram_provider_id'] : null,
            'twitter_handle' => ($merged['twitter_handle'] ?? '') !== '' ? $merged['twitter_handle'] : null,
            'twitter_provider_id' => ($merged['twitter_provider_id'] ?? '') !== '' ? $merged['twitter_provider_id'] : null,
            'email_fetch_status' => ($merged['email_fetch_status'] ?? '') !== '' ? $merged['email_fetch_status'] : null,
            'phone_fetch_attempted' => (bool) ($merged['phone_fetch_attempted'] ?? false),
            'phone_fetch_status' => ($merged['phone_fetch_attempted'] ?? false) && ($merged['phone_fetch_status'] ?? '') !== ''
                ? $merged['phone_fetch_status']
                : null,
        ];
    }

    /**
     * @return array{company_name: ?string, company_domain: ?string, company_logo_url: ?string}
     */
    private function companyPayload(?string $name, ?string $website, ?string $logo = null): array
    {
        $domain = $this->domainFromUrl($website ?? '');
        if ($domain === '' && $name !== null && str_contains($name, '.')) {
            $domain = $this->domainFromUrl($name);
        }
        $logoUrl = $logo ?: ($domain !== '' ? 'https://www.google.com/s2/favicons?domain='.$domain.'&sz=64' : null);

        return [
            'company_name' => $name ?: null,
            'company_domain' => $domain ?: null,
            'company_logo_url' => $logoUrl,
        ];
    }

    private function domainFromUrl(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://') && str_contains($value, '.')) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : '';
    }
}
