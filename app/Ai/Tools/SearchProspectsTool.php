<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Services\LeadListService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchProspectsTool extends GatedTool
{
    public function toolName(): string
    {
        return 'search_prospects';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Search existing saved prospects and lead lists in SociFusion only. '
            .'Use this first to reuse current CRM data before external discovery. '
            .'Read-only: never creates, edits, sends, or deletes anything.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('ICP keywords, list name, persona, company type, or "all"'),
            'limit' => $schema->integer()->min(1)->max(50)->nullable(),
            'list_all' => $schema->boolean()->nullable()->description('true = return every saved lead list'),
        ];
    }

    protected function run(Request $request): array
    {
        $query = Str::lower(trim((string) $request['query']));
        $limit = max(1, min(50, (int) ($request['limit'] ?? 10)));
        $listAll = (bool) ($request['list_all'] ?? false)
            || in_array($query, ['*', 'all', 'every', 'everything'], true);

        $lists = app(LeadListService::class)->listsForUser($this->context->user->id);
        $tokens = array_values(array_filter(preg_split('/\s+/', $query) ?: [], fn ($t) => strlen((string) $t) >= 2));

        $matched = $listAll
            ? $lists->sortByDesc(fn (array $list) => $list['created_at'] ?? '')->take($limit)->values()
            : $lists->map(function (array $list) use ($tokens, $query) {
                $name = Str::lower((string) ($list['list_name'] ?? ''));
                $score = 0;
                if ($query !== '' && str_contains($name, $query)) {
                    $score += 10;
                }
                foreach ($tokens as $token) {
                    if (str_contains($name, (string) $token)) {
                        $score += 2;
                    }
                }

                return $score > 0 ? array_merge($list, ['match_score' => $score]) : null;
            })->filter()->sortByDesc('match_score')->take($limit)->values();

        $rows = $matched->map(fn (array $l) => array_merge($l, [
            'list_hash' => (string) ($l['list_id'] ?? ''),
            'list_src' => (string) ($l['src'] ?? ''),
        ]))->all();

        return [
            'query' => (string) $request['query'],
            'lists' => $rows,
            'total_leads_in_matches' => (int) array_sum(array_map(fn (array $l) => (int) ($l['total_leads'] ?? 0), $rows)),
            'read_only' => true,
            'hint' => $rows === []
                ? 'No saved list matched. Use discover_prospects for external discovery if user wants net-new prospects.'
                : 'Use discover_prospects only when more prospects are needed than currently saved.',
        ];
    }
}
