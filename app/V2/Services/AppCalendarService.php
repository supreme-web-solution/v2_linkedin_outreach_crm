<?php

namespace App\V2\Services;

use App\Jobs\V2\PublishV2ContentPostJob;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2ContentPost;
use App\Models\V2Reminder;
use Carbon\CarbonInterface;

class AppCalendarService
{
    /**
     * @return list<array{
     *     id: string,
     *     type: string,
     *     title: string,
     *     start: string,
     *     end: string|null,
     *     color: string,
     *     status: string|null,
     *     prospect_name: string|null,
     *     provider: string|null,
     *     href: string|null,
     *     meta: array<string, mixed>
     * }>
     */
    public function eventsForOrganization(int $orgId, int $userId, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = [];

        $calls = V2Call::query()
            ->where('organization_id', $orgId)
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('scheduled_call_at', [$from, $to])
                    ->orWhereBetween('scheduled_send_at', [$from, $to]);
            })
            ->orderBy('scheduled_call_at')
            ->get();

        foreach ($calls as $call) {
            if ($call->scheduled_call_at) {
                $events[] = $this->callEvent($call, 'call');
            }
            if ($call->scheduled_send_at) {
                $events[] = $this->callEvent($call, 'call_send', $call->scheduled_send_at);
            }
        }

        $posts = V2ContentPost::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $userId)
            ->where('status', 'scheduled')
            ->whereBetween('scheduled_at', [$from, $to])
            ->orderBy('scheduled_at')
            ->get();

        foreach ($posts as $post) {
            $events[] = $this->contentEvent($post);
        }

        $reminders = V2Reminder::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->whereBetween('send_at', [$from, $to])
            ->orderBy('send_at')
            ->get();

        foreach ($reminders as $reminder) {
            $events[] = $this->reminderEvent($reminder);
        }

        usort($events, fn (array $a, array $b) => strcmp($a['start'], $b['start']));

        return $events;
    }

    /**
     * @return array{id: string, type: string, title: string, start: string, end: string|null, color: string, status: string|null, prospect_name: string|null, provider: string|null, href: string|null, meta: array<string, mixed>}
     */
    private function callEvent(V2Call $call, string $type, ?CarbonInterface $at = null): array
    {
        $start = $at ?? $call->scheduled_call_at;
        $name = trim((string) ($call->prospect_name ?? '')) ?: 'Prospect';

        return [
            'id' => "{$type}:{$call->id}",
            'type' => $type,
            'title' => $type === 'call_send'
                ? "Send message — {$name}"
                : "Call — {$name}",
            'start' => $start?->toIso8601String() ?? now()->toIso8601String(),
            'end' => $type === 'call' && $start
                ? $start->copy()->addMinutes(30)->toIso8601String()
                : null,
            'color' => $type === 'call_send' ? 'sky' : 'blue',
            'status' => $call->status,
            'prospect_name' => $call->prospect_name,
            'provider' => 'call_manager',
            'href' => route('calls.show', $call->id),
            'meta' => [
                'call_id' => $call->id,
                'headline' => $call->prospect_headline,
            ],
        ];
    }

    /**
     * @return array{id: string, type: string, title: string, start: string, end: string|null, color: string, status: string|null, prospect_name: string|null, provider: string|null, href: string|null, meta: array<string, mixed>}
     */
    private function contentEvent(V2ContentPost $post): array
    {
        $preview = trim(strip_tags((string) $post->content));
        $title = $preview !== ''
            ? 'Post — '.mb_substr($preview, 0, 48).(mb_strlen($preview) > 48 ? '…' : '')
            : 'Scheduled post';

        return [
            'id' => "content:{$post->id}",
            'type' => 'content',
            'title' => $title,
            'start' => $post->scheduled_at?->toIso8601String() ?? now()->toIso8601String(),
            'end' => null,
            'color' => 'violet',
            'status' => $post->status,
            'prospect_name' => null,
            'provider' => $post->provider,
            'href' => route('content'),
            'meta' => [
                'content_post_id' => $post->id,
                'preview' => mb_substr($preview, 0, 200),
            ],
        ];
    }

    /**
     * @return array{id: string, type: string, title: string, start: string, end: string|null, color: string, status: string|null, prospect_name: string|null, provider: string|null, href: string|null, meta: array<string, mixed>}
     */
    private function reminderEvent(V2Reminder $reminder): array
    {
        $call = $reminder->call_id ? V2Call::query()->find($reminder->call_id) : null;
        $name = $call?->prospect_name ?: 'prospect';

        return [
            'id' => "reminder:{$reminder->id}",
            'type' => 'reminder',
            'title' => "Reminder — {$name}",
            'start' => $reminder->send_at?->toIso8601String() ?? now()->toIso8601String(),
            'end' => null,
            'color' => 'amber',
            'status' => $reminder->status,
            'prospect_name' => $call?->prospect_name,
            'provider' => 'call_manager',
            'href' => $call ? route('calls.show', $call->id) : null,
            'meta' => [
                'reminder_id' => $reminder->id,
                'message' => mb_substr((string) $reminder->message, 0, 200),
            ],
        ];
    }

    /**
     * @return array{event: array<string, mixed>}
     */
    public function reschedule(int $orgId, User $user, string $type, int $recordId, CarbonInterface $newStart): array
    {
        return match ($type) {
            'call' => $this->rescheduleCall($orgId, $user, $recordId, $newStart),
            'call_send' => $this->rescheduleCallSend($orgId, $recordId, $newStart),
            'content' => $this->rescheduleContent($orgId, $user->id, $recordId, $newStart),
            'reminder' => $this->rescheduleReminder($orgId, $user->id, $recordId, $newStart),
            default => throw new \InvalidArgumentException('Unsupported calendar event type.'),
        };
    }

    /**
     * @return array{event: array<string, mixed>}
     */
    private function rescheduleCall(int $orgId, User $user, int $callId, CarbonInterface $newStart): array
    {
        $call = V2Call::query()
            ->where('organization_id', $orgId)
            ->where('id', $callId)
            ->firstOrFail();

        if ($newStart->isPast()) {
            throw new \InvalidArgumentException('Call must be scheduled in the future.');
        }

        $call->forceFill([
            'scheduled_call_at' => $newStart,
            'status' => 'booked',
        ])->save();

        app(CallOrchestrationService::class)->scheduleCallReminders($call->fresh(), $user);

        return ['event' => $this->callEvent($call->fresh(), 'call')];
    }

    /**
     * @return array{event: array<string, mixed>}
     */
    private function rescheduleCallSend(int $orgId, int $callId, CarbonInterface $newStart): array
    {
        $call = V2Call::query()
            ->where('organization_id', $orgId)
            ->where('id', $callId)
            ->firstOrFail();

        if ($newStart->isPast()) {
            throw new \InvalidArgumentException('Send time must be in the future.');
        }

        $call->forceFill(['scheduled_send_at' => $newStart])->save();

        return ['event' => $this->callEvent($call->fresh(), 'call_send')];
    }

    /**
     * @return array{event: array<string, mixed>}
     */
    private function rescheduleContent(int $orgId, int $userId, int $postId, CarbonInterface $newStart): array
    {
        $post = V2ContentPost::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $userId)
            ->where('id', $postId)
            ->firstOrFail();

        if ($newStart->lte(now())) {
            throw new \InvalidArgumentException('Scheduled time must be in the future.');
        }

        $post->update([
            'status' => 'scheduled',
            'scheduled_at' => $newStart,
        ]);

        PublishV2ContentPostJob::dispatch($post->id)->delay($newStart);

        return ['event' => $this->contentEvent($post->fresh())];
    }

    /**
     * @return array{event: array<string, mixed>}
     */
    private function rescheduleReminder(int $orgId, int $userId, int $reminderId, CarbonInterface $newStart): array
    {
        $reminder = V2Reminder::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $userId)
            ->where('id', $reminderId)
            ->where('status', 'pending')
            ->firstOrFail();

        if ($newStart->isPast()) {
            throw new \InvalidArgumentException('Reminder must be scheduled in the future.');
        }

        $reminder->forceFill(['send_at' => $newStart])->save();

        return ['event' => $this->reminderEvent($reminder->fresh())];
    }
}
