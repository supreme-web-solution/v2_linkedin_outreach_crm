<?php

namespace App\Ai\Tools;

use App\Models\V2OutreachCampaign;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ActionApprovalService;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\OutreachChannelInboxSettingsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ConfigureCampaignInboxAiTool extends GatedTool
{
    public function toolName(): string
    {
        return 'configure_campaign_inbox_ai';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Stage per-campaign inbox AI settings: auto-reply toggle, AI context, pause-on-reply. Requires Review & Launch.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->required(),
            'channel' => $schema->string()->nullable()->description('Inbox channel key, e.g. linkedin, email, whatsapp'),
            'ai_context' => $schema->string()->nullable()->description('Extra instructions for AI replies on this campaign'),
            'auto_reply_enabled' => $schema->boolean()->nullable(),
            'pause_on_reply' => $schema->boolean()->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $campaignId = (int) $request['campaign_id'];
        $campaign = V2OutreachCampaign::query()
            ->where('id', $campaignId)
            ->where('user_id', $this->context->user->id)
            ->first();

        if (! $campaign) {
            throw new \InvalidArgumentException('Campaign not found or not owned by this user.');
        }

        $inboxSettings = app(OutreachChannelInboxSettingsService::class);
        $channel = trim((string) ($request['channel'] ?? ''));
        if ($channel === '') {
            $channels = $inboxSettings->inboxChannelsForCampaign($campaign);
            $channel = $channels[0] ?? 'linkedin';
        }
        $inboxSettings->assertInboxChannel($channel);

        $current = $inboxSettings->forCampaignChannel($campaign, $channel);
        $next = $inboxSettings->normalizeChannelSettings([
            'ai_context' => $request['ai_context'] ?? $current['ai_context'],
            'auto_reply_enabled' => isset($request['auto_reply_enabled'])
                ? (bool) $request['auto_reply_enabled']
                : $current['auto_reply_enabled'],
            'pause_on_reply' => isset($request['pause_on_reply'])
                ? (bool) $request['pause_on_reply']
                : $current['pause_on_reply'],
        ]);

        $plan = [
            'type' => 'campaign_inbox_ai',
            'goal' => 'Configure inbox AI for '.$campaign->name,
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'channel' => $channel,
            'channel_label' => OutreachChannelRegistry::channelLabel($channel),
            'ai_context' => $next['ai_context'],
            'auto_reply_enabled' => $next['auto_reply_enabled'],
            'pause_on_reply' => $next['pause_on_reply'],
            'steps' => [
                'Apply AI context to '.$campaign->name.' · '.OutreachChannelRegistry::channelLabel($channel),
                $next['auto_reply_enabled'] ? 'Enable auto-reply on inbound messages' : 'Keep auto-reply off (draft only)',
                $next['pause_on_reply'] ? 'Pause outreach sequence when they reply' : 'Keep sequence running after reply',
            ],
            'status' => 'awaiting_review',
        ];

        $formatter = app(CommandCenterService::class);

        if ($this->context->autonomy()->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval_id' => null,
                'plan' => $plan,
                'card' => $formatter->formatPlanCard($plan, null, $this->context->channel),
                'cta' => 'Copilot mode: recommendation only.',
            ];
        }

        $approval = app(ActionApprovalService::class)->createPending(
            $this->context->user,
            $this->context->organizationId,
            $this->toolName(),
            $this->permission(),
            $plan,
            $this->context->conversation,
        );

        return [
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $formatter->formatPlanCard($plan, $approval->id, $this->context->channel),
            'cta' => 'User should Review & Launch to save inbox AI settings.',
        ];
    }
}
