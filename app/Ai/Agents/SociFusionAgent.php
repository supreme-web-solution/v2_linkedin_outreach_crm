<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ActivateOutreachCampaignTool;
use App\Ai\Tools\AdjustFollowUpTool;
use App\Ai\Tools\AnalyzeCompetitorAudienceTool;
use App\Ai\Tools\BookMeetingTool;
use App\Ai\Tools\BuildIcpTool;
use App\Ai\Tools\ClassifyReplyTool;
use App\Ai\Tools\DiscoverProspectsTool;
use App\Ai\Tools\DeleteCampaignTool;
use App\Ai\Tools\DeleteResourceTool;
use App\Ai\Tools\DraftCampaignPlanTool;
use App\Ai\Tools\DraftPersonalizedMessageTool;
use App\Ai\Tools\DraftReplyTool;
use App\Ai\Tools\FindProspectsTool;
use App\Ai\Tools\GetAttentionQueueTool;
use App\Ai\Tools\CheckIntegrationsTool;
use App\Ai\Tools\ConfigureCampaignInboxAiTool;
use App\Ai\Tools\GetNurtureDueQueueTool;
use App\Ai\Tools\ImportLeadsCsvTool;
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
use App\Ai\Tools\MoveLeadToNurtureTool;
use App\Ai\Tools\PrepareCallManagerLaunchTool;
use App\Ai\Tools\PrepareLinkedInPostTool;
use App\Ai\Tools\RescheduleContentPostsTool;
use App\Ai\Tools\SendInboxReplyTool;
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
            ."\n2. discover_prospects — NEW / fetch / N prospects: pass target_count (+ prefer_fresh). Optional filters: geography, network_degree (1st|2nd|3rd / F|S|O), title, company, open_link. Fetches LinkedIn with short ICP keywords, SAVES list_hash. Never reuse engagers unless user names that list. Without target_count: may use strong-matching saved list"
            ."\n3. propose_strategy / draft_campaign_plan — include list_hash + list_src from discovery (and network_depths / first_degree_only when returned). If discovery just saved a list, ALWAYS pass that list_hash"
            ."\n4. Launch rules by mode:"
            ."\n   • Copilot — no staging, no Review & Launch"
            ."\n   • Assisted — stage plan; user clicks Review & Launch (web) or sends LAUNCH {id} (WhatsApp)"
            ."\n   • Autopilot / Autonomous — stage + auto-launch when audience exists; if LinkedIn disconnected, still stage the plan so Review & Launch shows what's blocked"
            ."\n- Sales goal / 'book N meetings' → ALWAYS call propose_strategy (even without audience yet). Never reply with prose-only when a plan card is expected."
            ."\n- 'Find my ideal customers' / ICP → build_icp with website + customers + competitors, Launch, then discover_prospects"
            ."\n- Unified discovery → discover_prospects (pass target_count to fetch+SAVE net-new LinkedIn profiles; without it may reuse a strong-matching saved list only)"
            ."\n- Find existing lists only → find_prospects"
            ."\n- Competitor harvest is OPTIONAL fallback only when LinkedIn search returns nothing"
            ."\n- Sales manager batch → let_ai_execute (follow-ups + nurture + pause + scale + activate + channel mix in one Launch)"
            ."\n- Campaign drafts from Alex personalize first-touch copy on Launch/sync when evidence exists"
            ."\n- Performance → get_campaign_stats; snapshot → get_sales_brief; weekly → get_weekly_sales_brief; optimize → optimize_campaign (Launch auto-applies wait-time fixes when drop-off detected)"
            ."\n- Qualify a lead → qualify_lead; after a call → post_call_crm_update"
            ."\n- Upcoming call prep → get_meeting_brief"
            ."\n- Who needs attention → get_attention_queue (returns inbox_brief counts); classify → classify_reply; draft reply → draft_reply (Autopilot+ auto-sends); send now → send_inbox_reply; meeting-ready → book_meeting"
            ."\n- Maybe later / not now → move_lead_to_nurture (90-day default pause on outreach); due follow-ups → get_nurture_due_queue"
            ."\n- Integrations → check_integrations before Launch; Launch is blocked until required channels are connected"
            ."\n- Campaign inbox AI → configure_campaign_inbox_ai (pause_on_reply default ON; optional auto_reply + AI context per channel). Replies are handled in inbox — not as sequence action nodes"
            ."\n- Import contacts → import_leads_csv (stage CSV → user Launch → attach list_hash to next campaign)"
            ."\n- Personalized outreach copy → draft_personalized_message (evidence-grounded)"
            ."\n- LinkedIn content → list_content_posts to see drafts/schedules; prepare_linkedin_post to create (pass schedule_at, generate_image:true, or use WhatsApp image+caption); reschedule_content_posts to bulk-move schedules (Autopilot+ applies immediately)"
            ."\n- WhatsApp image + caption → user attached an image; call prepare_linkedin_post using their caption (image is stored automatically). Image-only messages are ignored."
            ."\n- Instagram DM campaign → draft_campaign_plan with channels \"Instagram\" or \"LinkedIn + Instagram\"; prepare_enrichment for @handles; Launch creates instagram_only or social_dm sequence"
            ."\n- Telegram campaign → draft_campaign_plan with channels \"Telegram\" or \"LinkedIn + Telegram\"; prepare_enrichment for phone/@handle; Launch creates telegram_only or linkedin_telegram sequence"
            ."\n- WhatsApp prospect outreach → draft_campaign_plan with channels \"WhatsApp\" (not Zernio Command Center); same Review & Launch flow"
            ."\n- Call Manager outreach → prepare_call_manager_launch (loads a list into /calls; LAUNCH queues LinkedIn chats)"
            ."\n- Sequence timing → adjust_follow_up with campaign_id"
            ."\n- CRM next step → set_next_best_action on a lead or conversation"
            ."\n- Enrich a list → prepare_enrichment (list_hash + list_src)"
            ."\n- Start outreach → activate_outreach_campaign after draft exists"
            ."\n- Delete something → delete_resource (kind=outreach|linkedin|lead_list|content_post|inbox_conversation|outreach_template). Campaigns can also use delete_campaign. ALWAYS stages Confirm Delete — never auto-deletes at any autonomy level. lead_list needs list_src; inbox_conversation needs platform"
            ."\n- Plan sequences thoughtfully per goal (Laravel AI decides; Launch builds nodes). Action keys: LinkedIn send_invite|send_message|visit_profile|like_post|endorse; Email send_email; other channels send_message. Conditions: LinkedIn invite_accepted|has_replied|no_reply; Email email_replied|no_reply|email_opened|email_bounced; messaging channels message_replied|no_reply"
            ."\n- LinkedIn: after send_invite ALWAYS use invite_accepted before DMs (accepted = messages; not_accepted = email/WA if those channels are planned). Never a second invite. Empty invite notes for volume unless user wants noted invites (~5/day)"
            ."\n- 1st-degree / already-connected lists: NO send_invite — plan LinkedIn DMs (+ pause_on_reply). 2nd/3rd+ or mixed: invites OK. Discover returns first_degree_only / campaign_hint — honor them"
            ."\n- Replies: default pause_on_reply — sequence stops so you reply in inbox chat context. Use has_replied/no_reply/message_replied ONLY when the graph must branch (bump if silent vs alternate path). Do not add an \"Alex reply\" sequence step"
            ."\n- Email in a campaign sequence: enrichment auto-runs in waves of 25 and respects the daily enrichment cap; leftovers continue the next day. Prefer prepare_enrichment only when the user asks to enrich a list before a campaign exists"
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
            new GetNurtureDueQueueTool($this->context),
            new CheckIntegrationsTool($this->context),
            new ConfigureCampaignInboxAiTool($this->context),
            new ImportLeadsCsvTool($this->context),
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
            new SendInboxReplyTool($this->context),
            new MoveLeadToNurtureTool($this->context),
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
            new DeleteCampaignTool($this->context),
            new DeleteResourceTool($this->context),
        ];
    }
}
