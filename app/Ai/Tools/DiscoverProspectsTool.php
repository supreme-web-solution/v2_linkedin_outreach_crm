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
        return 'Unified prospect discovery: search existing lead lists + competitor harvest audiences, auto-search LinkedIn when no list matches, then recommend best audience before staging a campaign.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('ICP, industry, geography, or goal keywords'),
            'competitors' => $schema->string()->nullable()->description('Optional comma-separated competitor names'),
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
        );
    }
}
