<?php

namespace App\V2\Ai\Services;

use Illuminate\Support\Str;

class InboxClassificationService
{
    /**
     * Keyword-based intent / priority classification for Unified Inbox replies.
     *
     * @return array{
     *     priority: string,
     *     intent: string,
     *     stage: string,
     *     recommended_action: string,
     *     evidence: list<string>
     * }
     */
    public function classify(string $body): array
    {
        $text = Str::lower(trim($body));
        $evidence = [];

        if ($text === '') {
            return [
                'priority' => 'needs_judgment',
                'intent' => 'unclear',
                'stage' => 'early',
                'recommended_action' => 'Review the thread and classify manually.',
                'evidence' => ['empty_message'],
            ];
        }

        $hotPatterns = [
            'yes' => '/\b(yes|yeah|yep|sure|interested|sounds good)\b/i',
            'pricing' => '/\b(pricing|price|cost|how much|quote|budget)\b/i',
            'meeting' => '/\b(demo|call|meeting|schedule|calendar|book|zoom|chat tomorrow)\b/i',
        ];

        foreach ($hotPatterns as $label => $pattern) {
            if (preg_match($pattern, $text)) {
                $evidence[] = $label;
            }
        }

        if ($evidence !== []) {
            return [
                'priority' => 'hot',
                'intent' => in_array('meeting', $evidence, true) ? 'meeting_request' : 'interested',
                'stage' => in_array('meeting', $evidence, true) ? 'qualified' : 'engaged',
                'recommended_action' => 'Reply quickly and propose a concrete next step (time slots or demo link).',
                'evidence' => $evidence,
            ];
        }

        $lowPatterns = [
            'not_now' => '/\b(not now|maybe later|circle back|next quarter|busy right now)\b/i',
            'objection' => '/\b(already use|using|competitor|not a fit|wrong person)\b/i',
            'opt_out' => '/\b(unsubscribe|stop|remove me|do not contact|don\'t contact)\b/i',
        ];

        foreach ($lowPatterns as $label => $pattern) {
            if (preg_match($pattern, $text)) {
                $evidence[] = $label;
            }
        }

        if (in_array('opt_out', $evidence, true)) {
            return [
                'priority' => 'low_priority',
                'intent' => 'opt_out',
                'stage' => 'closed_lost',
                'recommended_action' => 'Pause outreach for this lead and confirm removal if required.',
                'evidence' => $evidence,
            ];
        }

        if ($evidence !== []) {
            return [
                'priority' => 'low_priority',
                'intent' => in_array('objection', $evidence, true) ? 'objection' : 'timing',
                'stage' => 'engaged',
                'recommended_action' => 'Consider nurture sequence or pause active outreach.',
                'evidence' => $evidence,
            ];
        }

        if (preg_match('/\b(question|how does|what is|can you|tell me more)\b/i', $text)) {
            return [
                'priority' => 'needs_judgment',
                'intent' => 'question',
                'stage' => 'engaged',
                'recommended_action' => 'Answer the question directly, then suggest a next step.',
                'evidence' => ['question'],
            ];
        }

        return [
            'priority' => 'needs_judgment',
            'intent' => 'neutral',
            'stage' => 'early',
            'recommended_action' => 'Review reply and decide next step.',
            'evidence' => ['neutral_tone'],
        ];
    }

    public function priorityLabel(string $priority): string
    {
        return match ($priority) {
            'hot' => 'Hot — strong buying signal',
            'low_priority' => 'Low priority — objection or timing',
            default => 'Needs judgment',
        };
    }
}
