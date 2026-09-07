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
use App\Ai\Tools\ListContentPostsTool;
use App\Ai\Tools\PrepareCallManagerLaunchTool;
use App\Ai\Tools\PrepareLinkedInPostTool;
use App\Ai\Tools\RescheduleContentPostsTool;
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
            AiAutonomyLevel::Assisted => 'Autonomy: Assisted. Stage plans with tools; user can say go ahead or LAUNCH to approve pending plans before claiming execution.',
            AiAutonomyLevel::Autopilot => 'Autonomy: Autopilot. Auto-search LinkedIn for audiences, stage plans, and auto-LAUNCH outreach when a list is attached. Auto-run allowlisted execute tools without asking.',
            AiAutonomyLevel::Autonomous => 'Autonomy: Autonomous. Plan and execute end-to-end: discover audience via LinkedIn search, stage campaigns, auto-LAUNCH, and run allowlisted actions. Only pause for missing integrations — never ask for competitor URLs first.',
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
            ."\n1. build_icp only when ICP is truly unclear"
            ."\n2. discover_prospects — checks saved lists, then AUTO-SEARCHES LinkedIn via the connected account (no manual profile URLs needed)"
            ."\n3. propose_strategy / draft_campaign_plan — include list_hash + list_src from discovery (auto-attached when possible)"
            ."\n4. Launch rules by mode:"
            ."\n   • Copilot — no staging, no Review & Launch"
            ."\n   • Assisted — stage plan; user clicks Review & Launch (web) or sends LAUNCH {id} (WhatsApp)"
            ."\n   • Autopilot / Autonomous — stage + auto-launch when audience exists; if LinkedIn disconnected, still stage the plan so Review & Launch shows what's blocked"
            ."\n- Sales goal / 'book N meetings' → ALWAYS call propose_strategy (even without audience yet). Never reply with prose-only when a plan card is expected."
            ."\n- 'Find my ideal customers' / ICP → build_icp, then discover_prospects"
            ."\n- Unified discovery → discover_prospects (auto LinkedIn search fallback)"
            ."\n- Find existing lists only → find_prospects"
            ."\n- Competitor harvest is OPTIONAL fallback only when LinkedIn search returns nothing"
            ."\n- Performance → get_campaign_stats; snapshot → get_sales_brief; weekly → get_weekly_sales_brief; optimize → optimize_campaign (Launch auto-applies wait-time fixes when drop-off detected)"
            ."\n- Qualify a lead → qualify_lead; after a call → post_call_crm_update"
            ."\n- Upcoming call prep → get_meeting_brief"
            ."\n- Who needs attention → get_attention_queue (returns inbox_brief counts); classify → classify_reply; draft reply → draft_reply; meeting-ready → book_meeting"
            ."\n- Personalized outreach copy → draft_personalized_message (evidence-grounded)"
            ."\n- LinkedIn content → list_content_posts to see drafts/schedules; prepare_linkedin_post to create (pass schedule_at, generate_image:true, or use WhatsApp image+caption); reschedule_content_posts to bulk-move schedules (Autopilot+ applies immediately)"
            ."\n- WhatsApp image + caption → user attached an image; call prepare_linkedin_post using their caption (image is stored automatically). Image-only messages are ignored."
            ."\n- Call Manager outreach → prepare_call_manager_launch (loads a list into /calls; LAUNCH queues LinkedIn chats)"
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
            new PrepareLinkedInPostTool($this->context),
            new ListContentPostsTool($this->context),
            new RescheduleContentPostsTool($this->context),
            new PrepareCallManagerLaunchTool($this->context),
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
