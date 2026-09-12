<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ActivateOutreachCampaignTool;
use App\Ai\Tools\AdjustFollowUpTool;
use App\Ai\Tools\AnalyzeCompetitorAudienceTool;
use App\Ai\Tools\BookMeetingTool;
use App\Ai\Tools\UpdateSenderProfileTool;
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
use App\Ai\Tools\GetActivityTool;
use App\Ai\Tools\GetWorkflowRunTool;
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
use App\Ai\Tools\ResearchProspectTool;
use App\Ai\Tools\RescheduleContentPostsTool;
use App\Ai\Tools\SaveContactsTool;
use App\Ai\Tools\SearchProspectsTool;
use App\Ai\Tools\SearchActivityTool;
use App\Ai\Tools\StartAcquisitionExperimentTool;
use App\Ai\Tools\SendInboxReplyTool;
use App\Ai\Tools\ProposeStrategyTool;
use App\Ai\Tools\QualifyLeadTool;
use App\Ai\Tools\SetNextBestActionTool;
use App\Models\AiMessage;
use App\V2\Ai\AgentContext;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\AiChannelPolicyService;
use App\V2\Ai\Services\WorkspaceContextService;
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
        $template = $this->stripPromptRoutingCheatsheet($template);
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
        $workspace = app(WorkspaceContextService::class)->agentContextBlock(
            $this->context->user,
            $this->context->organizationId,
        );

        return str_replace('{employee_name}', $name, $template)
            ."\n\nYou are the SociFusion AI Sales Command Center — one brain for web and WhatsApp."
            ."\n{$extra}"
            ."\n{$surface}"
            ."\nChannels: {$channels}"
            ."\nOrganization ID: {$this->context->organizationId}."
            ."\nPlanning principles:"
            ."\n- Understand the user's full objective, then compose capabilities step-by-step."
            ."\n- Prefer existing data first: search_prospects/get_activity before external discovery when possible."
            ."\n- Use discover_prospects only when net-new external sourcing is needed."
            ."\n- For customer-affecting actions, stage approval-aware plans and keep scope explicit (count/channel/schedule)."
            ."\n- Treat all outbound copy as recipient-facing final text; never output operator instructions."
            ."\n- Report facts from logs and execution state, not assumptions."
            ."\nResearch in Command Center:"
            ."\n- When the user pastes a profile or company URL (LinkedIn, website, etc.), research runs automatically before you reply — summarize the excerpt/signals and suggest a next step."
            ."\n- You can also call research_prospect for deeper persistence on an outreach_lead_id."
            ."\n- After research, use draft_personalized_message to stage reply-first copy for Review & Launch when they want outreach."
            ."\nSafety principles:"
            ."\n- Laravel policy/autonomy/approvals govern side effects; never bypass with prompt logic."
            ."\n- Deletes and destructive operations always require explicit confirmation."
            ."\n- If scope changes materially after approval, request re-approval."
            ."\n- Never claim execution unless execute tools succeeded."
            .$workspace;
    }

    private function stripPromptRoutingCheatsheet(string $template): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $template) ?: [];
        $filtered = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $filtered[] = $line;
                continue;
            }

            if (preg_match('/\b(status|find|discover|launch|delete)\b.*(->|→)/i', $trimmed)) {
                continue;
            }

            $filtered[] = $line;
        }

        return trim(implode("\n", $filtered));
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
            new GetActivityTool($this->context),
            new SearchActivityTool($this->context),
            new GetWorkflowRunTool($this->context),
            new GetNurtureDueQueueTool($this->context),
            new CheckIntegrationsTool($this->context),
            new ConfigureCampaignInboxAiTool($this->context),
            new ImportLeadsCsvTool($this->context),
            new SaveContactsTool($this->context),
            new StartAcquisitionExperimentTool($this->context),
            new DiscoverProspectsTool($this->context),
            new SearchProspectsTool($this->context),
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
            new UpdateSenderProfileTool($this->context),
            new ClassifyReplyTool($this->context),
            new DraftPersonalizedMessageTool($this->context),
            new ResearchProspectTool($this->context),
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
