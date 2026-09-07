<?php

namespace App\Ai\Tools;

use App\Models\V2Campaign;
use App\Models\V2OutreachCampaign;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Outreach\OutreachCampaignStatsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetCampaignStatsTool extends GatedTool
{
    public function toolName(): string
    {
        return 'get_campaign_stats';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Get a summary of campaign / outreach performance for the current organization.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->description('Optional specific outreach campaign id')->nullable(),
            'scope' => $schema->string()->enum(['outreach', 'linkedin', 'all'])->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $orgId = $this->context->organizationId;
        $scope = (string) ($request['scope'] ?? 'all');
        $campaignId = $request['campaign_id'] ?? null;
        $statsService = app(OutreachCampaignStatsService::class);

        $outreachQuery = V2OutreachCampaign::query()
            ->where('organization_id', $orgId)
            ->where('status', '!=', 'template');

        if ($campaignId) {
            $outreachQuery->where('id', (int) $campaignId);
        }

        $outreachCampaigns = $outreachQuery->latest('id')->limit(10)->get();

        $outreachSummaries = [];
        $totals = [
            'targeted' => 0,
            'running' => 0,
            'replies' => 0,
            'done' => 0,
            'errors' => 0,
        ];

        foreach ($outreachCampaigns as $campaign) {
            $stats = $statsService->statsFor($campaign);
            $by = $stats['by_status'] ?? [];
            $targeted = (int) ($stats['total_leads'] ?? 0);
            $replies = (int) ($by['replied'] ?? 0);
            $running = (int) ($by['running'] ?? 0);
            $done = (int) ($by['done'] ?? 0);
            $errors = (int) ($by['error'] ?? 0);

            $totals['targeted'] += $targeted;
            $totals['running'] += $running;
            $totals['replies'] += $replies;
            $totals['done'] += $done;
            $totals['errors'] += $errors;

            $outreachSummaries[] = [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'targeted' => $targeted,
                'replies' => $replies,
                'reply_rate' => $stats['reply_rate'] ?? 0,
                'url' => url('/outreach/'.$campaign->id),
            ];
        }

        $linkedinCount = 0;
        if (in_array($scope, ['linkedin', 'all'], true) && class_exists(V2Campaign::class)) {
            $linkedinCount = V2Campaign::query()
                ->where('organization_id', $orgId)
                ->where('status', '!=', 'template')
                ->count();
        }

        return [
            'scope' => $scope,
            'summary' => [
                'outreach_campaigns' => count($outreachSummaries),
                'linkedin_campaigns' => $linkedinCount,
                'targeted' => $totals['targeted'],
                'contacted_or_running' => $totals['running'] + $totals['done'] + $totals['replies'],
                'replies' => $totals['replies'],
                'done' => $totals['done'],
                'errors' => $totals['errors'],
            ],
            'outreach' => $outreachSummaries,
            'note' => $outreachSummaries === []
                ? 'No outreach campaigns yet. Launch a Command Center plan to create a draft.'
                : 'Open a campaign URL for details. Reply rates are from outreach lead status.',
        ];
    }
}
