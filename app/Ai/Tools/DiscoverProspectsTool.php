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
        return 'Prospect discovery. When target_count or prefer_fresh=true: ALWAYS fetch NEW LinkedIn profiles, SAVE them as a lead list, and return that list_hash — never reuse engagers/old lists. Without target_count: may suggest strong-matching saved lists first.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('ICP, industry, geography, or goal keywords'),
            'competitors' => $schema->string()->nullable()->description('Optional comma-separated competitor names'),
            'target_count' => $schema->integer()->min(10)->max(500)->nullable()->description(
                'Fetch and SAVE ~N NEW LinkedIn profiles (forces fresh search; do not reuse old lists)',
            ),
            'prefer_fresh' => $schema->boolean()->nullable()->description(
                'true = force LinkedIn fetch+save even without target_count',
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
        );
    }
}
