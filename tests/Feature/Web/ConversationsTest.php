<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
    }

    public function test_conversations_index_only_shows_call_manager_threads(): void
    {
        $user = $this->userWithOrg();

        $callManagerThread = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_call_mgr_1',
            'status' => 'active',
            'meta' => [
                'source' => 'call_manager',
                'prospect_name' => 'Call Manager Prospect',
            ],
        ]);

        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'conversation_id' => $callManagerThread->id,
            'prospect_name' => 'Call Manager Prospect',
            'status' => 'engaged',
        ]);

        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_outreach_1',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 42,
            ],
        ]);

        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_outreach_1',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 99,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('conversations'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/Conversations/Index')
            ->has('flows', 1)
            ->where('flows.0.batch_name', 'Individual prospects')
            ->where('flows.0.count', 1)
        );
    }

    public function test_conversation_flow_page_lists_prospects_in_group(): void
    {
        $user = $this->userWithOrg();

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_from_call',
            'status' => 'active',
            'meta' => ['prospect_name' => 'Linked Via Call'],
        ]);

        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'conversation_id' => $conversation->id,
            'prospect_name' => 'Linked Via Call',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('conversations.flow', ['flowKey' => 'individual']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/Conversations/Flow')
            ->has('prospects.data', 1)
            ->where('prospects.data.0.call_id', fn ($id) => $id > 0)
            ->where('prospects.data.0.chat_started', true)
            ->where('flow.batch_name', 'Individual prospects')
        );
    }

    public function test_flow_page_lists_unlinked_pipeline_prospects(): void
    {
        $user = $this->userWithOrg();
        $batchId = 'batch-unlinked-test';

        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Not Started Yet',
            'connection_id' => 'linkedin-profile-123',
            'status' => 'engaged',
            'pending_message' => 'Would you be open to a quick call?',
            'meta' => [
                'batch_id' => $batchId,
                'batch_name' => 'Q2 outreach',
            ],
        ]);

        $response = $this->actingAs($user)->get(route('conversations.flow', ['flowKey' => $batchId]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/Conversations/Flow')
            ->has('prospects.data', 1)
            ->where('prospects.data.0.prospect_name', 'Not Started Yet')
            ->where('prospects.data.0.chat_started', false)
            ->where('flow.count', 1)
            ->where('flow.chats_started', 0)
        );
    }

    public function test_legacy_flow_query_redirects_to_flow_page(): void
    {
        $user = $this->userWithOrg();
        $batchId = 'batch-test-uuid';

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_batch',
            'status' => 'active',
            'meta' => ['prospect_name' => 'Batch Prospect'],
        ]);

        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'conversation_id' => $conversation->id,
            'prospect_name' => 'Batch Prospect',
            'status' => 'engaged',
            'meta' => [
                'batch_id' => $batchId,
                'batch_name' => 'Q2 calls',
            ],
        ]);

        $response = $this->actingAs($user)->get('/conversations?flow='.$batchId);

        $response->assertRedirect(route('conversations.flow', ['flowKey' => $batchId]));
    }

    public function test_conversation_linked_to_call_appears_on_flow_index(): void
    {
        $user = $this->userWithOrg();

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_from_call',
            'status' => 'active',
            'meta' => ['prospect_name' => 'Linked Via Call'],
        ]);

        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'conversation_id' => $conversation->id,
            'prospect_name' => 'Linked Via Call',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('conversations'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stats.prospect_count', 1)
        );
    }

    public function test_outreach_threads_never_appear_on_conversations_page(): void
    {
        $user = $this->userWithOrg();

        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_campaign_only',
            'status' => 'active',
            'meta' => [
                'source' => 'outreach',
                'outreach_campaign_id' => 7,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('conversations'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('stats.prospect_count', 0));
    }

    public function test_conversations_sync_is_disabled(): void
    {
        $user = $this->userWithOrg();

        $response = $this->actingAs($user)->post(route('conversations.sync'));

        $response->assertRedirect(route('conversations'));
        $response->assertSessionHas(
            'success',
            'Inbox sync is disabled. Launch outreach from Call Manager to start a conversation.'
        );
    }

    public function test_conversations_show_redirects_to_linked_call(): void
    {
        $user = $this->userWithOrg();

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_show_call',
            'status' => 'active',
            'meta' => ['source' => 'call_manager'],
        ]);

        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'conversation_id' => $conversation->id,
            'prospect_name' => 'Show Call Prospect',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('conversations.show', $conversation->id));

        $response->assertRedirect(route('calls.show', $call->id));
    }

    public function test_cannot_open_outreach_thread_on_conversations_show(): void
    {
        $user = $this->userWithOrg();

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_outreach_show',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 3,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('conversations.show', $conversation->id));

        $response->assertNotFound();
    }

    public function test_cannot_delete_outreach_thread_via_destroy(): void
    {
        $user = $this->userWithOrg();

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_outreach_destroy',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 3,
            ],
        ]);

        $response = $this->actingAs($user)->delete(route('conversations.destroy', $conversation->id));

        $response->assertNotFound();
        $this->assertDatabaseHas('v2_conversations', ['id' => $conversation->id]);
    }

    public function test_bulk_destroy_skips_outreach_threads(): void
    {
        $user = $this->userWithOrg();

        $callManagerThread = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_cm_bulk',
            'status' => 'active',
            'meta' => ['source' => 'call_manager'],
        ]);

        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'conversation_id' => $callManagerThread->id,
            'prospect_name' => 'Bulk Delete Prospect',
            'status' => 'engaged',
        ]);

        $outreachThread = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_outreach_bulk',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 8,
            ],
        ]);

        $response = $this->actingAs($user)->delete(route('conversations.bulk-destroy'), [
            'ids' => [$callManagerThread->id, $outreachThread->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', '1 conversation(s) removed from CRM.');
        $this->assertDatabaseMissing('v2_conversations', ['id' => $callManagerThread->id]);
        $this->assertDatabaseHas('v2_conversations', ['id' => $outreachThread->id]);
    }

    public function test_destroy_prospect_removes_call_and_conversation(): void
    {
        $user = $this->userWithOrg();

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_delete_prospect',
            'status' => 'active',
            'meta' => ['source' => 'call_manager'],
        ]);

        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'conversation_id' => $conversation->id,
            'prospect_name' => 'Delete Me',
            'status' => 'engaged',
        ]);

        $response = $this->actingAs($user)->delete(route('conversations.prospect.destroy', $call->id));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Prospect and chat removed.');
        $this->assertDatabaseMissing('v2_calls', ['id' => $call->id]);
        $this->assertDatabaseMissing('v2_conversations', ['id' => $conversation->id]);
    }

    public function test_destroy_flow_removes_all_prospects_in_batch(): void
    {
        $user = $this->userWithOrg();
        $batchId = 'batch-delete-flow';

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_delete_flow',
            'status' => 'active',
            'meta' => ['source' => 'call_manager'],
        ]);

        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'conversation_id' => $conversation->id,
            'prospect_name' => 'Flow Prospect',
            'status' => 'engaged',
            'meta' => [
                'batch_id' => $batchId,
                'batch_name' => 'Delete this flow',
            ],
        ]);

        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Another Prospect',
            'status' => 'engaged',
            'meta' => [
                'batch_id' => $batchId,
                'batch_name' => 'Delete this flow',
            ],
        ]);

        $response = $this->actingAs($user)->delete(route('conversations.flow.destroy', ['flowKey' => $batchId]));

        $response->assertRedirect(route('conversations'));
        $response->assertSessionHas('success', '2 prospect(s) and their chats removed.');
        $this->assertDatabaseMissing('v2_calls', ['id' => $call->id]);
        $this->assertDatabaseMissing('v2_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseCount('v2_calls', 0);
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Conversations Org',
            'slug' => 'conversations-org-'.$user->id,
            'status' => 'active',
        ]);

        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['*'],
            'status' => 'active',
        ]);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $user->fresh();
    }
}
