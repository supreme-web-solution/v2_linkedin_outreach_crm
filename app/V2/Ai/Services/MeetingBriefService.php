<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2Lead;
use App\V2\Services\CallOrchestrationService;
use Illuminate\Support\Str;

class MeetingBriefService
{
    public function __construct(
        private readonly CallOrchestrationService $calls,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(
        User $user,
        int $organizationId,
        ?int $callId = null,
        ?int $conversationId = null,
    ): array {
        $call = $this->resolveCall($user, $organizationId, $callId, $conversationId);

        if (! $call) {
            return [
                'found' => false,
                'message' => 'No upcoming or matching call found. Book a call in Call Manager or pass call_id.',
            ];
        }

        $meta = is_array($call->meta) ? $call->meta : [];
        $analysis = is_array($call->ai_analysis) ? collect($call->ai_analysis)->last() : null;
        $thread = $this->calls->conversationThread($call);
        $recentThread = array_slice($thread, -6);

        $talkingPoints = $this->talkingPoints($call, $analysis, $recentThread);
        $outreachContext = $this->outreachContext($call);

        $briefLines = [
            'Meeting brief: '.$call->prospect_name,
        ];

        if ($call->prospect_headline) {
            $briefLines[] = '• Role: '.$call->prospect_headline;
        }

        if ($call->scheduled_call_at) {
            $briefLines[] = '• When: '.$call->scheduled_call_at->toDayDateTimeString();
        }

        if (! empty($meta['meeting_url'])) {
            $briefLines[] = '• Join: '.$meta['meeting_url'];
        }

        foreach ($talkingPoints as $point) {
            $briefLines[] = '• '.$point;
        }

        return [
            'found' => true,
            'call_id' => $call->id,
            'prospect_name' => $call->prospect_name,
            'prospect_headline' => $call->prospect_headline,
            'status' => $call->status,
            'scheduled_call_at' => $call->scheduled_call_at?->toIso8601String(),
            'meeting_url' => $meta['meeting_url'] ?? null,
            'call_url' => url('/calls/'.$call->id),
            'talking_points' => $talkingPoints,
            'recent_conversation' => array_map(
                fn (array $entry) => [
                    'from' => ($entry['sender'] ?? '') === 'prospect' ? 'prospect' : 'you',
                    'message' => Str::limit((string) ($entry['message'] ?? ''), 200, '…'),
                    'at' => $entry['at'] ?? null,
                ],
                $recentThread,
            ),
            'ai_analysis' => is_array($analysis) ? [
                'summary' => $analysis['summary'] ?? $analysis['intent'] ?? null,
                'sentiment' => $analysis['sentiment'] ?? null,
                'recommended_action' => $analysis['recommended_action'] ?? null,
            ] : null,
            'outreach_context' => $outreachContext,
            'brief' => implode("\n", $briefLines),
        ];
    }

    private function resolveCall(
        User $user,
        int $organizationId,
        ?int $callId,
        ?int $conversationId,
    ): ?V2Call {
        if ($callId) {
            return V2Call::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $user->id)
                ->whereKey($callId)
                ->first();
        }

        if ($conversationId) {
            $conversation = V2Conversation::query()
                ->where('user_id', $user->id)
                ->whereKey($conversationId)
                ->first();

            if ($conversation) {
                $callFromConversation = V2Call::query()
                    ->where('organization_id', $organizationId)
                    ->where('user_id', $user->id)
                    ->where('conversation_id', $conversation->id)
                    ->latest('id')
                    ->first();

                if ($callFromConversation) {
                    return $callFromConversation;
                }
            }
        }

        return V2Call::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'booked')
            ->where('scheduled_call_at', '>=', now())
            ->orderBy('scheduled_call_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>|null  $analysis
     * @param  list<array<string, mixed>>  $thread
     * @return list<string>
     */
    private function talkingPoints(V2Call $call, ?array $analysis, array $thread): array
    {
        $points = [];

        if (is_array($analysis)) {
            foreach (['summary', 'recommended_action', 'objections', 'next_step'] as $key) {
                $value = trim((string) ($analysis[$key] ?? ''));
                if ($value !== '') {
                    $points[] = Str::headline($key).': '.$value;
                }
            }
        }

        $lastProspect = collect($thread)
            ->reverse()
            ->first(fn (array $entry) => ($entry['sender'] ?? '') === 'prospect');

        if ($lastProspect) {
            $points[] = 'Last said: "'.Str::limit((string) ($lastProspect['message'] ?? ''), 120, '…').'"';
        }

        if ($points === []) {
            $points[] = 'Confirm their goal and timeline in the first two minutes.';
            $points[] = 'Reference how they entered your pipeline (outreach or inbound).';
        }

        return array_values(array_unique($points));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function outreachContext(V2Call $call): ?array
    {
        $lead = $call->lead_id ? V2Lead::query()->find($call->lead_id) : null;
        if (! $lead) {
            return null;
        }

        return [
            'lead_id' => $lead->id,
            'lead_name' => $lead->full_name ?? $call->prospect_name,
            'headline' => $lead->headline ?? $call->prospect_headline,
            'source' => 'crm',
        ];
    }
}
