<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\CallCalendarService;
use App\V2\Services\CallOrchestrationService;
use App\V2\Services\UnifiedInboxReplyService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class BookMeetingCommandCenterService
{
    public function __construct(
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly CallCalendarService $calendar,
        private readonly CallOrchestrationService $calls,
        private readonly InboxClassificationService $classifier,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function stage(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        int $inboxConversationId,
        ?string $notes = null,
        string $surface = 'web',
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);

        if ($this->settingsService->isBlocked($settings)) {
            return [
                'blocked' => true,
                'message' => 'AI Employee is currently disabled for this workspace.',
            ];
        }

        $inbox = V2Conversation::query()
            ->where('user_id', $user->id)
            ->whereKey($inboxConversationId)
            ->firstOrFail();

        if (! $this->calendar->isAvailable($user->id)) {
            return [
                'blocked' => true,
                'message' => 'Connect Google or Outlook calendar on Integrations before Alex can send booking links.',
            ];
        }

        $meta = is_array($inbox->meta) ? $inbox->meta : [];
        $prospectName = trim((string) Arr::get($meta, 'prospect_name', 'there'));
        $latestInbound = trim((string) ($inbox->messages()->where('direction', 'inbound')->latest('id')->value('body') ?? ''));
        $classification = $this->classifier->classify($latestInbound);

        $existingCall = V2Call::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $inbox->id)
            ->whereIn('status', ['engaged', 'booked', 'scheduled'])
            ->latest('id')
            ->first();

        $call = $existingCall ?? $this->calls->createCall($user, $organizationId, [
            'conversation_id' => $inbox->id,
            'prospect_name' => $prospectName,
            'meta' => ['source' => 'socifusion_ai'],
        ]);

        $token = $this->calendar->ensureBookingToken($call);
        $bookingUrl = $this->calendar->publicBookingUrl($token);
        $draft = trim((string) $notes) !== ''
            ? trim((string) $notes)
            : "Great to connect, {$prospectName} — pick a time that works for you:\n{$bookingUrl}";

        $plan = [
            'type' => 'book_meeting',
            'goal' => 'Book a meeting with '.$prospectName,
            'conversation_id' => $inbox->id,
            'call_id' => $call->id,
            'prospect_name' => $prospectName,
            'channel' => $inbox->provider,
            'channel_label' => OutreachChannelRegistry::channelLabel((string) $inbox->provider),
            'inbound_preview' => Str::limit($latestInbound, 200, '…'),
            'intent' => $classification['intent'],
            'booking_url' => $bookingUrl,
            'draft_text' => $draft,
            'inbox_url' => url('/inbox/'.$inbox->provider.'/'.$inbox->id),
            'steps' => [
                'Send calendar link to prospect',
                'Prospect picks a slot on your booking page',
                'Call Manager syncs the meeting + CRM',
            ],
        ];

        if ($this->settingsService->for($user, $organizationId)->autonomy_level <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval_id' => null,
                'plan' => $plan,
                'card' => $this->commandCenter->formatPlanCard($plan, null, $surface),
            ];
        }

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'book_meeting',
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        return [
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $this->commandCenter->formatPlanCard($plan, $approval->id, $surface),
        ];
    }
}
