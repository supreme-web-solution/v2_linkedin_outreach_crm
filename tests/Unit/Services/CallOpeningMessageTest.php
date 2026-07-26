<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2IntegrationAccount;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Services\CallOrchestrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallOpeningMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_opening_message_is_not_appended_with_booking_link(): void
    {
        [$user, $call] = $this->callWithBooking('ssaas', 'booking-token-123');

        $service = app(CallOrchestrationService::class);
        $updated = $service->ensurePendingMessageHasBookingLink($call, $user);

        $this->assertSame('ssaas', $updated->pending_message);
    }

    public function test_booking_message_template_replaces_calendar_placeholder_only(): void
    {
        [$user, $call] = $this->callWithBooking(
            'Would you be open to a call? {calendar_url}',
            'booking-token-456',
        );

        $service = app(CallOrchestrationService::class);
        $updated = $service->ensurePendingMessageHasBookingLink($call, $user);

        $this->assertSame(
            'Would you be open to a call? '.url('/book/booking-token-456'),
            $updated->pending_message,
        );
    }

    public function test_ai_suggested_reply_does_not_append_booking_link(): void
    {
        $user = $this->userWithCalendar();
        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jane Doe',
            'status' => 'scheduling',
            'meta' => ['booking_token' => 'ai-token-789'],
        ]);

        $service = app(CallOrchestrationService::class);
        $reply = $service->buildSuggestedReply(
            [
                'suggested_response' => 'Great — happy to find a time that works.',
                'next_action' => 'send_calendar',
            ],
            $user->call_settings,
            'Jane',
            $call,
            $user,
        );

        $this->assertSame('Great — happy to find a time that works.', $reply);
        $this->assertStringNotContainsString('/book/', $reply);
    }

    public function test_ai_suggested_reply_replaces_calendar_placeholder_when_present(): void
    {
        $user = $this->userWithCalendar();
        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jane Doe',
            'status' => 'scheduling',
            'meta' => ['booking_token' => 'ai-token-abc'],
        ]);

        $service = app(CallOrchestrationService::class);
        $reply = $service->buildSuggestedReply(
            [
                'suggested_response' => 'Book here: {calendar_url}',
                'next_action' => 'send_calendar',
            ],
            $user->call_settings,
            'Jane',
            $call,
            $user,
        );

        $this->assertSame('Book here: '.url('/book/ai-token-abc'), $reply);
    }

    public function test_per_call_auto_send_override(): void
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Call Org',
            'slug' => 'call-org-override-'.$user->id,
            'status' => 'active',
        ]);

        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['*'],
            'status' => 'active',
        ]);

        $user->forceFill([
            'current_organization_id' => $organization->id,
            'call_settings' => ['auto_send_suggestions' => true],
        ])->save();

        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'prospect_name' => 'Jane Doe',
            'status' => 'engaged',
            'meta' => [
                'flow_settings' => ['auto_send_suggestions' => true],
            ],
        ]);

        $service = app(CallOrchestrationService::class);
        $service->setAutoSendSuggestions($call, false);

        $settings = $service->settingsForCall($call->fresh(), $user);

        $this->assertFalse($settings['auto_send_suggestions']);
    }

    /**
     * @return array{0: User, 1: V2Call}
     */
    private function callWithBooking(string $pendingMessage, string $token): array
    {
        $user = $this->userWithCalendar();
        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jane Doe',
            'status' => 'engaged',
            'pending_message' => $pendingMessage,
            'meta' => ['booking_token' => $token],
        ]);

        return [$user, $call];
    }

    private function userWithCalendar(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Call Org',
            'slug' => 'call-org-'.$user->id,
            'status' => 'active',
        ]);

        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['*'],
            'status' => 'active',
        ]);

        $user->forceFill([
            'current_organization_id' => $organization->id,
            'call_settings' => [
                'use_app_booking_link' => true,
                'booking_message' => 'Book here: {calendar_url}',
            ],
        ])->save();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google_calendar',
            'provider_account_id' => 'uni_cal_'.$user->id,
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'uni_cal_'.$user->id],
        ]);

        return $user->fresh();
    }
}
