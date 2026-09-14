<?php

namespace Tests\Feature\V2\Ai;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Services\ProspectMemoryService;
use App\V2\Services\UnifiedInboxReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InboxConversionCopyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
        config()->set('services.openai.api_key', 'test-key');
    }

    public function test_tell_me_more_draft_appends_sales_page_when_model_omits_it(): void
    {
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'Happy to share more about how we work with agencies.']]]], 200),
        ]);

        [$user, $conversation] = $this->fixtures([
            'sales_page_url' => 'https://socifusion.com/sales',
            'webinar_url' => 'https://socifusion.com/webinar',
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Tell me more about how this would work for our agency.',
            'received_at' => now(),
        ]);

        $draft = app(UnifiedInboxReplyService::class)->draftReplyForConversation($user, $conversation);

        $this->assertSame('share_sales_page', $draft['conversion_action']);
        $this->assertStringContainsString('https://socifusion.com/sales', $draft['draft']);
        $this->assertStringNotContainsString('https://socifusion.com/webinar', $draft['draft']);
    }

    public function test_qualifying_answer_strips_conversion_links(): void
    {
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => "Got it — mostly referrals. Here's our page: https://socifusion.com/sales"]]]], 200),
        ]);

        [$user, $conversation] = $this->fixtures([
            'sales_page_url' => 'https://socifusion.com/sales',
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Mostly referrals right now.',
            'received_at' => now(),
        ]);

        $draft = app(UnifiedInboxReplyService::class)->draftReplyForConversation($user, $conversation);

        $this->assertSame('qualify', $draft['conversion_action']);
        $this->assertStringNotContainsString('https://socifusion.com/sales', $draft['draft']);
    }

    /**
     * @param  array<string, string>  $assets
     * @return array{0: User, 1: V2Conversation}
     */
    private function fixtures(array $assets): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Copy Org',
            'slug' => 'copy-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        app(AiEmployeeSettingsService::class)->updateForUser(
            $user,
            $org->id,
            ['autonomy_level' => AiAutonomyLevel::Assisted->value],
            allowAutonomous: false,
        );

        $settings = AiEmployeeSetting::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $org->id)
            ->first();
        $this->assertNotNull($settings);
        $settings->forceFill([
            'meta' => ['conversion_assets' => $assets],
        ])->save();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Inbox',
            'status' => 'running',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Alex Agency',
            'status' => 'replied',
            'meta' => [],
        ]);

        app(ProspectMemoryService::class)->setConversionStage($lead, 'qualifying');

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'alex-agency',
            'status' => 'active',
            'meta' => [
                'outreach_lead_id' => $lead->id,
                'outreach_campaign_id' => $campaign->id,
                'prospect_name' => 'Alex Agency',
            ],
        ]);

        return [$user, $conversation];
    }
}
