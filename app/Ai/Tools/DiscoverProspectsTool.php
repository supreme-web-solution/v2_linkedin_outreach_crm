<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\DiscoverProspectsService;
use App\V2\Ai\Services\MultiChannelCampaignStagingService;
use App\V2\Ai\Services\TurnExecutionLedger;
use App\V2\Ai\Services\UserTurnIntentService;
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
        return 'Find and SAVE prospects to Leads. Default: search + save only — no campaigns, no messaging. '
            .'Use draft_campaign_plan only when the user explicitly asks to outreach/market/message. '
            .'Never use for status updates ("what do we have today", brief).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description(
                'LinkedIn ICP keywords, or Instagram Search keyword (Mindcase: e.g. "coffee", "nasa" — finds accounts by topic). Use @handle only for Handle mode (exact accounts)',
            ),
            'platform' => $schema->string()->nullable()->description('Optional: instagram (IG only), all (force parallel). Omit to auto-search all connected channels (LinkedIn + Instagram when both connected)'),
            'competitors' => $schema->string()->nullable()->description('Optional comma-separated competitor names'),
            'target_count' => $schema->integer()->min(1)->max(100)->nullable()->description(
                'Fetch and SAVE ~N NEW profiles (forces fresh search). Max 100 per pull across all channels.',
            ),
            'prefer_fresh' => $schema->boolean()->nullable()->description(
                'true = force a NEW search (no cache/reuse). Auto-set when user says more, fresh, new, don\'t reuse, etc.',
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
        $query = (string) $request['query'];
        $intent = app(UserTurnIntentService::class);
        $wantsOutreach = $intent->isOutreachCommand($query);
        $setupOnly = $intent->wantsCampaignSetupOnly($query);
        $preferFresh = (bool) ($request['prefer_fresh'] ?? false) || $intent->wantsFreshProspectPull($query);
        $targetCount = isset($request['target_count']) ? (int) $request['target_count'] : null;

        $result = app(DiscoverProspectsService::class)->discover(
                user: $this->context->user,
                query: $query,
                competitors: $request['competitors'] ?? null,
                limit: (int) ($request['limit'] ?? 10),
                targetCount: $targetCount,
                preferFresh: $preferFresh,
                geography: isset($request['geography']) ? (string) $request['geography'] : null,
                networkDegree: isset($request['network_degree']) ? (string) $request['network_degree'] : null,
                title: isset($request['title']) ? (string) $request['title'] : null,
                company: isset($request['company']) ? (string) $request['company'] : null,
                openLink: array_key_exists('open_link', $request->all()) ? (bool) $request['open_link'] : null,
                profileUrl: isset($request['profile_url']) ? (string) $request['profile_url'] : null,
                platform: (string) ($request['platform'] ?? 'auto'),
        );

        if (($result['mode'] ?? '') !== 'parallel' || empty($result['lists'])) {
            return $result;
        }

        $ledger = app(TurnExecutionLedger::class);
        if ($ledger->ownsTurnResult()) {
            return [
                'already_executed' => true,
                'do_not_search_again' => true,
                'report' => $ledger->report(),
                'instruction' => 'This turn already searched and saved prospects. Reply with the report only.',
            ];
        }

        $allocation = is_array($result['allocation'] ?? null) ? $result['allocation'] : [];
        $channelResults = is_array($result['channel_results'] ?? null) ? $result['channel_results'] : [];

        if ($wantsOutreach) {
            $staged = app(MultiChannelCampaignStagingService::class)->stage(
                $this->context->user,
                $this->context->organizationId,
                $query,
                $result['lists'],
                $this->context->conversation,
            );

            if ($staged !== []) {
                $ledger->recordOutreach($query, $allocation, $channelResults, $staged);
                $result['staged_campaigns'] = $staged;
                $result['execution_report'] = $ledger->report();
                $result['instruction'] = $setupOnly
                    ? 'User asked for outreach setup but NOT to send yet. Reply with the execution report. Campaigns are staged in Review & Launch — do NOT LAUNCH or activate.'
                    : 'User asked for outreach. Reply with the execution report.';
            }

            return $result;
        }

        $ledger->recordDiscovery($query, $allocation, $channelResults);
        $result['discovery_only'] = true;
        $result['execution_report'] = $ledger->report();
        $result['instruction'] = 'User asked to find/save prospects only — no outreach. Reply with the discovery report. Do NOT draft campaigns unless they ask to message/outreach.';

        return $result;
    }
}
