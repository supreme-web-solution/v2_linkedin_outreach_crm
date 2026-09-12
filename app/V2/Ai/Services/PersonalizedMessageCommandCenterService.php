<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\OpenAIContentService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PersonalizedMessageCommandCenterService
{
    public function __construct(
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly OpenAIContentService $openai,
        private readonly ProspectResearchService $prospectResearch,
    ) {}

    /**
     * @return array{
     *     approval: AiActionApproval|null,
     *     plan: array<string,mixed>,
     *     card: string,
     *     approval_id: int|null,
     *     blocked?: bool,
     *     message?: string
     * }
     */
    public function stageDraft(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        ?int $outreachLeadId,
        ?int $v2ConversationId,
        string $channel,
        string $action,
        string $goal,
        ?string $notes,
        string $surface = 'web',
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);

        if ($this->settingsService->isBlocked($settings)) {
            return [
                'approval' => null,
                'approval_id' => null,
                'plan' => [],
                'card' => '',
                'blocked' => true,
                'message' => 'AI Employee is currently disabled for this workspace.',
            ];
        }

        [$lead, $v2Conversation, $campaign] = $this->resolveLeadContext(
            $user,
            $outreachLeadId,
            $v2ConversationId,
        );

        if (! $lead) {
            throw new \RuntimeException('Outreach lead not found. Pass outreach_lead_id or a Unified Inbox conversation_id.');
        }

        if (! $this->openai->isConfigured()) {
            throw new \RuntimeException('OpenAI is not configured for personalized message drafting.');
        }

        $intel = $this->prospectResearch->researchLead($lead);
        $evidence = $this->buildEvidence($lead, $campaign, $v2Conversation, $intel);
        $context = $this->buildPromptContext($goal, $evidence, $notes);
        $draft = trim($this->openai->generateOutreachContent(
            'generate',
            $channel,
            $action,
            'message',
            $context,
        ));

        if ($draft === '') {
            throw new \RuntimeException('Could not generate personalized message.');
        }

        $prospectName = trim((string) ($lead->full_name ?? 'Prospect'));

        $plan = [
            'type' => 'personalized_message',
            'goal' => $goal !== '' ? $goal : 'Personalized outreach for '.$prospectName,
            'outreach_lead_id' => $lead->id,
            'conversation_id' => $v2Conversation?->id,
            'prospect_name' => $prospectName,
            'channel' => $channel,
            'channel_label' => OutreachChannelRegistry::channelLabel($channel),
            'action' => $action,
            'draft_text' => $draft,
            'evidence' => $evidence,
            'agent_notes' => trim((string) $notes) !== '' ? trim((string) $notes) : null,
            'outreach_url' => $campaign
                ? url('/outreach/'.$campaign->id)
                : url('/outreach'),
            'status' => 'awaiting_review',
        ];

        $autonomy = AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
        $card = $this->commandCenter->formatPlanCard($plan, null, $surface);

        if ($autonomy->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval' => null,
                'approval_id' => null,
                'plan' => $plan,
                'card' => $card,
                'message' => 'Copilot mode: draft shown for review only.',
            ];
        }

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'draft_personalized_message',
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        $card = $this->commandCenter->formatPlanCard($plan, $approval->id, $surface);

        return [
            'approval' => $approval,
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $card,
        ];
    }

    /**
     * @return array{0: V2OutreachLead|null, 1: V2Conversation|null, 2: V2OutreachCampaign|null}
     */
    private function resolveLeadContext(User $user, ?int $outreachLeadId, ?int $v2ConversationId): array
    {
        $lead = null;
        $v2Conversation = null;
        $campaign = null;

        if ($outreachLeadId) {
            $lead = V2OutreachLead::query()->find($outreachLeadId);
            if ($lead) {
                $campaign = V2OutreachCampaign::query()
                    ->where('user_id', $user->id)
                    ->whereKey($lead->outreach_campaign_id)
                    ->first();
            }
        }

        if ($v2ConversationId) {
            $v2Conversation = V2Conversation::query()
                ->where('user_id', $user->id)
                ->whereKey($v2ConversationId)
                ->first();

            if ($v2Conversation && ! $lead) {
                $meta = is_array($v2Conversation->meta) ? $v2Conversation->meta : [];
                $leadId = (int) (Arr::get($meta, 'outreach_lead_id') ?? 0);
                if ($leadId > 0) {
                    $lead = V2OutreachLead::query()->find($leadId);
                }
            }
        }

        if ($lead && ! $campaign) {
            $campaign = V2OutreachCampaign::query()
                ->where('user_id', $user->id)
                ->whereKey($lead->outreach_campaign_id)
                ->first();
        }

        if ($lead && ! $campaign) {
            throw new \RuntimeException('Outreach lead not found.');
        }

        return [$lead, $v2Conversation, $campaign];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $intel
     * @return array<string, mixed>
     */
    private function buildEvidence(
        V2OutreachLead $lead,
        ?V2OutreachCampaign $campaign,
        ?V2Conversation $conversation,
        array $intel = [],
    ): array {
        $inboundPreview = null;

        if ($conversation) {
            $latestInbound = V2Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->orderByDesc('received_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $body = trim((string) ($latestInbound?->body ?? ''));
            if ($body !== '') {
                $inboundPreview = Str::limit($body, 200, '…');
            }
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $scraped = is_array($intel['scraped'] ?? null) ? $intel['scraped'] : [];
        $siteExcerpt = trim((string) Arr::get($scraped, '0.excerpt', ''));

        return array_filter([
            'full_name' => $lead->full_name,
            'headline' => $lead->headline,
            'profile_url' => $lead->profile_url,
            'company' => Arr::get($meta, 'company_name') ?? Arr::get($meta, 'company'),
            'signals' => implode(', ', is_array($intel['signals'] ?? null) ? $intel['signals'] : []),
            'site_excerpt' => $siteExcerpt !== '' ? Str::limit($siteExcerpt, 500) : null,
            'campaign_name' => $campaign?->name,
            'last_inbound' => $inboundPreview,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function buildPromptContext(string $goal, array $evidence, ?string $notes): string
    {
        $lines = ['Write a personalized outreach message grounded ONLY in the evidence below. Do not invent employers, metrics, or prior conversations not listed.'];

        if ($goal !== '') {
            $lines[] = 'Goal: '.$goal;
        }

        foreach ($evidence as $key => $value) {
            $label = Str::headline(str_replace('_', ' ', (string) $key));
            $lines[] = "{$label}: {$value}";
        }

        $noteText = trim((string) $notes);
        if ($noteText !== '') {
            $lines[] = 'Additional guidance: '.$noteText;
        }

        return implode("\n", $lines);
    }
}
