<?php

namespace App\V2\Ai\Services;

use App\V2\Services\LeadListService;
use Illuminate\Support\Str;

/**
 * Matches saved lead lists to a semantic segment — reuses LeadListService, no external discovery.
 */
class ProspectListMatchService
{
    public function __construct(
        private readonly LeadListService $leadLists,
    ) {}

    /**
     * @return array<int, array{list_hash: string, list_src: string, list_name: string, match_score: int}>
     */
    public function matchForSegment(int $userId, ?string $segment, int $limit = 10): array
    {
        $segment = trim((string) $segment);
        $lists = $this->leadLists->listsForUser($userId);

        if ($segment === '') {
            return $lists->sortByDesc('total_leads')->take($limit)->values()
                ->map(fn (array $list) => $this->toMatchRow($list, 0))
                ->all();
        }

        $tokens = array_values(array_filter(
            preg_split('/\s+/', Str::lower($segment)) ?: [],
            fn ($t) => strlen((string) $t) >= 2,
        ));
        $segmentLower = Str::lower($segment);

        $matched = $lists
            ->map(function (array $list) use ($tokens, $segmentLower) {
                $name = Str::lower((string) ($list['list_name'] ?? ''));
                $score = 0;
                if ($segmentLower !== '' && str_contains($name, $segmentLower)) {
                    $score += 10;
                }
                foreach ($tokens as $token) {
                    if (str_contains($name, $token)) {
                        $score += 2;
                    }
                }
                if ($score > 0) {
                    $score += min(3, (int) floor(((int) ($list['total_leads'] ?? 0)) / 200));
                }

                return $score > 0 ? $this->toMatchRow($list, $score) : null;
            })
            ->filter()
            ->sortByDesc('match_score')
            ->take($limit)
            ->values()
            ->all();

        if ($matched !== []) {
            return $matched;
        }

        return $lists->sortByDesc('total_leads')->take(min(3, $limit))->values()
            ->map(fn (array $list) => $this->toMatchRow($list, 0))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $list
     * @return array{list_hash: string, list_src: string, list_name: string, match_score: int}
     */
    private function toMatchRow(array $list, int $score): array
    {
        return [
            'list_hash' => (string) ($list['list_id'] ?? ''),
            'list_src' => (string) ($list['src'] ?? ''),
            'list_name' => (string) ($list['list_name'] ?? ''),
            'match_score' => $score,
        ];
    }
}
