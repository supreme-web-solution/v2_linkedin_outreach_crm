<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ops' => [
        'slack_webhook_url' => env('OPS_SLACK_WEBHOOK_URL'),
        'alert_daily_limit_hits' => env('OPS_ALERT_DAILY_LIMITS', true),
        'alert_queue_health' => env('OPS_ALERT_QUEUE_HEALTH', true),
        'alert_failed_jobs_threshold' => (int) env('OPS_ALERT_FAILED_JOBS_THRESHOLD', 10),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY', env('OPENAI_KEY')),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
    ],

    'cloudinary' => [
        'url' => env('CLOUDINARY_URL'),
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
    ],

    'chatgpt' => [
        'key' => env('OPENAI_API_KEY', env('OPENAI_KEY')),
    ],

    'rapidapi' => [
        'key' => env('RAPIDAPI_KEY'),
        'allowed_hosts' => array_filter(array_map('trim', explode(',', env('RAPIDAPI_ALLOWED_HOSTS', 'linkedin-data-api.p.rapidapi.com,li-data-scraper.p.rapidapi.com,fresh-linkedin-profile-data.p.rapidapi.com')))),
    ],

    'skrapp' => [
        'key' => env('SKRAPP_EMAIL_FINDER_KEY'),
    ],

    'email_scraping' => [
        // Per-user daily LinkedIn profile contact lookups (email/phone enrich).
        'daily_limit_per_user' => (int) env('DAILY_EMAIL_SCRAPING_LIMIT', 100),
        // Max leads queued per Enrich / Prepare click (Leads + Outreach).
        // Also used as the in-flight cap: no new click while this many are already pending.
        'batch_size' => (int) env('EMAIL_ENRICHMENT_BATCH_SIZE', 25),
    ],

    'fullenrich' => [
        'api_key' => env('FULLENRICH_API_KEY'),
        'base_url' => env('FULLENRICH_BASE_URL', 'https://app.fullenrich.com/api/v2'),
        'poll_timeout_seconds' => (int) env('FULLENRICH_POLL_TIMEOUT_SECONDS', 90),
        'poll_interval_seconds' => (int) env('FULLENRICH_POLL_INTERVAL_SECONDS', 3),
        'request_timeout_seconds' => (int) env('FULLENRICH_REQUEST_TIMEOUT_SECONDS', 30),
    ],

    'competitor_followers' => [
        'company_posts_limit' => env('COMPETITOR_POSTS_LIMIT', 15),
        'page_size' => env('COMPETITOR_PAGE_SIZE', 100),
        'max_engagers_per_post' => env('COMPETITOR_MAX_ENGAGERS_PER_POST', 500),
        'max_posts_scan' => env('COMPETITOR_MAX_POSTS_SCAN', 30),
    ],

    /*
    | Per-user daily action caps + pacing to stay within Unipile / LinkedIn
    | conventional-usage limits. Caps of 0 or less mean "unlimited".
    | When a cap is reached, queued actions auto-defer to the next day.
    */
    'unipile_pacing' => [
        'daily_invites' => (int) env('UNIPILE_DAILY_INVITE_CAP', 40),
        'daily_new_chats' => (int) env('UNIPILE_DAILY_NEW_CHAT_CAP', 60),
        'daily_messages' => (int) env('UNIPILE_DAILY_MESSAGE_CAP', 200),
        // Bulk "start all chats": seconds between each queued chat + random jitter
        'chat_launch_stagger_seconds' => (int) env('UNIPILE_CHAT_LAUNCH_STAGGER_SECONDS', 8),
        'chat_launch_jitter_seconds' => (int) env('UNIPILE_CHAT_LAUNCH_JITTER_SECONDS', 7),
        // Random pause between chained profile lookups (email enrichment batches)
        'profile_lookup_delay_min_ms' => (int) env('UNIPILE_PROFILE_LOOKUP_DELAY_MIN_MS', 1000),
        'profile_lookup_delay_max_ms' => (int) env('UNIPILE_PROFILE_LOOKUP_DELAY_MAX_MS', 3000),
        // Random pause between paginated harvest calls (competitor engagers)
        'harvest_page_delay_min_ms' => (int) env('UNIPILE_HARVEST_PAGE_DELAY_MIN_MS', 800),
        'harvest_page_delay_max_ms' => (int) env('UNIPILE_HARVEST_PAGE_DELAY_MAX_MS', 2500),
        // Serialize Unipile calls per LinkedIn account across features (enrich/harvest/outreach).
        'account_lock_seconds' => (int) env('UNIPILE_ACCOUNT_LOCK_SECONDS', 25),
        'account_lock_wait_seconds' => (int) env('UNIPILE_ACCOUNT_LOCK_WAIT_SECONDS', 20),
        // Max ProcessOutreachLeadJob handlers running at once per user (others wait in queue).
        'outreach_inflight_per_user' => (int) env('OUTREACH_INFLIGHT_PER_USER', 2),
        // Lead readiness / Prepare contacts — same as EMAIL_ENRICHMENT_BATCH_SIZE unless overridden.
        'contact_prep_batch_size' => (int) env(
            'OUTREACH_CONTACT_PREP_BATCH_SIZE',
            env('EMAIL_ENRICHMENT_BATCH_SIZE', 25)
        ),
    ],

    'unipile' => [
        'base_url' => env('UNIPILE_BASE_URL', 'https://api1.unipile.com:13111/api/v1'),
        'api_key' => env('UNIPILE_API_KEY'),
        'webhook_secret' => env('UNIPILE_WEBHOOK_SECRET'),
        // Paste this path in Unipile Dashboard → Webhooks (Messaging source).
        'webhook_callback_path' => env('UNIPILE_WEBHOOK_CALLBACK_PATH', '/unipile/callback'),
        'mock' => env('UNIPILE_MOCK', false),
        // How account_id is sent on scoped routes (search, posts, etc.): query | body | path
        'account_id_param' => env('UNIPILE_ACCOUNT_ID_PARAM', 'query'),
        'endpoints' => [
            'hosted_auth_link'  => env('UNIPILE_ENDPOINT_HOSTED_AUTH_LINK', '/hosted/accounts/link'),
            'connect_account'   => env('UNIPILE_ENDPOINT_CONNECT_ACCOUNT', '/accounts'),
            'list_accounts'     => env('UNIPILE_ENDPOINT_LIST_ACCOUNTS', '/accounts'),
            'get_account'       => env('UNIPILE_ENDPOINT_GET_ACCOUNT', '/accounts/%s'),
            'delete_account'    => env('UNIPILE_ENDPOINT_DELETE_ACCOUNT', '/accounts/%s'),
            'search'            => env('UNIPILE_ENDPOINT_SEARCH', '/linkedin/search'),
            'send_invitation'   => env('UNIPILE_ENDPOINT_SEND_INVITATION', '/users/invite'),
            'list_invitations'  => env('UNIPILE_ENDPOINT_LIST_INVITATIONS', '/users/invitations'),
            'accept_invitation' => env('UNIPILE_ENDPOINT_ACCEPT_INVITATION', '/users/invitations'),
            'withdraw_invitation'=> env('UNIPILE_ENDPOINT_WITHDRAW_INVITATION', '/users/invitations/%s'),
            'profile_action'    => env('UNIPILE_ENDPOINT_PROFILE_ACTION', '/users/%s'),
            'start_chat'        => env('UNIPILE_ENDPOINT_START_CHAT', '/chats'),
            'send_message'      => env('UNIPILE_ENDPOINT_SEND_MESSAGE', '/chats/%s/messages'),
            'list_chats'        => env('UNIPILE_ENDPOINT_LIST_CHATS', '/chats'),
            'list_messages'     => env('UNIPILE_ENDPOINT_LIST_MESSAGES', '/chats/%s/messages'),
            'send_email'        => env('UNIPILE_ENDPOINT_SEND_EMAIL', '/emails'),
            'list_emails'       => env('UNIPILE_ENDPOINT_LIST_EMAILS', '/emails'),
            'list_calendars'    => env('UNIPILE_ENDPOINT_LIST_CALENDARS', '/calendars'),
            'calendar_events'   => env('UNIPILE_ENDPOINT_CALENDAR_EVENTS', '/calendars/%s/events'),
            'calendar_event'    => env('UNIPILE_ENDPOINT_CALENDAR_EVENT', '/calendars/%s/events/%s'),
        ],
    ],

];
