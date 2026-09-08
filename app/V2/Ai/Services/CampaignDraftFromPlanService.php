<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachList;
use App\V2\Ai\Support\PlanLeadList;
use Illuminate\Support\Str;

class CampaignDraftFromPlanService
{
    public function __construct(
        private readonly ProspectAudienceResolverService $audienceResolver,
    ) {}

    /**
     * Create a draft outreach campaign from an approved Command Center plan.
     * Does not auto-activate (needs lead lists + user confirmation for sends).
     *
     * @return array{campaign:V2OutreachCampaign, attached_lists:int, template_type:string, url:string}
     */
    public function createFromApproval(AiActionApproval $approval, User $user): array
    {
        $existingId = data_get($approval->result, 'outreach_campaign_id');
        if ($existingId) {
            $existing = V2OutreachCampaign::query()->find($existingId);
            if ($existing) {
                return [
                    'campaign' => $existing,
                    'attached_lists' => (int) data_get($approval->result, 'attached_lists', 0),
                    'template_type' => (string) data_get($approval->result, 'template_type', $existing->template_type),
                    'url' => url('/outreach/'.$existing->id),
                ];
            }
        }

        $payload = $approval->payload ?? [];
        $orgId = (int) $approval->organization_id;

        $audience = $this->audienceResolver->resolve($user, $payload, strict: true);
        if ($audience === null) {
            throw new MissingProspectAudienceException(
                'No prospect list is attached to this plan.',
                $this->audienceResolver->nextSteps($payload),
            );
        }

        $payload = PlanLeadList::merge(
            $payload,
            $audience['list_hash'],
            $audience['list_src'],
            $audience['list_name'],
        );

        $resolved = app(PlanSequenceNodeBuilder::class)->resolve($payload);
        $templateType = $resolved['template_type'];
        $nodeModel = $resolved['node_model'];

        $goal = (string) ($payload['goal'] ?? 'AI Command Center campaign');
        $name = Str::limit('AI: '.$goal, 180, '');

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $orgId,
            'name' => $name,
            'template_type' => $templateType,
            'node_model' => $nodeModel,
            'status' => 'draft',
            'meta' => [
                'source' => 'socifusion_ai',
                'ai_approval_id' => $approval->id,
                'ai_plan' => $payload,
                'created_via' => 'command_center',
                'ai_personalize_first_touch' => true,
                'ai_custom_sequence' => $resolved['custom'],
            ],
        ]);

        $attached = 0;
        $this->attachList(
            $campaign,
            $audience['list_hash'],
            $audience['list_src'],
            $audience['list_name'],
        );
        $attached = 1;

        $approval->update([
            'payload' => $payload,
            'result' => [
                'outreach_campaign_id' => $campaign->id,
                'template_type' => $templateType,
                'attached_lists' => $attached,
                'audience_list' => $audience['list_name'],
                'audience_leads' => $audience['total_leads'],
                'status' => 'draft_created',
            ],
            'status' => 'executed',
        ]);

        return [
            'campaign' => $campaign,
            'attached_lists' => $attached,
            'template_type' => $templateType,
            'url' => url('/outreach/'.$campaign->id),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveTemplateType(array $payload): string
    {
        $channels = Str::lower((string) ($payload['preferred_channels'] ?? $payload['channels'] ?? ''));
        $policy = app(AiChannelPolicyService::class);
        $mentioned = $policy->mentionedInText($channels !== '' ? $channels : $policy->defaultChannelsLabel());

        $has = fn (string $key): bool => in_array($key, $mentioned, true);

        if ($has('instagram') && $has('linkedin')) {
            return 'social_dm';
        }

        if ($has('instagram') && ! $has('linkedin')) {
            return 'instagram_only';
        }

        if ($has('telegram') && $has('linkedin')) {
            return 'linkedin_telegram';
        }

        if ($has('telegram') && ! $has('linkedin')) {
            return 'telegram_only';
        }

        if ($has('whatsapp') && $has('linkedin') && ! $has('email')) {
            return 'linkedin_whatsapp';
        }

        if ($has('whatsapp') && ($has('email') || $has('linkedin'))) {
            return 'multichannel';
        }

        if ($has('whatsapp') && ! $has('email') && ! $has('linkedin')) {
            return 'whatsapp_only';
        }

        if ($has('email') && ! $has('linkedin')) {
            return 'email_only';
        }

        if ($has('linkedin') && $has('email')) {
            return 'linkedin_email';
        }

        if ($has('linkedin')) {
            return 'linkedin_only';
        }

        return 'linkedin_email';
    }

    private function attachList(V2OutreachCampaign $campaign, string $listHash, string $listSrc, ?string $listName): void
    {
        V2OutreachList::query()->firstOrCreate(
            [
                'outreach_campaign_id' => $campaign->id,
                'list_hash' => $listHash,
                'list_src' => $listSrc,
            ],
            ['list_name' => $listName]
        );
    }
}
