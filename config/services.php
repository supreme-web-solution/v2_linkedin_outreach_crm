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

    'openai' => [
        'key' => env('OPENAI_API_KEY', env('OPENAI_KEY')),
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
        'daily_limit_per_user' => env('DAILY_EMAIL_SCRAPING_LIMIT', 100),
    ],

    'competitor_followers' => [
        'company_posts_limit' => env('COMPETITOR_POSTS_LIMIT', 15),
        'page_size' => env('COMPETITOR_PAGE_SIZE', 100),
        'max_engagers_per_post' => env('COMPETITOR_MAX_ENGAGERS_PER_POST', 500),
        'max_posts_scan' => env('COMPETITOR_MAX_POSTS_SCAN', 30),
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
        ],
    ],

];
