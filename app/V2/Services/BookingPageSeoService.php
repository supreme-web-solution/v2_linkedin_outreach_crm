<?php

namespace App\V2\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

/**
 * Server-side SEO for the public call booking page (LinkedIn / social crawlers).
 */
class BookingPageSeoService
{
    public function apply(
        User $host,
        ?string $prospectName,
        bool $booked,
        ?CarbonInterface $scheduledAt,
        int $durationMinutes,
        string $token,
    ): void {
        $appName = (string) config('app.name', 'Socifusion');
        $hostName = trim($host->name) !== '' ? trim($host->name) : 'your host';
        $duration = max(15, $durationMinutes);

        if ($booked && $scheduledAt !== null) {
            $when = $scheduledAt->timezone($host->timezone ?? config('app.timezone'))
                ->format('l, F j \a\t g:i A T');

            $title = "Call confirmed with {$hostName}";
            $description = $prospectName
                ? "{$prospectName}, your {$duration}-minute call with {$hostName} is set for {$when}."
                : "Your {$duration}-minute call with {$hostName} is scheduled for {$when}.";
        } else {
            $title = "Book a call with {$hostName}";
            $description = $prospectName
                ? "Hi {$prospectName} — choose a time for a {$duration}-minute video call with {$hostName}."
                : "Choose a time for a {$duration}-minute video call with {$hostName}.";
        }

        $pageUrl = URL::route('book.show', ['token' => $token], absolute: true);
        $imageUrl = URL::asset('images/seo/book-call-og.png');

        seo()
            ->title($title)
            ->description($description)
            ->image($imageUrl)
            ->type('website')
            ->site($appName)
            ->url($pageUrl);

        seo()->rawTag('<meta property="og:image:width" content="1200">');
        seo()->rawTag('<meta property="og:image:height" content="630">');
        seo()->rawTag('<meta property="og:image:alt" content="Book a video call — pick a time that works for you">');
    }
}
