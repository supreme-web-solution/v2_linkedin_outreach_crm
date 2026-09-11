<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use App\V2\Ai\Support\PlanLeadList;
use App\V2\Outreach\OutreachImportListService;
use App\V2\Services\LeadListService;
use Illuminate\Support\Str;
use RuntimeException;

class MissingProspectAudienceException extends RuntimeException
{
    /**
     * @param  list<string>  $nextSteps
     */
    public function __construct(
        string $message,
        public readonly array $nextSteps = [],
    ) {
        parent::__construct($message);
    }
}

class ProspectAudienceResolverService
{
    public function __construct(
        private readonly LeadListService $leadLists,
        private readonly LinkedInAudienceBuilderService $linkedInAudience,
        private readonly InstagramAudienceBuilderService $instagramAudience,
        private readonly OutreachImportListService $importLists,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return array{
     *     list_hash: string,
     *     list_src: string,
     *     list_name: string,
     *     total_leads: int,
     *     match_score: int
     * }|null
     */
    public function resolve(User $user, array $plan, bool $strict = true): ?array
    {
        $hash = trim((string) ($plan['list_hash'] ?? $plan['lead_list_id'] ?? ''));
        $src = trim((string) ($plan['list_src'] ?? $plan['lead_list_src'] ?? ''));

        if ($hash !== '' && in_array($src, ['aud', 'sn', 'csv'], true)) {
            if ($src === 'csv') {
                $importList = V2OutreachImportList::query()
                    ->where('user_id', $user->id)
                    ->where('list_hash', $hash)
                    ->first();

                if ($importList) {
                    return $this->normalizeListRef(
                        (string) $importList->list_hash,
                        'csv',
                        (string) ($plan['list_name'] ?? $importList->name),
                        max(1, (int) $importList->lead_count),
                    );
                }
            }

            $lists = $this->leadLists->listsForUser($user->id);
            $match = $lists->first(
                fn (array $list) => (string) $list['list_id'] === $hash && (string) $list['src'] === $src
            );

            return [
                'list_hash' => $hash,
                'list_src' => $src,
                'list_name' => (string) ($plan['list_name'] ?? $match['list_name'] ?? 'Selected list'),
                'total_leads' => (int) ($match['total_leads'] ?? 0),
                'match_score' => 100,
            ];
        }

        return $this->findBestMatch($user, $plan, $strict);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function enrichPlanWithAudience(User $user, array $plan): array
    {
        $plan = $this->hydrateContactIdentifiersFromText($plan);
        $oneShot = $this->isOneShotPlan($plan);
        $profileUrl = trim((string) ($plan['profile_url'] ?? $plan['linkedin_url'] ?? ''));

        // One-person profile URL always wins over a stale multi-lead list_hash.
        if ($profileUrl !== '' && preg_match('#linkedin\.com/in/[\w%-]+#i', $profileUrl)) {
            $orgId = (int) ($user->current_organization_id ?? 0);
            $built = $orgId > 0
                ? $this->linkedInAudience->tryBuildFromPlan($user, $orgId, array_merge($plan, [
                    'one_shot' => true,
                    'target_count' => 1,
                    'profile_url' => $profileUrl,
                ]))
                : null;

            if ($built !== null) {
                $plan = PlanLeadList::merge(
                    $plan,
                    $built['list_hash'],
                    $built['list_src'],
                    $built['list_name'],
                );
                $plan['audience_status'] = 'attached';
                $plan['audience_leads'] = 1;
                $plan['audience_note'] = $built['list_name'].' (1 profile from LinkedIn URL)';
                if ($oneShot) {
                    $plan['one_shot'] = true;
                    $plan['one_time'] = true;
                }
                $plan['target_count'] = 1;
                if (! empty($built['profile_detail'])) {
                    $plan['profile_detail'] = $built['profile_detail'];
                }
                if (! empty($built['sample_profiles'])) {
                    $plan['sample_profiles'] = $built['sample_profiles'];
                }
                if (! empty($built['first_degree_only'])) {
                    $plan['first_degree_only'] = true;
                }

                return $plan;
            }
        }

        $socialAttached = $this->tryAttachOnePersonContactList($user, $plan);
        if ($socialAttached !== null) {
            return $this->applyAttachedAudience($plan, $socialAttached);
        }

        $explicitHash = trim((string) ($plan['list_hash'] ?? $plan['lead_list_id'] ?? ''));
        $explicitSrc = trim((string) ($plan['list_src'] ?? $plan['lead_list_src'] ?? ''));
        $preferFresh = ! empty($plan['prefer_fresh_audience']);

        // One-shot must not silently reuse a large unrelated list.
        if ($oneShot && $explicitHash !== '' && $explicitSrc !== '') {
            $resolvedExplicit = $this->resolve($user, $plan, strict: true);
            $leads = (int) ($resolvedExplicit['total_leads'] ?? 0);
            if ($leads > 1) {
                unset($plan['list_hash'], $plan['list_src'], $plan['list_name'], $plan['lead_list_id'], $plan['lead_list_src']);
                $explicitHash = '';
                $explicitSrc = '';
                $preferFresh = true;
                $plan['prefer_fresh_audience'] = true;
                $plan['target_count'] = 1;
            }
        }

        // Fresh N requested and no explicit list_hash → LinkedIn fetch+save, never silent engagers reuse.
        if ($preferFresh && $explicitHash === '') {
            if (! $this->shouldAutoSearchLinkedIn($plan)) {
                $plan['audience_status'] = 'missing';
                $plan['audience_next_steps'] = $this->nextSteps($plan);

                return $plan;
            }

            $orgId = (int) ($user->current_organization_id ?? 0);
            $built = $orgId > 0
                ? $this->linkedInAudience->tryBuildFromPlan($user, $orgId, $plan)
                : null;

            if ($built !== null) {
                $plan = PlanLeadList::merge(
                    $plan,
                    $built['list_hash'],
                    $built['list_src'],
                    $built['list_name'],
                );
                $plan['audience_status'] = 'attached';
                $plan['audience_leads'] = $built['total_leads'];
                $plan['audience_note'] = $built['list_name'].' ('.$built['total_leads'].' leads, fetched from LinkedIn and saved)';
                $plan['prefer_fresh_audience'] = true;
                if (! empty($built['sample_profiles'])) {
                    $plan['sample_profiles'] = $built['sample_profiles'];
                }
                if (! empty($built['profile_detail'])) {
                    $plan['profile_detail'] = $built['profile_detail'];
                }

                return $plan;
            }

            $plan['audience_status'] = 'missing';
            $plan['audience_next_steps'] = $this->nextSteps($plan);

            return $plan;
        }

        $match = $this->resolve($user, $plan, strict: true);

        // Weak fuzzy matches are not good enough when a target size was requested.
        if (
            $match !== null
            && $explicitHash === ''
            && isset($plan['target_count'])
            && (int) ($match['match_score'] ?? 0) < DiscoverProspectsService::STRONG_MATCH_SCORE
        ) {
            $match = null;
        }

        if ($match === null) {
            $attached = $this->tryAttachOnePersonContactList($user, $plan);
            if ($attached !== null) {
                return $this->applyAttachedAudience($plan, $attached);
            }

            if (! $this->shouldAutoSearchLinkedIn($plan)) {
                $plan['audience_status'] = 'missing';
                $plan['audience_next_steps'] = $this->nextSteps($plan);

                return $plan;
            }

            $orgId = (int) ($user->current_organization_id ?? 0);
            $built = $orgId > 0
                ? $this->linkedInAudience->tryBuildFromPlan($user, $orgId, $plan)
                : null;

            if ($built !== null) {
                $plan = PlanLeadList::merge(
                    $plan,
                    $built['list_hash'],
                    $built['list_src'],
                    $built['list_name'],
                );
                $plan['audience_status'] = 'attached';
                $plan['audience_leads'] = $built['total_leads'];
                $plan['audience_note'] = $built['list_name'].' ('.$built['total_leads'].' leads, auto-sourced from LinkedIn)';

                return $plan;
            }

            $plan['audience_status'] = 'missing';
            $plan['audience_next_steps'] = $this->nextSteps($plan);

            return $plan;
        }

        // Explicit list_hash from tool/user always wins.
        if ($explicitHash !== '' && $explicitSrc !== '') {
            $match = $this->resolve($user, $plan, strict: true) ?? $match;
        }

        $plan = PlanLeadList::merge(
            $plan,
            $match['list_hash'],
            $match['list_src'],
            $match['list_name'],
        );
        $plan['audience_status'] = 'ready';
        $plan['audience_leads'] = $match['total_leads'];
        $plan['audience_note'] = "{$match['list_name']} ({$match['total_leads']} leads)";

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    public function nextSteps(array $plan): array
    {
        $channels = Str::lower((string) ($plan['preferred_channels'] ?? $plan['channels'] ?? ''));
        $handle = $this->extractInstagramHandle($plan);
        $telegram = $this->extractTelegramHandle($plan);
        $email = $this->extractEmail($plan);
        $phone = $this->extractPhone($plan);

        if ($handle !== '' && str_contains($channels, 'instagram')) {
            return [
                'Save the Instagram profile first (save_contacts or Leads → Add from profile URL).',
                'Then draft_campaign_plan with channels=Instagram, one_shot=true, list_hash from the saved list.',
                'Or paste the instagram.com/… URL in your message — Launch will attach that person automatically.',
            ];
        }

        if ($email !== '' && str_contains($channels, 'email') && ! str_contains($channels, 'linkedin')) {
            return [
                'Save the email with save_contacts, then draft_campaign_plan with channels=Email + one_shot.',
                'Launch attaches the saved contact list automatically when the email is in the plan.',
            ];
        }

        if ($phone !== '' && (str_contains($channels, 'whatsapp') || str_contains($channels, 'telegram'))) {
            return [
                'Save the phone or @handle with save_contacts first.',
                'Then draft_campaign_plan with the matching channel and one_shot=true.',
            ];
        }
        if ($telegram !== '' && str_contains($channels, 'telegram')) {
            return [
                'Save the Telegram @handle with save_contacts first.',
                'Then draft_campaign_plan with channels=Telegram + one_shot=true.',
            ];
        }

        $goal = trim((string) ($plan['goal'] ?? $plan['audience'] ?? $plan['icp_notes'] ?? 'your ICP'));

        return [
            'Soci will auto-search LinkedIn for '.$goal.' when your account is connected',
            'Or import / build a lead list in SociFusion → Leads',
            'Or share a competitor LinkedIn company URL to harvest engagers',
            'Launch runs once a list is attached — Autopilot auto-launches when ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{
     *     list_hash: string,
     *     list_src: string,
     *     list_name: string,
     *     total_leads: int,
     *     match_score: int
     * }|null
     */
    private function findBestMatch(User $user, array $plan, bool $strict): ?array
    {
        $needle = $this->searchNeedle($plan);
        $tokens = array_values(array_filter(
            preg_split('/\s+/', $needle) ?: [],
            fn (string $token) => strlen($token) >= 3,
        ));

        $lists = $this->leadLists->listsForUser($user->id)
            ->filter(fn (array $list) => (int) ($list['total_leads'] ?? 0) > 0)
            ->values();

        if ($lists->isEmpty()) {
            return null;
        }

        $scored = $lists->map(function (array $list) use ($needle, $tokens) {
            $name = Str::lower((string) $list['list_name']);
            $score = 0;

            if ($needle !== '' && str_contains($name, $needle)) {
                $score += 10;
            }

            foreach ($tokens as $token) {
                if (str_contains($name, $token)) {
                    $score += 2;
                }
            }

            if ($score > 0) {
                $score += min(3, (int) floor(((int) $list['total_leads']) / 200));
            }

            return ['list' => $list, 'score' => $score];
        })->sortByDesc('score')->values();

        $best = $scored->first();
        // Size-only / weak token hits must not win in strict mode.
        if (! $best || ($best['score'] ?? 0) < DiscoverProspectsService::STRONG_MATCH_SCORE) {
            if ($strict) {
                return null;
            }

            if (! $best || ($best['score'] ?? 0) <= 0) {
                $largest = $lists->sortByDesc('total_leads')->first();
                if (! $largest) {
                    return null;
                }

                return $this->normalizeListRef(
                    (string) $largest['list_id'],
                    (string) $largest['src'],
                    (string) $largest['list_name'],
                    (int) $largest['total_leads'],
                    0,
                );
            }
        }

        $list = $best['list'];

        return $this->normalizeListRef(
            (string) $list['list_id'],
            (string) $list['src'],
            (string) $list['list_name'],
            (int) $list['total_leads'],
            (int) $best['score'],
        );
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function isOneShotPlan(array $plan): bool
    {
        return app(PlanSequenceNodeBuilder::class)->isOneShotIntent($plan);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function hydrateContactIdentifiersFromText(array $plan): array
    {
        $existingLinkedIn = trim((string) ($plan['profile_url'] ?? $plan['linkedin_url'] ?? ''));
        if ($existingLinkedIn !== '' && preg_match('#linkedin\.com/in/[\w%-]+#i', $existingLinkedIn)) {
            $plan['profile_url'] = $existingLinkedIn;
            $plan['linkedin_url'] = $existingLinkedIn;

            return $plan;
        }

        $blob = $this->planTextBlob($plan);

        if (preg_match('#https?://(?:www\.)?linkedin\.com/in/[\w%-]+/?#i', $blob, $m)) {
            $plan['profile_url'] = $m[0];
            $plan['linkedin_url'] = $m[0];
        }

        if (preg_match('#https?://(?:www\.)?instagram\.com/([a-z0-9._]{1,30})/?#i', $blob, $ig)) {
            $plan['instagram_url'] = 'https://www.instagram.com/'.$ig[1];
            $plan['instagram_handle'] = $this->normalizeInstagramHandle($ig[1]);
        } elseif (preg_match('#(?:^|\s)@([a-z0-9._]{2,30})(?:\s|$)#i', $blob, $at)
            && $this->planTargetsChannel($plan, 'instagram')) {
            $plan['instagram_handle'] = $this->normalizeInstagramHandle($at[1]);
        }

        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $blob, $email)) {
            $plan['contact_email'] = Str::lower($email[0]);
        }

        if (preg_match('/\+?\d[\d\s().-]{7,}\d/', $blob, $phone)) {
            $plan['contact_phone'] = preg_replace('/\s+/', '', $phone[0]) ?? $phone[0];
        }

        return $plan;
    }

    /**
     * Attach a single saved contact (Instagram / email / phone / etc.) for one-shot outreach.
     *
     * @param  array<string, mixed>  $plan
     * @return array{
     *     list_hash: string,
     *     list_src: string,
     *     list_name: string,
     *     total_leads: int,
     *     match_score: int,
     *     sample_profiles?: list<array<string,mixed>>,
     *     profile_detail?: array<string,mixed>,
     *     note: string
     * }|null
     */
    private function tryAttachOnePersonContactList(User $user, array $plan): ?array
    {
        if (! $this->shouldTrySingleContactAttach($plan)) {
            return null;
        }

        $instagramHandle = $this->extractInstagramHandle($plan);
        if ($instagramHandle !== '' && $this->planTargetsChannel($plan, 'instagram')) {
            $existing = $this->findImportListByInstagramHandle($user, $instagramHandle);
            if ($existing !== null) {
                $existing['note'] = '1 Instagram contact from saved list';

                return $existing;
            }

            $built = $this->instagramAudience->searchAndPersist(
                $user,
                $instagramHandle,
                1,
                [$instagramHandle],
                null,
                true,
            );
            if ($built !== null) {
                return [
                    'list_hash' => (string) $built['list_hash'],
                    'list_src' => 'csv',
                    'list_name' => (string) $built['list_name'],
                    'total_leads' => max(1, (int) ($built['total_leads'] ?? 1)),
                    'match_score' => 100,
                    'sample_profiles' => $built['sample_profiles'] ?? [],
                    'profile_detail' => $built['profile_detail'] ?? null,
                    'note' => '1 Instagram profile from URL/handle',
                ];
            }
        }

        $email = $this->extractEmail($plan);
        if ($email !== '' && $this->planTargetsChannel($plan, 'email')) {
            $existing = $this->findImportListByEmail($user, $email);
            if ($existing !== null) {
                $existing['note'] = '1 email contact from saved list';

                return $existing;
            }

            return $this->createImportListFromContacts(
                $user,
                'Email: '.$email,
                [['full_name' => $this->contactDisplayName($plan, $email), 'email' => $email]],
                '1 email contact saved for outreach',
            );
        }

        $telegramHandle = $this->extractTelegramHandle($plan);
        if ($telegramHandle !== '' && $this->planTargetsChannel($plan, 'telegram')) {
            $existing = $this->findImportListByTelegramHandle($user, $telegramHandle);
            if ($existing !== null) {
                $existing['note'] = '1 Telegram contact from saved list';

                return $existing;
            }

            return $this->createImportListFromContacts(
                $user,
                'Telegram: @'.$telegramHandle,
                [[
                    'full_name' => $this->contactDisplayName($plan, '@'.$telegramHandle),
                    'telegram' => $telegramHandle,
                ]],
                '1 Telegram handle saved for outreach',
            );
        }

        $phone = $this->extractPhone($plan);
        if ($phone !== '' && ($this->planTargetsChannel($plan, 'whatsapp') || $this->planTargetsChannel($plan, 'telegram'))) {
            $existing = $this->findImportListByPhone($user, $phone);
            if ($existing !== null) {
                $existing['note'] = '1 phone contact from saved list';

                return $existing;
            }

            $contact = ['full_name' => $this->contactDisplayName($plan, $phone), 'phone' => $phone];
            if ($this->planTargetsChannel($plan, 'telegram')) {
                $telegram = trim((string) ($plan['telegram_handle'] ?? ''));
                if ($telegram !== '') {
                    $contact['telegram'] = ltrim($telegram, '@');
                }
            }

            return $this->createImportListFromContacts(
                $user,
                'Contact: '.$phone,
                [$contact],
                '1 phone contact saved for outreach',
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $attached
     * @return array<string, mixed>
     */
    private function applyAttachedAudience(array $plan, array $attached): array
    {
        $plan = PlanLeadList::merge(
            $plan,
            (string) $attached['list_hash'],
            (string) ($attached['list_src'] ?? 'csv'),
            (string) $attached['list_name'],
        );
        $plan['audience_status'] = 'attached';
        $plan['audience_leads'] = max(1, (int) ($attached['total_leads'] ?? 1));
        $plan['audience_note'] = $attached['list_name'].' ('.($attached['note'] ?? 'contact attached').')';
        $plan['target_count'] = 1;
        $plan['one_shot'] = true;
        $plan['one_time'] = true;

        if (! empty($attached['profile_detail']) && is_array($attached['profile_detail'])) {
            $plan['profile_detail'] = $attached['profile_detail'];
        }
        if (! empty($attached['sample_profiles']) && is_array($attached['sample_profiles'])) {
            $plan['sample_profiles'] = $attached['sample_profiles'];
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function shouldTrySingleContactAttach(array $plan): bool
    {
        if ($this->isOneShotPlan($plan) || (int) ($plan['target_count'] ?? 0) === 1) {
            return true;
        }

        return $this->extractInstagramHandle($plan) !== ''
            || $this->extractEmail($plan) !== ''
            || $this->extractPhone($plan) !== ''
            || $this->extractTelegramHandle($plan) !== '';
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function planTextBlob(array $plan): string
    {
        return implode(' ', array_filter([
            (string) ($plan['goal'] ?? ''),
            (string) ($plan['audience'] ?? ''),
            (string) ($plan['icp_notes'] ?? ''),
            (string) ($plan['message'] ?? ''),
            (string) ($plan['icp_summary'] ?? ''),
            (string) ($plan['instagram_url'] ?? ''),
            (string) ($plan['instagram_handle'] ?? ''),
            (string) ($plan['telegram_handle'] ?? ''),
        ]));
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function planTargetsChannel(array $plan, string $channel): bool
    {
        $channels = Str::lower((string) ($plan['preferred_channels'] ?? $plan['channels'] ?? ''));
        if ($channels === '') {
            return $channel === 'linkedin';
        }

        return str_contains($channels, $channel);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function extractInstagramHandle(array $plan): string
    {
        $handle = trim((string) ($plan['instagram_handle'] ?? ''));
        if ($handle !== '') {
            return $this->normalizeInstagramHandle($handle);
        }

        $audience = trim((string) ($plan['audience'] ?? ''));
        if (preg_match('/^@?[a-z0-9._]{2,30}$/i', $audience)) {
            return $this->normalizeInstagramHandle($audience);
        }

        $url = trim((string) ($plan['instagram_url'] ?? ''));
        if ($url !== '' && preg_match('~instagram\.com/([a-z0-9._]{1,30})/?~i', $url, $m)) {
            return $this->normalizeInstagramHandle($m[1]);
        }

        $blob = $this->planTextBlob($plan);
        if (preg_match('~instagram\.com/([a-z0-9._]{1,30})/?~i', $blob, $m)) {
            return $this->normalizeInstagramHandle($m[1]);
        }

        if (preg_match('/(?:^|\s)@([a-z0-9._]{2,30})(?:\s|$)/i', $blob, $m)) {
            return $this->normalizeInstagramHandle($m[1]);
        }

        if ($this->planTargetsChannel($plan, 'instagram')
            && preg_match('/\b(?:dm|message|reach out to|instagram)\s+([a-z0-9._]{2,30})\b/i', $blob, $m)) {
            return $this->normalizeInstagramHandle($m[1]);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function extractEmail(array $plan): string
    {
        $email = Str::lower(trim((string) ($plan['contact_email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        $blob = $this->planTextBlob($plan);
        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $blob, $m)) {
            return Str::lower($m[0]);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function extractPhone(array $plan): string
    {
        $phone = trim((string) ($plan['contact_phone'] ?? ''));
        if ($phone !== '') {
            return $phone;
        }

        $blob = $this->planTextBlob($plan);
        if (preg_match('/\+?\d[\d\s().-]{7,}\d/', $blob, $m)) {
            return preg_replace('/\s+/', '', $m[0]) ?? $m[0];
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function extractTelegramHandle(array $plan): string
    {
        $handle = trim((string) ($plan['telegram_handle'] ?? ''));
        if ($handle !== '') {
            return Str::lower(ltrim($handle, '@'));
        }

        $blob = $this->planTextBlob($plan);
        if (preg_match('~(?:t\.me|telegram\.me)/([a-z0-9_]{5,32})~i', $blob, $m)) {
            return Str::lower($m[1]);
        }
        if ($this->planTargetsChannel($plan, 'telegram')
            && preg_match('/(?:^|\s)@([a-z0-9_]{5,32})(?:\s|$)/i', $blob, $m)) {
            return Str::lower($m[1]);
        }

        return '';
    }

    private function normalizeInstagramHandle(string $value): string
    {
        $value = trim($value);
        if (preg_match('~instagram\.com/([^/?#]+)~i', $value, $m)) {
            $value = $m[1];
        }

        return Str::lower(ltrim($value, '@'));
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function contactDisplayName(array $plan, string $fallback): string
    {
        $audience = trim((string) ($plan['audience'] ?? ''));
        if ($audience !== '' && ! str_contains($audience, 'http') && strlen($audience) <= 80) {
            return $audience;
        }

        return $fallback;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findImportListByInstagramHandle(User $user, string $handle): ?array
    {
        $handle = $this->normalizeInstagramHandle($handle);
        $lead = V2OutreachImportLead::query()
            ->whereHas('importList', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('instagram_handle')
            ->where(function ($q) use ($handle) {
                $q->whereRaw('LOWER(REPLACE(instagram_handle, "@", "")) = ?', [$handle]);
            })
            ->latest('id')
            ->first();

        return $lead ? $this->importListRefFromLead($lead) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findImportListByEmail(User $user, string $email): ?array
    {
        $email = Str::lower(trim($email));
        $lead = V2OutreachImportLead::query()
            ->whereHas('importList', fn ($q) => $q->where('user_id', $user->id))
            ->whereRaw('LOWER(email) = ?', [$email])
            ->latest('id')
            ->first();

        return $lead ? $this->importListRefFromLead($lead) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findImportListByPhone(User $user, string $phone): ?array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        $lead = V2OutreachImportLead::query()
            ->whereHas('importList', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('phone')
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", ['%'.$digits.'%'])
            ->latest('id')
            ->first();

        return $lead ? $this->importListRefFromLead($lead) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findImportListByTelegramHandle(User $user, string $handle): ?array
    {
        $handle = Str::lower(ltrim(trim($handle), '@'));
        $lead = V2OutreachImportLead::query()
            ->whereHas('importList', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('telegram_handle')
            ->whereRaw('LOWER(REPLACE(telegram_handle, "@", "")) = ?', [$handle])
            ->latest('id')
            ->first();

        return $lead ? $this->importListRefFromLead($lead) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function importListRefFromLead(V2OutreachImportLead $lead): array
    {
        $list = $lead->importList;
        $listName = (string) ($list?->name ?? 'Saved contacts');

        return $this->normalizeListRef(
            (string) ($list?->list_hash ?? ''),
            'csv',
            $listName,
            max(1, (int) ($list?->lead_count ?? 1)),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     * @return array<string, mixed>
     */
    private function createImportListFromContacts(User $user, string $listName, array $contacts, string $note): array
    {
        $result = app(ImportLeadsCsvFromPlanService::class)->importContactsNow($user, $listName, $contacts);
        $list = $result['list'];

        return [
            'list_hash' => (string) $list['list_hash'],
            'list_src' => 'csv',
            'list_name' => (string) ($list['list_name'] ?? $listName),
            'total_leads' => max(1, (int) ($result['imported'] ?? 1)),
            'match_score' => 100,
            'note' => $note,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function hydrateProfileUrlFromText(array $plan): array
    {
        return $this->hydrateContactIdentifiersFromText($plan);
    }

    /**
     * Email/WhatsApp-only one-shots must not trigger LinkedIn people search
     * (that produced garbage lists from webinar copy / email addresses).
     *
     * @param  array<string, mixed>  $plan
     */
    private function shouldAutoSearchLinkedIn(array $plan): bool
    {
        if (! empty($plan['linkedin_url']) || ! empty($plan['profile_url'])) {
            if (preg_match('#linkedin\.com/in/#i', (string) ($plan['profile_url'] ?? $plan['linkedin_url'] ?? ''))) {
                return true;
            }
        }

        if ($this->extractInstagramHandle($plan) !== '') {
            return false;
        }

        if ($this->extractEmail($plan) !== '' || $this->extractPhone($plan) !== '') {
            return false;
        }

        $channels = Str::lower((string) ($plan['preferred_channels'] ?? $plan['channels'] ?? ''));
        $mentionsLinkedIn = $channels === '' || str_contains($channels, 'linkedin');
        $emailOnly = str_contains($channels, 'email') && ! $mentionsLinkedIn;
        $whatsappOnly = str_contains($channels, 'whatsapp') && ! $mentionsLinkedIn && ! str_contains($channels, 'email');
        $instagramOnly = str_contains($channels, 'instagram') && ! $mentionsLinkedIn;
        $telegramOnly = str_contains($channels, 'telegram') && ! $mentionsLinkedIn;
        $twitterOnly = (str_contains($channels, 'twitter') || preg_match('/\bx\b/', $channels)) && ! $mentionsLinkedIn;

        if ($emailOnly || $whatsappOnly || $instagramOnly || $telegramOnly || $twitterOnly) {
            return false;
        }

        $blob = (string) ($plan['audience'] ?? $plan['icp_notes'] ?? $plan['goal'] ?? '');
        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $blob) && ! $mentionsLinkedIn) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function searchNeedle(array $plan): string
    {
        $listName = Str::lower(trim((string) ($plan['list_name'] ?? '')));
        if ($listName !== '') {
            return $listName;
        }

        $goal = (string) ($plan['goal'] ?? '');
        if (preg_match('/["\']([^"\']{4,})["\']/', $goal, $quoted)) {
            return Str::lower(trim($quoted[1]));
        }

        return Str::lower(trim(implode(' ', array_filter([
            $plan['audience'] ?? null,
            $plan['icp_notes'] ?? null,
            $plan['icp']['summary'] ?? null,
            $plan['geography'] ?? null,
            $plan['goal'] ?? null,
        ]))));
    }

    /**
     * @return array{
     *     list_hash: string,
     *     list_src: string,
     *     list_name: string,
     *     total_leads: int,
     *     match_score: int
     * }
     */
    private function normalizeListRef(
        string $listHash,
        string $listSrc,
        string $listName,
        int $totalLeads = 0,
        int $matchScore = 100,
    ): array {
        return [
            'list_hash' => $listHash,
            'list_src' => $listSrc,
            'list_name' => $listName,
            'total_leads' => $totalLeads,
            'match_score' => $matchScore,
        ];
    }
}
