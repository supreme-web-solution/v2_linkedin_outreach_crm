<?php

namespace App\V2\Ai\Services;

use App\Jobs\FetchCompetitorFollowersJob;
use App\Models\AiActionApproval;
use App\Models\Audience;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use Illuminate\Support\Str;

class CompetitorHarvestFromPlanService
{
    /**
     * @return array{audience: Audience, url: string, message: string}
     */
    public function startFromApproval(AiActionApproval $approval, User $user): array
    {
        $existingId = data_get($approval->result, 'audience_id');
        if ($existingId) {
            $existing = Audience::query()->find($existingId);
            if ($existing) {
                return [
                    'audience' => $existing,
                    'url' => url('/competitor-followers/'.$existing->id),
                    'message' => 'Competitor harvest already queued for this plan.',
                ];
            }
        }

        $payload = $approval->payload ?? [];
        $linkedinUrl = trim((string) ($payload['linkedin_url'] ?? ''));
        if ($linkedinUrl === '') {
            throw new \InvalidArgumentException('Missing LinkedIn URL in harvest plan.');
        }

        if (! V2IntegrationAccount::activeUnipileAccountId($user->id)) {
            throw new \RuntimeException('Connect LinkedIn via Integrations before harvesting.');
        }

        $normalizedUrl = $this->normalizeLinkedInUrl($linkedinUrl);
        $sourceType = $this->detectSourceType($normalizedUrl);
        $label = (string) ($payload['label'] ?? $payload['competitor_name'] ?? '');
        $companyName = $label !== ''
            ? Str::limit($label, 120, '')
            : $this->defaultAudienceName($normalizedUrl, $sourceType);

        if (! str_contains(Str::lower($companyName), 'engager')) {
            $companyName = Str::limit(rtrim($companyName, ' -').' - Active Engagers', 180, '');
        }

        $audience = $this->findOrCreateAudience($user, $normalizedUrl, $companyName, $sourceType);

        $meta = json_decode((string) $audience->source_meta, true) ?? [];
        $meta['company_url'] = $normalizedUrl;
        $meta['source_type'] = $sourceType;
        $meta['fetch_status'] = 'pending';
        $meta['fetch_started_at'] = now()->toIso8601String();
        $meta['fetch_progress'] = 'Queued from Command Center…';
        $meta['ai_approval_id'] = $approval->id;
        $audience->source_meta = json_encode($meta);
        $audience->save();

        FetchCompetitorFollowersJob::dispatch(
            $user->id,
            $audience->id,
            $linkedinUrl,
            '',
            '',
        );

        $approval->update([
            'result' => [
                'audience_id' => $audience->id,
                'audience_list_id' => (string) $audience->audience_id,
                'status' => 'harvest_queued',
            ],
            'status' => 'executed',
        ]);

        return [
            'audience' => $audience,
            'url' => url('/competitor-followers/'.$audience->id),
            'message' => "Harvest started for {$companyName}. This can take a few minutes — I'll notify you when it's ready to draft outreach.",
        ];
    }

    private function findOrCreateAudience(User $user, string $normalizedUrl, string $companyName, string $sourceType): Audience
    {
        $existing = Audience::query()
            ->where('user_id', $user->id)
            ->where('source', 'linkedin_company_followers')
            ->where('tag', 'competitor_active_followers')
            ->get()
            ->first(function (Audience $aud) use ($normalizedUrl) {
                $meta = json_decode((string) $aud->source_meta, true) ?? [];

                return ($meta['company_url'] ?? null) === $normalizedUrl;
            });

        if ($existing) {
            if ($existing->audience_name !== $companyName) {
                $existing->audience_name = $companyName;
                $existing->save();
            }

            return $existing;
        }

        return Audience::query()->create([
            'audience_name' => $companyName,
            'audience_id' => now()->timestamp.$user->id,
            'audience_type' => 'LI',
            'user_id' => $user->id,
            'tag' => 'competitor_active_followers',
            'source' => 'linkedin_company_followers',
            'source_meta' => json_encode([
                'company_url' => $normalizedUrl,
                'source_type' => $sourceType,
            ]),
        ]);
    }

    private function normalizeLinkedInUrl(string $url): string
    {
        return rtrim(
            parse_url($url, PHP_URL_SCHEME).'://'.
            parse_url($url, PHP_URL_HOST).
            parse_url($url, PHP_URL_PATH),
            '/'
        );
    }

    private function detectSourceType(string $normalizedUrl): string
    {
        $path = (string) parse_url($normalizedUrl, PHP_URL_PATH);

        return preg_match('~/in/[^/?#]+~i', $path) ? 'person' : 'company';
    }

    private function defaultAudienceName(string $normalizedUrl, string $sourceType): string
    {
        $path = (string) parse_url($normalizedUrl, PHP_URL_PATH);

        if ($sourceType === 'person' && preg_match('~/in/([^/?#]+)~i', $path, $matches)) {
            return $this->humanizeSlug(rawurldecode($matches[1])).' - Active Engagers';
        }

        if (preg_match('~/company/([^/?#]+)~i', $path, $matches)) {
            return ucfirst(rawurldecode($matches[1])).' - Active Engagers';
        }

        return 'Competitor - Active Engagers';
    }

    private function humanizeSlug(string $slug): string
    {
        return Str::title(str_replace(['-', '_'], ' ', $slug));
    }
}
