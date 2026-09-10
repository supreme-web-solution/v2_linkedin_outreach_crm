<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Services\OpenAIContentService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Evidence-grounded first-touch personalization for AI-created campaigns.
 */
class CampaignFirstTouchPersonalizationService
{
    public function __construct(
        private readonly OpenAIContentService $openai,
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

        $evidence = array_filter([
            'full_name' => $lead->full_name,
            'headline' => $lead->headline,
            'profile_url' => $lead->profile_url,
            'company' => Arr::get($meta, 'company_name') ?? Arr::get($meta, 'company'),
            'campaign_name' => $campaign->name,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        if (count($evidence) < 2) {
            return false;
        }

        $lines = [
            'Write a short first-touch outreach message (max 350 chars) grounded ONLY in the evidence.',
            'Do not invent employers, metrics, or prior conversations.',
            'Never use placeholders like [Your Name] or {{firstName}} — use real names from evidence/sender.',
            'Goal: '.$goal,
        ];

        $owner = \App\Models\User::query()->find((int) $campaign->user_id);
        if ($owner) {
            $sender = \App\V2\Ai\Support\SenderIdentity::displayName($owner, (int) $campaign->organization_id, $campaign);
            if ($sender !== '') {
                $lines[] = 'Sender name (sign as this person — user-specified name beats profile name): '.$sender;
            }
        }

        foreach ($evidence as $key => $value) {
            $lines[] = Str::headline(str_replace('_', ' ', (string) $key)).': '.$value;
        }

        $draft = trim($this->openai->generateOutreachContent(
            'generate',
            'linkedin',
            'message',
            'message',
            implode("\n", $lines),
        ));

        if ($draft === '') {
            return false;
        }

        $draft = \App\V2\Ai\Support\RecipientFacingCopyGuard::prepareOutbound($draft, [
            'user' => $owner,
            'organization_id' => (int) $campaign->organization_id,
            'lead' => $lead,
            'campaign' => $campaign,
        ]);

        $meta['ai_personalized_draft'] = [
            'text' => $draft,
            'evidence' => $evidence,
            'channel' => 'linkedin',
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
}
