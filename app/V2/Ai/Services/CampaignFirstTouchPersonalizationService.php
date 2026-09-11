<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\OpenAIContentService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Evidence-grounded first-touch personalization for AI-created campaigns.
 * Conversation-first: earn a reply with a situational question — no pitch, no links.
 */
class CampaignFirstTouchPersonalizationService
{
    public function __construct(
        private readonly OpenAIContentService $openai,
        private readonly ProspectResearchService $research,
        private readonly WorkspaceContextService $workspaceContext,
    ) {}

    /**
     * Personalize first-touch copy for leads that lack a draft yet.
     *
     * @return array{personalized:int, skipped:int}
     */
    public function personalizeCampaign(V2OutreachCampaign $campaign, int $limit = 40): array
    {
        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        if (empty($meta['ai_personalize_first_touch'])) {
            return ['personalized' => 0, 'skipped' => 0];
        }

        if (! $this->openai->isConfigured()) {
            return ['personalized' => 0, 'skipped' => 0];
        }

        $goal = (string) Arr::get($meta, 'ai_plan.goal', $campaign->name);
        $leads = V2OutreachLead::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->whereNull('meta->ai_personalized_draft')
            ->orderBy('id')
            ->limit(max(1, min(100, $limit)))
            ->get();

        $personalized = 0;
        $skipped = 0;

        foreach ($leads as $lead) {
            try {
                if ($this->personalizeLead($lead, $campaign, $goal)) {
                    $personalized++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                report($e);
                $skipped++;
            }
        }

        $meta['ai_first_touch_personalized_at'] = now()->toIso8601String();
        $meta['ai_first_touch_personalized_count'] = ((int) ($meta['ai_first_touch_personalized_count'] ?? 0)) + $personalized;
        $campaign->forceFill(['meta' => $meta])->save();

        return ['personalized' => $personalized, 'skipped' => $skipped];
    }

    public function personalizeLead(V2OutreachLead $lead, V2OutreachCampaign $campaign, string $goal): bool
    {
        $meta = is_array($lead->meta) ? $lead->meta : [];
        if (! empty($meta['ai_personalized_draft']['text'])) {
            return false;
        }

        $channel = $this->resolveChannel($lead, $campaign);
        $intel = $this->research->researchLead($lead);

        $evidence = array_filter([
            'full_name' => $lead->full_name,
            'headline' => $lead->headline,
            'profile_url' => $lead->profile_url,
            'company' => Arr::get($meta, 'company_name') ?? Arr::get($meta, 'company'),
            'primary_channel' => Arr::get($meta, 'primary_channel'),
            'signals' => implode(', ', Arr::get($intel, 'signals', [])),
            'site_excerpt' => Arr::get($intel, 'scraped.0.excerpt'),
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        if (count($evidence) < 2) {
            return false;
        }

        $owner = User::query()->find((int) $campaign->user_id);
        $orgId = (int) $campaign->organization_id;
        $businessContext = $owner && $orgId > 0
            ? $this->workspaceContext->agentContextBlock($owner, $orgId)
            : '';

        $lines = $this->buildPromptLines($channel, $goal, $businessContext);

        if ($owner) {
            $sender = \App\V2\Ai\Support\SenderIdentity::displayName($owner, $orgId, $campaign);
            if ($sender !== '') {
                $lines[] = 'Sender name (sign as this person): '.$sender;
            }
        }

        foreach ($evidence as $key => $value) {
            $lines[] = Str::headline(str_replace('_', ' ', (string) $key)).': '.$value;
        }

        $draft = trim($this->openai->generateOutreachContent(
            'generate',
            $channel,
            'message',
            'message',
            implode("\n", $lines),
        ));

        if ($draft === '') {
            return false;
        }

        $draft = \App\V2\Ai\Support\RecipientFacingCopyGuard::prepareOutbound($draft, [
            'user' => $owner,
            'organization_id' => $orgId,
            'lead' => $lead,
            'campaign' => $campaign,
        ]);

        $meta['ai_personalized_draft'] = [
            'text' => $draft,
            'evidence' => $evidence,
            'prospect_intelligence' => $intel,
            'channel' => $channel,
            'saved_at' => Carbon::now()->toIso8601String(),
            'source' => 'campaign_first_touch',
        ];
        $lead->forceFill(['meta' => $meta])->save();

        return true;
    }

    /**
     * Prefer personalized first-touch draft over sequence template when available.
     */
    public function resolveMessageText(V2OutreachLead $lead, string $templateText): string
    {
        $draft = trim((string) Arr::get($lead->meta ?? [], 'ai_personalized_draft.text', ''));

        return $draft !== '' ? $draft : $templateText;
    }

    /**
     * Write the first DM from research immediately before send.
     * Returns null when there is not enough evidence yet — caller should defer, not send a generic template.
     */
    public function messageForFirstSend(V2OutreachLead $lead, V2OutreachCampaign $campaign, string $templateText): ?string
    {
        $campaignMeta = is_array($campaign->meta) ? $campaign->meta : [];
        $mustPersonalize = ! empty($campaignMeta['ai_personalize_first_touch'])
            || ! empty($campaignMeta['ai_plan']['personalize_before_send']);

        $existing = trim((string) Arr::get($lead->meta ?? [], 'ai_personalized_draft.text', ''));
        if ($existing !== '') {
            return $existing;
        }

        if (! $mustPersonalize && trim($templateText) !== '') {
            return $templateText;
        }

        $goal = (string) Arr::get($campaignMeta, 'ai_plan.goal', $campaign->name);
        $this->personalizeLead($lead->fresh() ?? $lead, $campaign, $goal);

        $draft = trim((string) Arr::get(($lead->fresh() ?? $lead)->meta ?? [], 'ai_personalized_draft.text', ''));
        if ($draft !== '') {
            return $draft;
        }

        if ($mustPersonalize || $this->isPlaceholderTemplate($templateText)) {
            return null;
        }

        return trim($templateText) !== '' ? $templateText : null;
    }

    /**
     * Personalize non-first-touch follow-ups when configured, so later steps are
     * still context-aware and not generic.
     */
    public function messageForFollowUpSend(V2OutreachLead $lead, V2OutreachCampaign $campaign, array $node, string $templateText): ?string
    {
        $campaignMeta = is_array($campaign->meta) ? $campaign->meta : [];
        $enabled = ! empty($campaignMeta['ai_personalize_first_touch'])
            || ! empty($campaignMeta['ai_plan']['personalize_before_send']);
        if (! $enabled) {
            return trim($templateText) !== '' ? $templateText : null;
        }

        $nodeKey = (string) ($node['key'] ?? '');
        $cacheKey = $nodeKey !== '' ? $nodeKey : md5((string) ($node['label'] ?? 'follow-up'));
        $existing = trim((string) Arr::get($lead->meta ?? [], "ai_follow_up_drafts.{$cacheKey}.text", ''));
        if ($existing !== '') {
            return $existing;
        }

        $intel = $this->research->researchLead($lead);
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $channel = $this->resolveChannel($lead, $campaign);
        $goal = (string) Arr::get($campaignMeta, 'ai_plan.goal', $campaign->name);
        $owner = User::query()->find((int) $campaign->user_id);
        $orgId = (int) $campaign->organization_id;
        $businessContext = $owner && $orgId > 0
            ? $this->workspaceContext->agentContextBlock($owner, $orgId)
            : '';

        $lines = [
            'Write a FOLLOW-UP outreach message (max 280 chars).',
            'Keep tone channel-native and conversational.',
            'Reference earlier outreach naturally, then ask one short context-aware question.',
            'No product pitch, no links, no calendar in this follow-up.',
            'Use only provided evidence.',
            'Background goal (do not pitch it): '.$goal,
            'Prospect name: '.(string) ($lead->full_name ?? ''),
            'Channel: '.$channel,
            'Company: '.(string) (Arr::get($meta, 'company_name') ?? Arr::get($meta, 'company', '')),
            'Signals: '.implode(', ', Arr::get($intel, 'signals', [])),
            'Recent scraped excerpt: '.(string) Arr::get($intel, 'scraped.0.excerpt', ''),
        ];
        if ($businessContext !== '') {
            $lines[] = trim($businessContext);
        }

        $draft = trim($this->openai->generateOutreachContent(
            'generate',
            $channel,
            'message',
            'message',
            implode("\n", $lines),
        ));
        if ($draft === '') {
            return trim($templateText) !== '' ? $templateText : null;
        }

        $draft = \App\V2\Ai\Support\RecipientFacingCopyGuard::prepareOutbound($draft, [
            'user' => $owner,
            'organization_id' => $orgId,
            'lead' => $lead,
            'campaign' => $campaign,
        ]);

        $meta['ai_follow_up_drafts'][$cacheKey] = [
            'text' => $draft,
            'node_key' => $node['key'] ?? null,
            'label' => $node['label'] ?? 'Follow-up',
            'saved_at' => Carbon::now()->toIso8601String(),
        ];
        $lead->forceFill(['meta' => $meta])->save();

        return $draft;
    }

    public function isPlaceholderTemplate(string $templateText): bool
    {
        $text = trim($templateText);

        return $text === ''
            || (bool) preg_match('/thanks for connecting|just checking in|^\{\{firstName\}\}|^hi \{\{firstname\}\}/i', $text);
    }

    private function resolveChannel(V2OutreachLead $lead, V2OutreachCampaign $campaign): string
    {
        $leadMeta = is_array($lead->meta) ? $lead->meta : [];
        $fromLead = Str::lower(trim((string) ($leadMeta['primary_channel'] ?? '')));
        if (in_array($fromLead, OutreachChannelRegistry::sequenceChannelKeys(), true)) {
            return $fromLead;
        }

        $campaignMeta = is_array($campaign->meta) ? $campaign->meta : [];
        $plan = is_array($campaignMeta['ai_plan'] ?? null) ? $campaignMeta['ai_plan'] : [];
        $fromPlan = Str::lower(trim((string) ($plan['primary_channel'] ?? $campaignMeta['primary_channel'] ?? '')));
        if (in_array($fromPlan, OutreachChannelRegistry::sequenceChannelKeys(), true)) {
            return $fromPlan;
        }

        if (! empty($leadMeta['instagram_handle']) && empty($lead->profile_url)) {
            return 'instagram';
        }

        return 'linkedin';
    }

    /**
     * @return list<string>
     */
    private function buildPromptLines(string $channel, string $goal, string $businessContext): array
    {
        $tone = match ($channel) {
            'instagram', 'whatsapp', 'telegram', 'twitter' => 'Conversational DM tone — short, casual, like texting. Not a formal email.',
            'email' => 'Professional but warm email tone — short paragraphs OK.',
            default => 'LinkedIn professional tone — concise and respectful.',
        };

        $lines = [
            'Write a FIRST-TOUCH outreach message (max 320 chars).',
            $tone,
            'CRITICAL: Your only job is to EARN A REPLY — aim for a message they would actually answer.',
            'Use what you know: their role, company, headline, about, and any company site excerpt.',
            'Ask ONE specific question about a situation that role/company would recognize (manual work, disconnected tools, old process).',
            'Do NOT mention our product, do NOT include links, demos, calendars, or "I help businesses…" pitches.',
            'Do not invent facts that are not in the evidence. If company is known, mention it naturally.',
            'Never use placeholders like [Your Name] or {{firstName}}.',
            'Background goal (do not pitch it): '.$goal,
        ];

        if ($businessContext !== '') {
            $lines[] = trim($businessContext);
        }

        return $lines;
    }
}
