<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\DiscoverProspectsService;
use App\V2\Ai\Services\MultiChannelCampaignStagingService;
use App\V2\Ai\Services\TurnExecutionLedger;
use App\V2\Ai\Services\TurnPlanContext;
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
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Discover net-new prospects from external searchable channels and save them to Leads. '
            .'Use this when existing lists are insufficient or user explicitly requests fresh/new prospects. '
            .'Call ONCE per user turn — do not retry with different keywords if the first search saved or failed. '
            .'This mutates CRM state by saving leads but does not send messages by itself.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description(
                'LinkedIn ICP keywords, or Instagram Search keyword (Mindcase: e.g. "coffee", "nasa" — finds accounts by topic). Use @handle only for Handle mode (exact accounts)',
            ),
            'platform' => $schema->string()->nullable()->description('Optional: linkedin (default for find-only), instagram (IG only), all (force parallel LinkedIn+IG when both connected). Omit for auto: find-only → LinkedIn; outreach → connected searchable channels.'),
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
        $turnPlan = app(TurnPlanContext::class)->get();
        $workflowBlock = app(\App\V2\Ai\Services\WorkflowOrchestrationGuardService::class)
            ->blockTool(is_array($turnPlan) ? $turnPlan : null, 'discover_prospects');
        if ($workflowBlock !== null) {
            return $workflowBlock;
        }

        $ledger = app(TurnExecutionLedger::class);
        if ($ledger->hasDiscoveryAttempt()) {
            return [
                'already_executed' => true,
                'do_not_search_again' => true,
                'report' => $ledger->report() ?: 'Prospect search already ran this turn.',
                'instruction' => 'Do NOT call discover_prospects again. Continue with draft_campaign_plan, or reply with the saved results.',
            ];
        }

        $query = (string) $request['query'];
        $intent = app(UserTurnIntentService::class);
        $userMessage = $this->latestUserMessage();
        $intentSource = $userMessage !== '' ? $userMessage : $query;

        $wantsOutreach = $intent->isOutreachCommand($intentSource);
        $setupOnly = $intent->wantsCampaignSetupOnly($intentSource);
        $preferFresh = (bool) ($request['prefer_fresh'] ?? false) || $intent->wantsFreshProspectPull($intentSource);

        $discover = app(DiscoverProspectsService::class);
        $userCount = $discover->inferCountFromQuery($intentSource)
            ?? $discover->inferCountFromQuery($query);
        $targetCount = $userCount;
        $turnPlan = app(TurnPlanContext::class)->get();
        if (is_array($turnPlan)) {
            $workflowQty = (int) ($turnPlan['state_evaluation']['remaining_discovery'] ?? 0);
            if ($workflowQty > 0 && isset($turnPlan['workflow_run_id'])) {
                $targetCount = $workflowQty;
            }
        }
        if ($targetCount === null && isset($request['target_count']) && $userMessage === '') {
            $targetCount = (int) $request['target_count'];
        }

        $platform = (string) ($request['platform'] ?? 'auto');
        if ($platform === 'auto' && $intent->prefersLinkedInOnlyDiscovery($intentSource) && ! $wantsOutreach) {
            $platform = 'linkedin';
        } elseif ($platform === 'auto' && $intent->wantsInstagramDiscovery($intentSource) === false
            && ! $wantsOutreach
            && ! preg_match('/\b(instagram|ig)\b/i', $query)
        ) {
            $platform = 'linkedin';
        }

        $ledger->markDiscoveryAttempted();

        $result = $discover->discover(
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
            platform: $platform,
        );

        if (($result['mode'] ?? '') === 'parallel' && ! empty($result['lists'])) {
            return $this->finalizeParallelDiscovery(
                $result,
                $ledger,
                $intentSource,
                $query,
                $wantsOutreach,
                $setupOnly,
            );
        }

        return $this->finalizeSingleChannelDiscovery(
            $result,
            $ledger,
            $intentSource,
            $query,
            $wantsOutreach,
            $setupOnly,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finalizeParallelDiscovery(
        array $result,
        TurnExecutionLedger $ledger,
        string $intentSource,
        string $query,
        bool $wantsOutreach,
        bool $setupOnly,
    ): array {
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
                $intentSource !== '' ? $intentSource : $query,
                $result['lists'],
                $this->context->conversation,
            );

            if ($staged !== []) {
                $ledger->recordOutreach($intentSource !== '' ? $intentSource : $query, $allocation, $channelResults, $staged);
                $result['staged_campaigns'] = $staged;
                $result['execution_report'] = $ledger->report();
                $result['instruction'] = $setupOnly
                    ? 'User asked for outreach setup but NOT to send yet. Reply with the execution report. Campaigns are staged in Review & Launch — do NOT LAUNCH or activate.'
                    : 'User asked for outreach. Reply with the execution report. Do NOT call discover_prospects again.';

                return $result;
            }
        }

        $ledger->recordDiscovery($intentSource !== '' ? $intentSource : $query, $allocation, $channelResults);
        $result['discovery_only'] = true;
        $result['execution_report'] = $ledger->report();
        $result['instruction'] = 'User asked to find/save prospects only — no outreach. Reply with the discovery report. Do NOT draft campaigns unless they ask to message/outreach.';

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finalizeSingleChannelDiscovery(
        array $result,
        TurnExecutionLedger $ledger,
        string $intentSource,
        string $query,
        bool $wantsOutreach,
        bool $setupOnly,
    ): array {
        $platform = (string) ($result['platform'] ?? 'linkedin');
        $lists = is_array($result['lists'] ?? null) ? $result['lists'] : [];
        if ($lists === [] && is_array($result['best_match'] ?? null)) {
            $lists = [$result['best_match']];
        }

        $allocation = [
            'allocation' => [
                $platform => (int) ($result['total_leads_in_matches'] ?? 0),
            ],
        ];
        $channelResults = [$platform => $result];

        if ($wantsOutreach && $lists !== []) {
            $staged = app(MultiChannelCampaignStagingService::class)->stage(
                $this->context->user,
                $this->context->organizationId,
                $intentSource !== '' ? $intentSource : $query,
                $lists,
                $this->context->conversation,
            );

            if ($staged !== []) {
                $ledger->recordOutreach($intentSource !== '' ? $intentSource : $query, $allocation, $channelResults, $staged);
                $result['staged_campaigns'] = $staged;
                $result['execution_report'] = $ledger->report();
                $result['instruction'] = $setupOnly
                    ? 'Outreach plan staged — reply with the execution report. Do NOT search again or LAUNCH without user approval.'
                    : 'Outreach plan staged — reply with the execution report. Do NOT call discover_prospects again.';

                return $result;
            }
        }

        $ledger->recordDiscovery($intentSource !== '' ? $intentSource : $query, $allocation, $channelResults);
        $result['discovery_only'] = ! $wantsOutreach;
        $result['execution_report'] = $ledger->report();
        $result['instruction'] = ($result['search_failed'] ?? false)
            ? 'Search failed or returned nothing — explain why and suggest trying again in a new message. Do NOT call discover_prospects again this turn.'
            : ($wantsOutreach
                ? 'Prospects saved but campaign could not be auto-staged — call draft_campaign_plan with the list_hash. Do NOT search again.'
                : 'Reply with the discovery report. Do NOT draft or launch campaigns unless the user asked to outreach.');

        return $result;
    }

    private function latestUserMessage(): string
    {
        $conversation = $this->context->conversation;
        if (! $conversation?->id) {
            return '';
        }

        $message = \App\Models\AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->value('content');

        return trim((string) $message);
    }
}
