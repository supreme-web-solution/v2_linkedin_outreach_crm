<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Ai\Services\CampaignFirstTouchPersonalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignFirstTouchPersonalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_message_text_prefers_personalized_draft(): void
    {
        $user = User::factory()->create();
        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => null,
            'name' => 'Pers campaign',
            'status' => 'draft',
            'node_model' => [],
            'meta' => ['ai_personalize_first_touch' => true],
        ]);
        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Ada Lovelace',
            'status' => 'pending',
            'meta' => [
                'ai_personalized_draft' => [
                    'text' => 'Ada — loved your work on analytical engines.',
                ],
            ],
        ]);

        $text = app(CampaignFirstTouchPersonalizationService::class)
            ->resolveMessageText($lead, 'Hi {{firstName}}, quick note.');

        $this->assertSame('Ada — loved your work on analytical engines.', $text);
    }

    public function test_requires_personalization_from_campaign_meta_or_node(): void
    {
        $user = User::factory()->create();
        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => null,
            'name' => 'Soci campaign',
            'status' => 'draft',
            'node_model' => [],
            'meta' => ['ai_personalize_first_touch' => true],
        ]);

        $service = app(CampaignFirstTouchPersonalizationService::class);

        $this->assertTrue($service->requiresPersonalizationBeforeSend($campaign, []));
        $this->assertTrue($service->requiresPersonalizationBeforeSend($campaign, [
            'config' => ['personalize_before_send' => false],
        ]));

        $manual = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => null,
            'name' => 'Manual template',
            'status' => 'draft',
            'node_model' => [],
            'meta' => [],
        ]);

        $this->assertFalse($service->requiresPersonalizationBeforeSend($manual, []));
        $this->assertTrue($service->requiresPersonalizationBeforeSend($manual, [
            'config' => ['personalize_before_send' => true],
        ]));
    }

    public function test_defer_for_personalization_uses_short_retry(): void
    {
        $result = app(CampaignFirstTouchPersonalizationService::class)
            ->deferForPersonalizationResult();

        $this->assertSame('deferred', $result['status']);
        $this->assertSame('awaiting_personalization', $result['payload']['reason']);
        $this->assertTrue($result['next_run_at']->greaterThan(now()->addMinutes(4)));
        $this->assertTrue($result['next_run_at']->lessThan(now()->addMinutes(16)));
    }
}
