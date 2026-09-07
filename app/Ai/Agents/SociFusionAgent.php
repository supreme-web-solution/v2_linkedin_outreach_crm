<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ActivateOutreachCampaignTool;
use App\Ai\Tools\AdjustFollowUpTool;
use App\Ai\Tools\AnalyzeCompetitorAudienceTool;
use App\Ai\Tools\BookMeetingTool;
use App\Ai\Tools\BuildIcpTool;
use App\Ai\Tools\ClassifyReplyTool;
use App\Ai\Tools\DiscoverProspectsTool;
use App\Ai\Tools\DraftCampaignPlanTool;
use App\Ai\Tools\DraftPersonalizedMessageTool;
use App\Ai\Tools\DraftReplyTool;
use App\Ai\Tools\FindProspectsTool;
use App\Ai\Tools\GetAttentionQueueTool;
use App\Ai\Tools\GetCampaignStatsTool;
use App\Ai\Tools\GetMeetingBriefTool;
use App\Ai\Tools\GetSalesBriefTool;
use App\Ai\Tools\GetWeeklySalesBriefTool;
use App\Ai\Tools\LetAiExecuteTool;
use App\Ai\Tools\OptimizeCampaignTool;
use App\Ai\Tools\PauseOutreachCampaignTool;
use App\Ai\Tools\PostCallCrmUpdateTool;
use App\Ai\Tools\PrepareCompetitorHarvestTool;
use App\Ai\Tools\PrepareEnrichmentTool;
use App\Ai\Tools\ProposeStrategyTool;
use App\Ai\Tools\QualifyLeadTool;
use App\Ai\Tools\SetNextBestActionTool;
use App\Models\AiMessage;
use App\V2\Ai\AgentContext;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\AiChannelPolicyService;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class SociFusionAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(
        public AgentContext $context,
    ) {}

    public function instructions(): Stringable|string
    {
        $template = (string) config('socifusion_ai.persona');
        $name = $this->context->employeeName();
        $autonomy = $this->context->autonomy();

        $extra = match ($autonomy) {
            AiAutonomyLevel::Copilot => 'Autonomy: Copilot. Recommend only; do not claim actions were executed.',
            AiAutonomyLevel::Assisted => 'Autonomy: Assisted. Stage plans with tools; wait for Launch/Approve before claiming execution.',
            AiAutonomyLevel::Autopilot => 'Autonomy: Autopilot. You may auto-run allowlisted execute tools only.',
            AiAutonomyLevel::Autonomous => 'Autonomy: Autonomous within org rules. Still prefer confirmation for high-impact sends.',
        };

        $surface = $this->context->channel === 'whatsapp'
            ? 'Surface: WhatsApp Command Center. Keep replies short. When a tool returns a "card" string, paste that card almost verbatim and remind them of LAUNCH/REVIEW/REJECT.'
            : 'Surface: Web Command Center. When a tool returns a "card", summarize clearly; the UI also shows Review & Launch buttons.';

        $channels = app(AiChannelPolicyService::class)->agentChannelGuide();

        return str_replace('{employee_name}', $name, $template)
            ."\n\nYou are the SociFusion AI Sales Command Center — one brain for web and WhatsApp."
            ."\n{$extra}"
            ."\n{$surface}"
            ."\nChannels: {$channels}"
            ."\nOrganization ID: {$this->context->organizationId}."
            ."\nTool guide (follow this order for outreach goals):"
            ."\n1. build_icp or clarify ICP when needed"
            ."\n2. discover_prospects or find_prospects — locate a real lead list before staging a campaign"
            ."\n3. propose_strategy / draft_campaign_plan — include list_hash + list_src from discovery"
            ."\n4. Launch only when the plan shows an audience list; never create empty campaigns"
            ."\n- Sales goal / 'get me meetings' → discover_prospects first, then propose_strategy or draft_campaign_plan"
            ."\n- 'Find my ideal customers' / ICP → build_icp"
            ."\n- Unified discovery (lists + competitor audiences) → discover_prospects"
            ."\n- Find existing lists only → find_prospects (pass list_hash + list_src into draft_campaign_plan)"
            ."\n- Competitor audiences → analyze_competitor_audience; harvest new → prepare_competitor_harvest"
            ."\n- Performance → get_campaign_stats; snapshot → get_sales_brief; weekly → get_weekly_sales_brief; optimize → optimize_campaign (Launch auto-applies wait-time fixes when drop-off detected)"
            ."\n- Qualify a lead → qualify_lead; after a call → post_call_crm_update"
            ."\n- Upcoming call prep → get_meeting_brief"
            ."\n- Who needs attention → get_attention_queue (returns inbox_brief counts); classify → classify_reply; draft reply → draft_reply; meeting-ready → book_meeting"
            ."\n- Personalized outreach copy → draft_personalized_message (evidence-grounded)"
            ."\n- Sequence timing → adjust_follow_up with campaign_id"
            ."\n- CRM next step → set_next_best_action on a lead or conversation"
            ."\n- Enrich a list → prepare_enrichment (list_hash + list_src)"
            ."\n- Sales manager batch → let_ai_execute (pause bad campaigns + follow up hot inbox in one Launch)"
            ."\n- Start outreach → activate_outreach_campaign after draft exists"
            ."\nNever invent CRM numbers; use tools. Never say you messaged prospects unless an execute tool succeeded.";
    }

    public function messages(): iterable
    {
        return AiMessage::query()
            ->where('conversation_id', $this->context->conversation->id)
            ->whereIn('role', ['user', 'assistant'])
            ->latest('id')
            ->limit(40)
            ->get()
            ->reverse()
            ->map(fn (AiMessage $message) => new Message($message->role, $message->content))
            ->all();
    }

    public function tools(): iterable
    {
        return [
            new GetCampaignStatsTool($this->context),
            new GetSalesBriefTool($this->context),
            new GetWeeklySalesBriefTool($this->context),
            new GetMeetingBriefTool($this->context),
            new GetAttentionQueueTool($this->context),
            new DiscoverProspectsTool($this->context),
            new FindProspectsTool($this->context),
            new AnalyzeCompetitorAudienceTool($this->context),
            new PrepareCompetitorHarvestTool($this->context),
            new PrepareEnrichmentTool($this->context),
            new ProposeStrategyTool($this->context),
            new BuildIcpTool($this->context),
            new DraftCampaignPlanTool($this->context),
            new DraftReplyTool($this->context),
            new BookMeetingTool($this->context),
            new ClassifyReplyTool($this->context),
            new DraftPersonalizedMessageTool($this->context),
            new AdjustFollowUpTool($this->context),
            new SetNextBestActionTool($this->context),
            new OptimizeCampaignTool($this->context),
            new LetAiExecuteTool($this->context),
            new QualifyLeadTool($this->context),
            new PostCallCrmUpdateTool($this->context),
            new ActivateOutreachCampaignTool($this->context),
            new PauseOutreachCampaignTool($this->context),
        ];
    }
}
