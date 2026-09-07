<?php

namespace App\V2\Ai\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class ScheduleAtParser
{
    public static function parse(?string $input, ?string $timezone = null): ?Carbon
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        $tz = $timezone ?: (string) config('app.timezone', 'UTC');
        $now = Carbon::now($tz);

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $input)) {
            $parsed = Carbon::parse($input, $tz);

            return $parsed->isFuture() ? $parsed : null;
        }

        $lower = Str::lower($input);

        if (preg_match('/\btomorrow\b/', $lower)) {
            $at = $now->copy()->addDay()->setTime(self::hourFromPhrase($lower), 0, 0);

            return $at->isFuture() ? $at : null;
        }

        if (preg_match('/\btoday\b/', $lower)) {
            $at = $now->copy()->setTime(self::hourFromPhrase($lower), 0, 0);

            return $at->isFuture() ? $at : $now->copy()->addHour();
        }

        $weekdays = [
            'monday' => Carbon::MONDAY,
            'tuesday' => Carbon::TUESDAY,
            'wednesday' => Carbon::WEDNESDAY,
            'thursday' => Carbon::THURSDAY,
            'friday' => Carbon::FRIDAY,
            'saturday' => Carbon::SATURDAY,
            'sunday' => Carbon::SUNDAY,
        ];

        foreach ($weekdays as $name => $constant) {
            if (str_contains($lower, $name)) {
                $at = $now->copy()->next($constant)->setTime(self::hourFromPhrase($lower), 0, 0);
                if ($at->lte($now)) {
                    $at->addWeek();
                }

                return $at;
            }
        }

        try {
            $parsed = Carbon::parse($input, $tz);

            return $parsed->isFuture() ? $parsed : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function hourFromPhrase(string $lower): int
    {
        if (preg_match('/\b(\d{1,2})\s*(am|pm)\b/', $lower, $m)) {
            $hour = (int) $m[1];
            if ($m[2] === 'pm' && $hour < 12) {
                $hour += 12;
            }
            if ($m[2] === 'am' && $hour === 12) {
                $hour = 0;
            }

            return max(0, min(23, $hour));
        }

        if (str_contains($lower, 'morning')) {
            return 9;
        }
        if (str_contains($lower, 'afternoon')) {
            return 14;
        }
        if (str_contains($lower, 'evening')) {
            return 18;
        }

        return 9;
    }

    /**
     * Calendar day bounds for filtering scheduled posts (e.g. "tomorrow", "2026-09-08").
     *
     * @return array{start:Carbon,end:Carbon}|null
     */
    public static function dayRange(?string $input, ?string $timezone = null): ?array
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        $tz = $timezone ?: (string) config('app.timezone', 'UTC');
        $now = Carbon::now($tz);
        $lower = Str::lower($input);

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $input)) {
            $day = Carbon::parse($input, $tz)->startOfDay();

            return ['start' => $day, 'end' => $day->copy()->endOfDay()];
        }

        if (preg_match('/\btomorrow\b/', $lower)) {
            $day = $now->copy()->addDay()->startOfDay();

            return ['start' => $day, 'end' => $day->copy()->endOfDay()];
        }

        if (preg_match('/\btoday\b/', $lower)) {
            $day = $now->copy()->startOfDay();

            return ['start' => $day, 'end' => $day->copy()->endOfDay()];
        }

        $weekdays = [
            'monday' => Carbon::MONDAY,
            'tuesday' => Carbon::TUESDAY,
            'wednesday' => Carbon::WEDNESDAY,
            'thursday' => Carbon::THURSDAY,
            'friday' => Carbon::FRIDAY,
            'saturday' => Carbon::SATURDAY,
            'sunday' => Carbon::SUNDAY,
        ];

        foreach ($weekdays as $name => $constant) {
            if (str_contains($lower, $name)) {
                $day = $now->copy()->next($constant)->startOfDay();
                if ($day->isSameDay($now)) {
                    $day = $now->copy()->startOfDay();
                }

                return ['start' => $day, 'end' => $day->copy()->endOfDay()];
            }
        }

        try {
            $day = Carbon::parse($input, $tz)->startOfDay();

            return ['start' => $day, 'end' => $day->copy()->endOfDay()];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve a target day/time phrase, preserving hour/minute from $preserveFrom when the phrase has no time.
     */
    public static function resolveRescheduleTarget(string $input, ?CarbonInterface $preserveFrom = null, ?string $timezone = null): ?Carbon
    {
        $parsed = self::parse($input, $timezone);
        if ($parsed === null) {
            return null;
        }

        if ($preserveFrom !== null && ! self::phraseIncludesTime($input)) {
            return $parsed->copy()->setTime(
                (int) $preserveFrom->format('G'),
                (int) $preserveFrom->format('i'),
                (int) $preserveFrom->format('s'),
            );
        }

        return $parsed;
    }

    private static function phraseIncludesTime(string $input): bool
    {
        $lower = Str::lower(trim($input));

        return (bool) preg_match('/\b(\d{1,2}\s*(am|pm)|morning|afternoon|evening|\d{1,2}:\d{2})\b/', $lower);
    }
}
