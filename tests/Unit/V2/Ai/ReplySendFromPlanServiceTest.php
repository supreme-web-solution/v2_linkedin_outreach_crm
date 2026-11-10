<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\ReplySendFromPlanService;
use App\V2\Services\InboxConversationResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplySendFromPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_remapped_conversation_by_provider_chat_id_after_stale_id(): void
    {
        [$user, $organization] = $this->orgUser();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'li_acc_reply',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'acc_reply_test'],
        ]);

        $live = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'chat_live_123',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'prospect_name' => 'Alex Prospect',
                'outreach_campaign_id' => 9,
            ],
        ]);

        $approval = AiActionApproval::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'tool' => 'draft_reply',
            'permission' => 'external',
            'status' => 'approved',
            'payload' => [
                'type' => 'draft_reply',
                'conversation_id' => 999999,
                'provider_chat_id' => 'chat_live_123',
                'prospect_name' => 'Alex Prospect',
                'channel' => 'linkedin',
                'draft_text' => 'Thanks Alex — happy to share more.',
                'inbox_url' => url('/inbox/linkedin/999999'),
            ],
        ]);

        $this->mock(\App\V2\Integrations\Unipile\UnipileProvider::class)
            ->shouldReceive('listMessages')
            ->andReturn(['items' => []])
            ->shouldReceive('sendMessage')
            ->once()
            ->andReturn(['id' => 'msg_out_1']);

        $result = app(ReplySendFromPlanService::class)->sendFromApproval($approval, $user);

        $this->assertSame('executed', $approval->fresh()->status);
        $this->assertSame($live->id, (int) data_get($approval->fresh()->result, 'conversation_id'));
        $this->assertSame($live->id, (int) data_get($approval->fresh()->payload, 'conversation_id'));
        $this->assertNotNull(V2Message::query()->where('conversation_id', $live->id)->where('direction', 'outbound')->first());
        $this->assertStringContainsString('Alex', $result['message']);
    }

    public function test_retargets_pending_approvals_when_orphan_conversation_merges(): void
    {
        [$user, $organization] = $this->orgUser();

        $orphan = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => null,
            'lead_id' => null,
            'status' => 'active',
            'meta' => ['source' => 'unified_inbox', 'prospect_name' => 'Orphan'],
        ]);

        $keeper = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'chat_keeper',
            'status' => 'active',
            'meta' => ['source' => 'unified_inbox', 'prospect_name' => 'Orphan'],
        ]);

        $approval = AiActionApproval::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'tool' => 'draft_reply',
            'permission' => 'external',
            'status' => 'pending',
            'payload' => [
                'type' => 'draft_reply',
                'conversation_id' => $orphan->id,
                'prospect_name' => 'Orphan',
                'channel' => 'linkedin',
                'draft_text' => 'Hi again',
                'inbox_url' => url('/inbox/linkedin/'.$orphan->id),
            ],
        ]);

        $updated = app(InboxConversationResolverService::class)
            ->retargetApprovalConversationIds($user->id, $orphan->id, $keeper->id);

        $this->assertSame(1, $updated);
        $this->assertSame($keeper->id, (int) data_get($approval->fresh()->payload, 'conversation_id'));
        $this->assertSame('chat_keeper', data_get($approval->fresh()->payload, 'provider_chat_id'));
    }

    /**
     * @return array{0: User, 1: V2Organization}
     */
    private function orgUser(): array
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Reply Org',
            'slug' => 'reply-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return [$user->fresh(), $organization];
    }
}
