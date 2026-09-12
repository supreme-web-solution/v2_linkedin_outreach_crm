<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Ai\Services\CommandCenterAwarenessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommandCenterAwarenessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_includes_read_thread_still_awaiting_reply(): void
    {
        [$user, $org, $conversation] = $this->fixtures();

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Thank you — I want something tailored https://engr.phanrise.com/',
            'received_at' => now(),
        ]);

        $block = app(CommandCenterAwarenessService::class)->promptBlock($user, $org->id);

        $this->assertStringContainsString('Live workspace awareness', $block);
        $this->assertStringContainsString('vickenconcept@gmail.com', $block);
        $this->assertStringContainsString('read but still awaiting your reply', $block);
        $this->assertStringContainsString('conv #'.$conversation->id, $block);
    }

    /**
     * @return array{0: User, 1: V2Organization, 2: V2Conversation}
     */
    private function fixtures(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Aware Org',
            'slug' => 'aware-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'One-shot email to vickenconcept',
            'status' => 'completed',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'vickenconcept',
            'email' => 'vickenconcept@gmail.com',
            'status' => 'replied',
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_chat_id' => 'vickenconcept@gmail.com',
            'status' => 'active',
            'last_read_at' => now(),
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => $campaign->id,
                'outreach_lead_id' => $lead->id,
                'prospect_name' => 'vickenconcept',
            ],
        ]);

        return [$user, $org, $conversation];
    }
}
