<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\DiscoverProspectsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class DiscoverProspectsTool extends GatedTool
{
    public function toolName(): string
    {
        return 'discover_prospects';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Prospect discovery via LinkedIn classic search (keywords, title, location/country, company, school, '
            .'network degree F/S/O, open_link) or saved lists. When target_count or prefer_fresh=true: ALWAYS fetch NEW '
            .'profiles, SAVE them, return list_hash. Pass network_degree=1st for connections-only (campaigns should DM, not invite).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('ICP / industry / role keywords (not the full product pitch)'),
            'competitors' => $schema->string()->nullable()->description('Optional comma-separated competitor names'),
            'target_count' => $schema->integer()->min(10)->max(500)->nullable()->description(
                'Fetch and SAVE ~N NEW LinkedIn profiles (forces fresh search; do not reuse old lists)',
            ),
            'prefer_fresh' => $schema->boolean()->nullable()->description(
                'true = force LinkedIn fetch+save even without target_count',
            ),
            'geography' => $schema->string()->nullable()->description(
                'Country or city for LinkedIn location filter (e.g. United States, Nigeria, London)',
            ),
            'network_degree' => $schema->string()->nullable()->description(
                'Connection degree: 1st|2nd|3rd|F|S|O or combos like "2nd,3rd". 1st = already connected.',
            ),
            'title' => $schema->string()->nullable()->description('Job title filter e.g. Founder, VP Sales'),
            'company' => $schema->string()->nullable()->description('Current company name filter'),
            'open_link' => $schema->boolean()->nullable()->description('Only Open Profile / open-to-connect profiles'),
            'profile_url' => $schema->string()->nullable()->description(
                'Exact linkedin.com/in/... URL — imports that one person (preferred when user confirms a profile)',
            ),
            'limit' => $schema->integer()->min(1)->max(20)->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        return app(DiscoverProspectsService::class)->discover(
            user: $this->context->user,
            query: (string) $request['query'],
            competitors: $request['competitors'] ?? null,
            limit: (int) ($request['limit'] ?? 10),
            targetCount: isset($request['target_count']) ? (int) $request['target_count'] : null,
            preferFresh: (bool) ($request['prefer_fresh'] ?? false),
            geography: isset($request['geography']) ? (string) $request['geography'] : null,
            networkDegree: isset($request['network_degree']) ? (string) $request['network_degree'] : null,
            title: isset($request['title']) ? (string) $request['title'] : null,
            company: isset($request['company']) ? (string) $request['company'] : null,
            openLink: array_key_exists('open_link', $request->all()) ? (bool) $request['open_link'] : null,
            profileUrl: isset($request['profile_url']) ? (string) $request['profile_url'] : null,
        );
    }
}
