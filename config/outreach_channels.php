<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outreach channel enablement
    |--------------------------------------------------------------------------
    |
    | Toggle each platform on/off without removing integration code.
    | Disabled channels are hidden in Integrations, outreach builder, unified
    | inbox, auto-responses, and related flows.
    |
    */

    'enabled' => [
        'linkedin' => env('CHANNEL_LINKEDIN_ENABLED', true),
        'email' => env('CHANNEL_EMAIL_ENABLED', true),
        'whatsapp' => env('CHANNEL_WHATSAPP_ENABLED', true),
        'instagram' => env('CHANNEL_INSTAGRAM_ENABLED', true),
        'telegram' => env('CHANNEL_TELEGRAM_ENABLED', false),
        'twitter' => env('CHANNEL_TWITTER_ENABLED', false),
        'google_calendar' => env('CHANNEL_GOOGLE_CALENDAR_ENABLED', true),
        'outlook_calendar' => env('CHANNEL_OUTLOOK_CALENDAR_ENABLED', true),
    ],

    /*
    | Channels that appear in the unified inbox (subset of all channels).
    */
    'inbox' => [
        'linkedin',
        'whatsapp',
        'instagram',
        'telegram',
        'twitter',
        'email',
    ],

];
