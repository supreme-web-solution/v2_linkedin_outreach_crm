<?php

namespace App\V2\Ai\Services;

use App\Models\AiEmployeeSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Domain-agnostic audience quality scoring for discovery (esp. Instagram).
 * Prefers ICP buyer/niche overlap; demotes mega/celebrity/media accounts with no fit signal.
 */
class DiscoveryAudienceQualityService
{
    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly WorkspaceContextService $workspace,
    ) {}

    /**
     * Buyer-side tokens for fit scoring — not the seller pitch.
     *
     * @return list<string>
     */
    public function icpBuyerTokens(\App\Models\User $user, int $organizationId): array
    {
        if ($organizationId <= 0) {
            return [];
        }

        $settings = $this->settingsService->for($user, $organizationId);
        $icp = $this->workspace->storedIcp($settings);
        $chunks = [];

        foreach (['who_we_sell_to', 'industry', 'search_query', 'decision_maker', 'likely_pain', 'outreach_angle'] as $key) {
            $chunks[] = (string) Arr::get($icp, $key, '');
        }
        foreach (['niches', 'customers', 'search_titles', 'jobs_to_be_done'] as $listKey) {
            $list = Arr::get($icp, $listKey, []);
            if (is_array($list)) {
                $chunks[] = implode(' ', array_map(fn ($v) => (string) $v, $list));
            }
        }

        return $this->tokenize(implode(' ', $chunks));
    }

    /**
     * @param  list<array<string, mixed>>  $rows  Raw Mindcase / discovery rows
     * @return array{
     *     kept:list<array<string,mixed>>,
     *     rejected:int,
     *     weak_fit:bool,
     *     warning:?string
     * }
     */
    public function filterInstagramRows(
        array $rows,
        array $buyerTokens,
        int $keepLimit,
    ): array {
        $scored = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $score = $this->scoreInstagramRow($row, $buyerTokens);
            if ($score < 0) {
                continue;
            }
            // With ICP tokens, require a real buyer-fit signal — not media/noise padding.
            if ($buyerTokens !== [] && $score < 2.0) {
                continue;
            }
            $scored[] = ['score' => $score, 'row' => $row];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $kept = [];
        foreach ($scored as $item) {
            $kept[] = $item['row'];
            if (count($kept) >= $keepLimit) {
                break;
            }
        }

        $rejected = max(0, count($rows) - count($kept));
        $avg = $kept === []
            ? 0.0
            : (float) collect($scored)->take(count($kept))->avg('score');
        // Weak fit means "do not save" — never ship comic-con / media pages as ICP.
        $weakFit = $buyerTokens !== [] && ($kept === [] || $avg < 2.5);

        $warning = null;
        if ($kept === [] && $rows !== []) {
            $warning = 'Instagram results looked like media, mega-brand, or off-niche accounts — not saved. Try a clearer buyer niche keyword.';
        } elseif ($weakFit) {
            $warning = 'Instagram matches were too weak for your ICP — not saved. Continuing on other channels.';
            $kept = [];
            $rejected = count($rows);
        } elseif ($rejected > 0 && $kept !== []) {
            $warning = "Filtered {$rejected} weak Instagram profile(s) (mega/celebrity/media or no buyer-fit signal).";
        }

        return [
            'kept' => $kept,
            'rejected' => $rejected,
            'weak_fit' => $weakFit,
            'warning' => $warning,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $buyerTokens
     */
    public function scoreInstagramRow(array $row, array $buyerTokens): float
    {
        $username = Str::lower(ltrim(trim((string) (
            Arr::get($row, 'username') ?? Arr::get($row, 'handle') ?? ''
        )), '@'));
        $bio = Str::lower(trim((string) (Arr::get($row, 'bio') ?? Arr::get($row, 'biography') ?? '')));
        $name = Str::lower(trim((string) (Arr::get($row, 'fullName') ?? Arr::get($row, 'full_name') ?? '')));
        $followers = (int) (Arr::get($row, 'followers') ?? Arr::get($row, 'followersCount') ?? 0);
        $hay = trim($username.' '.$name.' '.$bio);

        if ($username === '') {
            return -1;
        }

        // Media / events / pages are almost never the buyer — reject structurally.
        if ($this->looksLikeMediaOrEventPage($hay)) {
            return -1;
        }

        $overlap = 0.0;
        foreach ($buyerTokens as $token) {
            if ($token !== '' && str_contains($hay, $token)) {
                $overlap += 1.0;
            }
        }

        // Mega / celebrity accounts need clear buyer-fit; otherwise reject.
        if ($followers >= 1_000_000 && $overlap < 2) {
            return -1;
        }
        if ($followers >= 250_000 && $overlap < 1) {
            return -1;
        }
        if ($followers >= 100_000 && $overlap < 1 && $this->looksLikePublicFigureOrBrand($hay)) {
            return -1;
        }

        $score = $overlap * 2.0;
        if ($bio !== '') {
            $score += 0.5;
        }
        // Prefer reachable business-scale accounts when no ICP tokens are available.
        if ($followers > 0 && $followers < 50_000) {
            $score += 0.5;
        }
        if ($followers >= 50_000 && $followers < 250_000 && $overlap >= 1) {
            $score += 0.25;
        }

        return $score;
    }

    private function looksLikeMediaOrEventPage(string $hay): bool
    {
        // Structural signals only — no brand/name hardcoding.
        return (bool) preg_match(
            '/\b(news|media brand|media company|magazine|newsletter|podcast|comic.?con|convention|festival|breaking|funding round|unicorns?|vc news|operator-first|insider guide|tag your photos|don\'t slide into our dms|don\'t dm|email us|official account of|startup news|founder stories)\b/u',
            $hay
        );
    }

    private function looksLikePublicFigureOrBrand(string $hay): bool
    {
        // Structural signals only — no brand/name hardcoding beyond clear media patterns above.
        return (bool) preg_match(
            '/\b(official account|ask me anything|candidato|deputado|congress|senator|celebrity|influencer|content creator|lifestyle|entertainment|pediatrician|neonatologist|professor)\b/u',
            $hay
        );
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $text = Str::lower(preg_replace('/[^a-z0-9\s\-]/i', ' ', $text) ?? '');
        $parts = preg_split('/[\s,\-\/|]+/', $text) ?: [];
        $stop = [
            'a', 'an', 'the', 'and', 'or', 'to', 'for', 'of', 'in', 'on', 'with', 'our', 'we', 'you',
            'your', 'their', 'this', 'that', 'from', 'into', 'across', 'various', 'industries',
            'business', 'businesses', 'company', 'companies', 'services', 'solutions', 'custom',
            'software', 'powered', 'ai', 'growth', 'drive', 'transform', 'operations', 'help',
            'helping', 'sell', 'selling', 'ideal', 'customers', 'customer',
        ];

        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if (strlen($part) < 3 || in_array($part, $stop, true)) {
                continue;
            }
            if (! in_array($part, $out, true)) {
                $out[] = $part;
            }
            if (count($out) >= 24) {
                break;
            }
        }

        return $out;
    }
}
