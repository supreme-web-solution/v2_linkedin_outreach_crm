<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Support\RecipientFacingCopyGuard;
use App\V2\Ai\Support\SenderIdentity;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\CallCalendarService;
use App\V2\Services\CallOrchestrationService;
use App\V2\Services\OpenAIContentService;
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
        private readonly OpenAIContentService $openai,
        private readonly OutboundMessageComposerService $composer,
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

        $workspace = app(WorkspaceContextService::class);
        $meeting = $workspace->resolveMeetingLink($user, $settings);
        $calendarAvailable = $this->calendar->isAvailable($user->id);
        $manualUrl = (is_string($meeting) && $meeting !== '' && $meeting !== 'app_booking')
            ? $meeting
            : '';

        if (! $calendarAvailable && $manualUrl === '') {
            return [
                'blocked' => true,
                'message' => 'Add a meeting link in AI Employee settings, or connect Google/Outlook calendar on Integrations, before Soci can send booking links.',
            ];
        }

        $meta = is_array($inbox->meta) ? $inbox->meta : [];
        $prospectName = trim((string) Arr::get($meta, 'prospect_name', 'there'));
        $latestInbound = trim((string) ($inbox->messages()->where('direction', 'inbound')->latest('id')->value('body') ?? ''));
        $classification = $this->classifier->classify($latestInbound);

        $existingCall = V2Call::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $inbox->id)
            ->whereIn('status', ['engaged', 'scheduling', 'sent', 'in_progress', 'booked', 'scheduled'])
            ->latest('id')
            ->first();

        $batch = $this->inboxBookingBatch($user, $existingCall);
        $call = $existingCall ?? $this->calls->createCall($user, $organizationId, [
            'conversation_id' => $inbox->id,
            'prospect_name' => $prospectName,
            'meta' => [
                'source' => 'socifusion_ai',
                'batch_id' => $batch['id'],
                'batch_name' => $batch['name'],
            ],
        ]);

        $token = $this->calendar->ensureBookingToken($call);
        $bookingUrl = $manualUrl !== ''
            ? $manualUrl
            : $this->calendar->publicBookingUrl($token);
        $senderName = SenderIdentity::displayName($user, $organizationId);
        $draft = $this->buildRecipientFacingDraft(
            $inbox,
            $prospectName,
            $bookingUrl,
            $senderName,
            $notes,
            $latestInbound,
        );

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
            'meeting_link_source' => $manualUrl !== '' ? 'manual' : 'app_booking',
            'agent_notes' => trim((string) $notes) !== '' ? trim((string) $notes) : null,
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

    private function buildRecipientFacingDraft(
        V2Conversation $inbox,
        string $prospectName,
        string $bookingUrl,
        string $senderName,
        ?string $notes,
        string $latestInbound,
    ): string {
        $notes = trim((string) $notes);
        $signOff = $senderName !== '' ? $senderName : 'Best regards';

        // Notes that are already a real message to the prospect.
        if (
            $notes !== ''
            && ! RecipientFacingCopyGuard::looksLikeInternalPlan($notes)
            && ! RecipientFacingCopyGuard::hasUnresolvedPlaceholders($notes)
        ) {
            $draft = $notes;
            if (! str_contains($draft, $bookingUrl)) {
                $draft = rtrim($draft)."\n\n".$bookingUrl;
            }
            if ($senderName !== '' && ! str_contains($draft, $senderName)) {
                $draft = rtrim($draft)."\n\n".$signOff;
            }

            return $draft;
        }

        // Planning notes / empty → generate recipient-facing copy (notes = guidance only).
        $guidance = $notes !== '' ? $notes : 'Thank them and share the booking link to pick a time.';
        try {
            $aiContext = "Write a short recipient-facing reply. Guidance for YOU (do not paste as the reply):\n{$guidance}\n\n"
                ."Include this exact booking link in the message:\n{$bookingUrl}\n"
                .($senderName !== ''
                    ? "Sign off as: {$senderName}\n"
                    : "Do not use [Your Name] — omit a personal name if unknown.\n")
                .'Output ONLY the email/DM the prospect will read.';

            $draft = trim($this->composer->composeInboxReply(
                (string) $inbox->provider,
                $aiContext,
                [],
                $latestInbound !== '' ? $latestInbound : 'Thanks — interested, please share details.',
                $prospectName,
                [
                    'sender_name' => $senderName,
                    'agent_notes' => $guidance,
                    'must_include_url' => $bookingUrl,
                ],
            )['body'] ?? '');

            if ($draft !== '' && RecipientFacingCopyGuard::problems($draft) === []) {
                if (! str_contains($draft, $bookingUrl)) {
                    $draft .= "\n\n".$bookingUrl;
                }

                return $draft;
            }
        } catch (\Throwable) {
            // fall through to template
        }

        $hello = $prospectName !== '' && strcasecmp($prospectName, 'there') !== 0
            ? "Hi {$prospectName},"
            : 'Hi,';

        return "{$hello}\n\nThanks for your interest — pick a time that works for you here:\n{$bookingUrl}\n\nLooking forward to connecting.\n\n{$signOff}";
    }

    /**
     * @return array{id: string, name: string}
     */
    private function inboxBookingBatch(User $user, ?V2Call $existing): array
    {
        $name = 'Inbox bookings';
        $meta = is_array($existing?->meta) ? $existing->meta : [];
        $id = trim((string) ($meta['batch_id'] ?? ''));

        return [
            'id' => $id !== '' ? $id : 'inbox-bookings-'.$user->id,
            'name' => trim((string) ($meta['batch_name'] ?? '')) !== ''
                ? (string) $meta['batch_name']
                : $name,
        ];
    }
}
