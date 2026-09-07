<?php

namespace App\Ai\Tools;

use App\Models\Audience;
use App\Models\AudienceList;
use App\V2\Ai\Enums\AiToolPermission;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class AnalyzeCompetitorAudienceTool extends GatedTool
{
    public function toolName(): string
    {
        return 'analyze_competitor_audience';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'List competitor-engager audiences already harvested for this user, with lead counts. Suggest next harvest if competitors are named but not harvested yet.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'competitors' => $schema->string()->nullable()->description('Comma-separated competitor names or LinkedIn URLs'),
            'limit' => $schema->integer()->min(1)->max(20)->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $limit = (int) ($request['limit'] ?? 10);
        $competitors = array_values(array_filter(array_map('trim', explode(',', (string) ($request['competitors'] ?? '')))));

        $audiences = Audience::query()
            ->where('user_id', $this->context->user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'audience_name', 'audience_id', 'source', 'source_meta', 'created_at']);

        $rows = $audiences->map(function (Audience $a) {
            $count = AudienceList::query()->where('audience_id', $a->audience_id)->count();
            $meta = json_decode((string) $a->source_meta, true) ?: [];

            return [
                'id' => $a->id,
                'list_id' => (string) $a->audience_id,
                'list_hash' => (string) $a->audience_id,
                'list_name' => $a->audience_name,
                'source' => $a->source,
                'total_leads' => $count,
                'company_name' => $meta['company_name'] ?? $meta['person_name'] ?? null,
                'src' => 'aud',
                'list_src' => 'aud',
                'created_at' => optional($a->created_at)->toIso8601String(),
            ];
        });

        if ($competitors !== []) {
            $rows = $rows->filter(function (array $row) use ($competitors) {
                $hay = Str::lower(($row['list_name'] ?? '').' '.($row['company_name'] ?? ''));

                foreach ($competitors as $c) {
                    if ($c !== '' && str_contains($hay, Str::lower($c))) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $top = $rows->sortByDesc('total_leads')->take($limit)->values()->all();
        $total = array_sum(array_map(fn ($r) => (int) $r['total_leads'], $top));

        $missing = [];
        foreach ($competitors as $c) {
            $found = collect($top)->contains(function (array $row) use ($c) {
                return str_contains(Str::lower(($row['list_name'] ?? '').' '.($row['company_name'] ?? '')), Str::lower($c));
            });
            if (! $found) {
                $missing[] = $c;
            }
        }

        return [
            'audiences' => $top,
            'total_leads' => $total,
            'competitors_requested' => $competitors,
            'not_harvested_yet' => $missing,
            'next_step' => $missing !== []
                ? 'Use prepare_competitor_harvest with their LinkedIn URL, then LAUNCH when ready.'
                : ($top === []
                    ? 'Use prepare_competitor_harvest with a competitor LinkedIn URL, then draft outreach when done.'
                    : 'Pick list_hash + list_src from audiences and pass into draft_campaign_plan before Launch.'),
        ];
    }
}
