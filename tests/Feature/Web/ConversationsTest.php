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
            ->component('crm/Conversations')
            ->has('conversations.data', 1)
            ->where('conversations.data.0.id', $callManagerThread->id)
            ->where('conversations.data.0.prospect_name', 'Call Manager Prospect')
        );
    }

    public function test_conversation_linked_to_call_appears_without_call_manager_meta(): void
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
            ->has('conversations.data', 1)
            ->where('conversations.data.0.id', $conversation->id)
            ->where('conversations.data.0.call_id', fn ($id) => $id > 0)
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
        $response->assertInertia(fn ($page) => $page->has('conversations.data', 0));
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
