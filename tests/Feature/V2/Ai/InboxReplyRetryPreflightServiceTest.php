<?php

namespace Tests\Feature\V2\Ai;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\InboxReplyRetryPreflightService;
use App\V2\Ai\Services\UserTurnIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxReplyRetryPreflightServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_try_again_relaunches_failed_draft_reply_approval(): void
    {
        [$user, $organization, $approval, $v2Conversation] = $this->failedDraftReplyFixture();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'li_retry_acc',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'li_retry_acc'],
        ]);

        $this->mock(\App\V2\Integrations\Unipile\UnipileProvider::class)
            ->shouldReceive('listChats')
            ->once()
            ->andReturn([
                'items' => [[
                    'id' => 'li_chat_retry',
                    'attendees' => [['provider_id' => 'ACoAAnancy']],
                ]],
            ])
            ->shouldReceive('sendMessage')
            ->once()
            ->andReturn(['id' => 'msg_retry_1']);

        $aiConversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'title' => 'Retry test',
        ]);

        $result = app(InboxReplyRetryPreflightService::class)->tryHandle(
            $user,
            $organization->id,
            $aiConversation,
            'try it again',
        );

        $this->assertTrue($result['handled'] ?? false);
        $this->assertStringContainsString('Launched plan #'.$approval->id, (string) ($result['reply'] ?? ''));
        $this->assertSame('executed', $approval->fresh()->status);
        $this->assertSame('li_chat_retry', $v2Conversation->fresh()->provider_chat_id);
    }

    public function test_resend_selection_matches_prospect_name(): void
    {
        [$user, $organization, $approval] = $this->failedDraftReplyFixture();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'li_select_acc',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'li_select_acc'],
        ]);

        $this->mock(\App\V2\Integrations\Unipile\UnipileProvider::class)
            ->shouldReceive('listChats')
            ->once()
            ->andReturn([
                'items' => [[
                    'id' => 'li_chat_select',
                    'attendees' => [['provider_id' => 'ACoAAnancy']],
                ]],
            ])
            ->shouldReceive('sendMessage')
            ->once()
            ->andReturn(['id' => 'msg_select_1']);

        $aiConversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'title' => 'Selection test',
        ]);

        $result = app(InboxReplyRetryPreflightService::class)->tryHandle(
            $user,
            $organization->id,
            $aiConversation,
            'Nancy Ifeoma Ozoume — "Brief me more on this!"',
        );

        $this->assertTrue($result['handled'] ?? false);
        $this->assertSame('executed', $approval->fresh()->status);
    }

    public function test_retry_intent_patterns(): void
    {
        $intent = app(UserTurnIntentService::class);

        $this->assertTrue($intent->isInboxReplyRetryRequest('try it again'));
        $this->assertTrue($intent->isInboxReplyRetryRequest('it has been enabled send again'));
        $this->assertTrue($intent->isInboxResendSelection('Nancy Ifeoma Ozoume — "Brief me more on this!"'));
    }

    /**
     * @return array{0: User, 1: V2Organization, 2: AiActionApproval, 3: V2Conversation}
     */
    private function failedDraftReplyFixture(): array
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Retry Org',
            'slug' => 'retry-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $organization->id])->save();

        $v2Conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => null,
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'prospect_name' => 'Nancy Ifeoma Ozoume',
                'attendee_ids' => ['ACoAAnancy'],
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
                'conversation_id' => $v2Conversation->id,
                'prospect_name' => 'Nancy Ifeoma Ozoume',
                'channel' => 'linkedin',
                'channel_label' => 'LinkedIn',
                'draft_text' => 'Hi Nancy, thanks for reaching out.',
                'inbound_preview' => 'Brief me more on this!',
                'inbox_url' => url('/inbox/linkedin/'.$v2Conversation->id),
            ],
            'result' => [],
        ]);

        return [$user->fresh(), $organization, $approval, $v2Conversation];
    }
}
