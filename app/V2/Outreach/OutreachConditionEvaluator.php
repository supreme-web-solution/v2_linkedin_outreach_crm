<?php

namespace App\V2\Outreach;

use App\Models\V2OutreachLeadProgress;
use Illuminate\Support\Carbon;

class OutreachConditionEvaluator
{
    public const INVITE_TIMEOUT_DAYS = 7;

    public const NO_REPLY_TIMEOUT_DAYS = 3;

    /**
     * @param  array<string, mixed>  $node
     */
    public function evaluate(V2OutreachLeadProgress $progress, array $node): ?bool
    {
        $condition = (string) ($node['condition'] ?? 'invite_accepted');
        $channel = (string) ($node['channel'] ?? 'linkedin');
        $channelState = is_array($progress->channel_state) ? $progress->channel_state : [];
        $channelData = is_array($channelState[$channel] ?? null) ? $channelState[$channel] : [];
        $replied = (bool) ($channelData['replied'] ?? false);
        $opened = (bool) ($channelData['opened'] ?? false);
        $bounced = (bool) ($channelData['bounced'] ?? false);
        $inviteAccepted = (bool) ($channelData['invite_accepted'] ?? false)
            || ($channel === 'linkedin' && $progress->acceptance_status === true);

        $waitSince = $this->conditionWaitSince($progress);
        $timeoutDays = $this->timeoutDaysFor($node, $condition);

        return match ($condition) {
            'invite_accepted' => $inviteAccepted ? true : ($this->timedOut($waitSince, $timeoutDays) ? false : null),
            'message_replied', 'email_replied', 'has_replied' => $replied ? true : ($this->timedOut($waitSince, $timeoutDays) ? false : null),
            'no_reply' => $replied ? false : ($this->timedOut($waitSince, $timeoutDays) ? true : null),
            'email_opened' => $opened ? true : ($this->timedOut($waitSince, $timeoutDays) ? false : null),
            'email_bounced' => $bounced ? true : null,
            default => null,
        };
    }

    public function markConditionWaiting(V2OutreachLeadProgress $progress): void
    {
        $meta = is_array($progress->meta) ? $progress->meta : [];
        if (! empty($meta['condition_wait_since'])) {
            return;
        }

        $meta['condition_wait_since'] = now()->toIso8601String();
        $progress->forceFill(['meta' => $meta])->save();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function timeoutDaysFor(array $node, string $condition): int
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $configured = (int) ($config['timeout_days'] ?? 0);

        if ($configured > 0) {
            return min($configured, 90);
        }

        return match ($condition) {
            'invite_accepted' => self::INVITE_TIMEOUT_DAYS,
            default => self::NO_REPLY_TIMEOUT_DAYS,
        };
    }

    private function conditionWaitSince(V2OutreachLeadProgress $progress): ?Carbon
    {
        $meta = is_array($progress->meta) ? $progress->meta : [];
        $raw = $meta['condition_wait_since'] ?? null;

        return is_string($raw) && $raw !== '' ? Carbon::parse($raw) : null;
    }

    private function timedOut(?Carbon $since, int $days): bool
    {
        if ($since === null) {
            return false;
        }

        return $since->copy()->addDays($days)->isPast();
    }
}
