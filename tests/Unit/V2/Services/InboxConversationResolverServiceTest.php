<?php

namespace Tests\Unit\V2\Services;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Services\InboxConversationResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxConversationResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_conversation_by_email_after_thread_was_read(): void
    {
        $user = User::factory()->create();
        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => 1,
            'name' => 'Email',
            'status' => 'completed',
            'node_model' => [],
        ]);
        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'William Victor',
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

        $found = app(InboxConversationResolverService::class)->findForUser(
            $user,
            email: 'vickenconcept@gmail.com',
        );

        $this->assertNotNull($found);
        $this->assertSame($conversation->id, $found->id);
    }
}
