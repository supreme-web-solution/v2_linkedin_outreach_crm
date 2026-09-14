<?php

namespace Tests\Feature\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\BookMeetingCommandCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookMeetingCommandCenterServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
    }

    public function test_stages_booking_with_manual_meeting_link_when_calendar_disconnected(): void
    {
        [$user, $inbox, $commandCenter] = $this->fixtures(
            meetingLink: 'https://calendly.com/socifusion/demo',
        );

        V2Message::query()->create([
            'conversation_id' => $inbox->id,
            'direction' => 'inbound',
            'body' => 'Can we schedule a call next week?',
            'received_at' => now(),
        ]);

        $result = app(BookMeetingCommandCenterService::class)->stage(
            $user,
            (int) $user->current_organization_id,
            $commandCenter,
            $inbox->id,
        );

        $this->assertFalse($result['blocked'] ?? false);
        $this->assertSame('https://calendly.com/socifusion/demo', $result['plan']['booking_url'] ?? null);
        $this->assertSame('manual', $result['plan']['meeting_link_source'] ?? null);
        $this->assertStringContainsString('https://calendly.com/socifusion/demo', (string) ($result['plan']['draft_text'] ?? ''));
        $this->assertNotEmpty($result['approval_id'] ?? null);

        $call = V2Call::query()->find((int) ($result['plan']['call_id'] ?? 0));
        $this->assertNotNull($call);
        $this->assertSame('inbox-bookings-'.$user->id, data_get($call->meta, 'batch_id'));
        $this->assertSame('Inbox bookings', data_get($call->meta, 'batch_name'));
    }

    public function test_blocks_when_no_calendar_and_no_meeting_link(): void
    {
        [$user, $inbox, $commandCenter] = $this->fixtures(meetingLink: null);

        $result = app(BookMeetingCommandCenterService::class)->stage(
            $user,
            (int) $user->current_organization_id,
            $commandCenter,
            $inbox->id,
        );

        $this->assertTrue($result['blocked'] ?? false);
        $this->assertStringContainsString('meeting link', strtolower((string) ($result['message'] ?? '')));
    }

    /**
     * @return array{0: User, 1: V2Conversation, 2: AiConversation}
     */
    private function fixtures(?string $meetingLink): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Booking Org',
            'slug' => 'booking-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        AiEmployeeSetting::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'enabled' => true,
            'kill_switch' => false,
            'autonomy_level' => AiAutonomyLevel::Assisted->value,
            'employee_name' => 'Soci',
            'meta' => $meetingLink ? [
                'conversion_assets' => [
                    'meeting_link' => $meetingLink,
                    'meeting_link_source' => 'manual',
                ],
            ] : [],
        ]);

        $inbox = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'prospect-1',
            'status' => 'active',
            'meta' => ['prospect_name' => 'Jordan Agency'],
        ]);

        $commandCenter = AiConversation::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'command_center',
            'status' => 'open',
            'title' => 'Command Center',
        ]);

        return [$user, $inbox, $commandCenter];
    }
}
