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
        return 'Prospect discovery. platform=linkedin (default): Unipile people search. platform=instagram: Mindcase '
            .'KEYWORD search is primary (query + target_count → many profiles). @handle/profile_url only for exact person. '
            .'SAVES csv list with instagram filled. Always save first, then draft_campaign_plan.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description(
                'LinkedIn ICP keywords, or Instagram KEYWORD (e.g. "fitness coaches Lagos"). Use @handle only when looking up one known account',
            ),
            'platform' => $schema->string()->nullable()->description('linkedin (default) or instagram (Mindcase keyword search)'),
            'competitors' => $schema->string()->nullable()->description('Optional comma-separated competitor names'),
            'target_count' => $schema->integer()->min(1)->max(500)->nullable()->description(
                'Fetch and SAVE ~N NEW profiles (forces fresh search)',
            ),
            'prefer_fresh' => $schema->boolean()->nullable()->description(
                'true = force fetch+save even without target_count',
            ),
            'geography' => $schema->string()->nullable()->description(
                'LinkedIn: country or city (e.g. United States, Nigeria, London)',
            ),
            'network_degree' => $schema->string()->nullable()->description(
                'LinkedIn: 1st|2nd|3rd|F|S|O or combos like "2nd,3rd"',
            ),
            'title' => $schema->string()->nullable()->description('LinkedIn job title filter'),
            'company' => $schema->string()->nullable()->description('LinkedIn current company filter'),
            'open_link' => $schema->boolean()->nullable()->description('LinkedIn Open Profile only'),
            'profile_url' => $schema->string()->nullable()->description(
                'Exact linkedin.com/in/... or instagram.com/... URL',
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
            platform: (string) ($request['platform'] ?? 'linkedin'),
        );
    }
}
