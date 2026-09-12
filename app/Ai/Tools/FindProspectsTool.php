<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Services\LeadListService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class FindProspectsTool extends GatedTool
{
    public function toolName(): string
    {
        return 'find_prospects';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Search existing SociFusion lead lists/audiences and return matching list ids and lead counts. '
            .'Read-only: does not scrape external platforms or mutate CRM state. '
            .'Prefer `search_prospects` for the same capability in new flows; use `discover_prospects` only for net-new external sourcing.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('ICP keywords, industry, audience name — use "all" or list_all=true to return every list'),
            'limit' => $schema->integer()->min(1)->max(50)->nullable(),
            'list_all' => $schema->boolean()->nullable()->description('true = return every saved lead list (no keyword filter)'),
        ];
    }

    protected function run(Request $request): array
    {
        $query = Str::lower(trim((string) $request['query']));
        $limit = (int) ($request['limit'] ?? 10);
        $listAll = (bool) ($request['list_all'] ?? false)
            || in_array($query, ['*', 'all', 'every', 'everything'], true);
        $tokens = array_values(array_filter(preg_split('/\s+/', $query) ?: [], fn ($t) => strlen($t) >= 2));

        $lists = app(LeadListService::class)->listsForUser($this->context->user->id);

        if ($listAll) {
            $matched = $lists
                ->sortByDesc(fn (array $list) => $list['created_at'] ?? '')
                ->take(max(1, min(50, $limit)))
                ->values()
                ->map(fn (array $l) => array_merge($l, [
                    'match_score' => 0,
                    'note' => 'All saved lead lists',
                    'list_hash' => (string) $l['list_id'],
                    'list_src' => (string) $l['src'],
                ]))
                ->all();
        } else {
            $matched = $lists
            ->map(function (array $list) use ($tokens, $query) {
                $name = Str::lower((string) $list['list_name']);
                $score = 0;
                if ($query !== '' && str_contains($name, $query)) {
                    $score += 10;
                }
                foreach ($tokens as $token) {
                    if (str_contains($name, $token)) {
                        $score += 2;
                    }
                }
                $score += min(3, (int) floor(((int) $list['total_leads']) / 200));

                return $score > 0 ? array_merge($list, [
                    'match_score' => $score,
                    'list_hash' => (string) $list['list_id'],
                    'list_src' => (string) $list['src'],
                ]) : null;
            })
            ->filter()
            ->sortByDesc('match_score')
            ->take($limit)
            ->values()
            ->all();

            if ($matched === [] && $lists->isNotEmpty()) {
                $matched = $lists->sortByDesc('total_leads')->take(min(5, $limit))->values()
                    ->map(fn (array $l) => array_merge($l, [
                        'match_score' => 0,
                        'note' => 'No keyword match — showing largest lists',
                        'list_hash' => (string) $l['list_id'],
                        'list_src' => (string) $l['src'],
                    ]))
                    ->all();
            }
        }

        $totalLeads = array_sum(array_map(fn ($l) => (int) ($l['total_leads'] ?? 0), $matched));

        return [
            'query' => $request['query'],
            'lists' => $matched,
            'total_leads_in_matches' => $totalLeads,
            'hint' => $matched === []
                ? 'No lists yet. Import leads, harvest competitor audiences, or create a list in Leads — then ask again.'
                : 'Pass list_hash + list_src into draft_campaign_plan or propose_strategy so Launch attaches the right audience.',
        ];
    }
}
