<?php

return [
    'enabled' => env('SOCIFUSION_AI_ENABLED', true),

    'kill_switch' => env('SOCIFUSION_AI_KILL_SWITCH', false),

    /*
    | Default autonomy when no ai_employee_settings row exists.
    | 1=copilot, 2=assisted, 3=autopilot, 4=autonomous
    | Product default: Autopilot (doc "full Autonomous" deferred until undo UI matures).
    */
    'default_autonomy_level' => (int) env('SOCIFUSION_AI_DEFAULT_AUTONOMY', 3),

    'employee_name' => env('SOCIFUSION_AI_EMPLOYEE_NAME', 'Alex'),

    'persona' => <<<'TXT'
You are {employee_name}, the AI Sales Employee for SociFusion — the Command Center brain.
Users talk to you from the web app or WhatsApp; it is the same conversation and the same tools.
You help achieve sales goals: find prospects, build multichannel campaigns (LinkedIn + Email by default; WhatsApp, Instagram, and Telegram are full outreach channels when the user asks), monitor replies, and recommend next actions.
When the user states a goal, you plan and execute: auto-search LinkedIn for matching profiles when no list exists, stage campaigns, and launch when ready (Autopilot+ launches automatically).
Do not ask for competitor LinkedIn URLs before trying discover_prospects / LinkedIn auto-search.
You do not invent CRM data — use tools. Prefer clear plan cards and ask for Launch/Approve before sending or launching in Assisted mode.
Deletes (campaigns, etc.) ALWAYS require Confirm Delete — never auto-delete, even in Autopilot or Autonomous.
Never claim you messaged a prospect unless an execute tool succeeded.
Keep responses concise and action-oriented. On WhatsApp, favor short bullets.
TXT,

    'zernio' => [
        'base_url' => env('ZERNIO_BASE_URL', 'https://api.zernio.com'),
        'api_key' => env('ZERNIO_API_KEY'),
        'webhook_secret' => env('ZERNIO_WEBHOOK_SECRET'),
        'from_number' => env('ZERNIO_FROM_NUMBER'),
        'account_id' => env('ZERNIO_ACCOUNT_ID'),
        'use_inbox_api' => env('ZERNIO_USE_INBOX_API', true),
        /*
        | Process AI messages on a queue so Zernio gets a fast 200 response.
        | Link-code pairing stays synchronous.
        */
        'queue_inbound' => env('ZERNIO_QUEUE_INBOUND', true),
        /*
        | Show WhatsApp "typing..." while Alex prepares a reply (Zernio inbox API).
        | Refreshed every typing_refresh_seconds because WhatsApp clears after ~25s.
        */
        'typing_indicator' => env('ZERNIO_TYPING_INDICATOR', true),
        'typing_refresh_seconds' => (int) env('ZERNIO_TYPING_REFRESH_SECONDS', 18),
        'max_message_length' => (int) env('ZERNIO_MAX_MESSAGE_LENGTH', 1024),
    ],

    /*
    | Web / widget Command Center chat: queue LLM turns so navigation is never blocked.
    | Control commands (LAUNCH, etc.) still run synchronously in the HTTP request.
    */
    'web_chat_queue' => env('SOCIFUSION_AI_WEB_CHAT_QUEUE', true),
    'web_chat_queue_name' => env('SOCIFUSION_AI_WEB_CHAT_QUEUE_NAME', 'webhooks'),

    'link_code_ttl_minutes' => 15,

    /*
    | Tools allowed to auto-execute when autonomy >= autopilot (3).
    | Empty = none auto-execute (approve everything).
    */
    'default_allowed_execute_tools' => [
        'pause_outreach_campaign',
        'activate_outreach_campaign',
        'send_inbox_reply',
        'move_lead_to_nurture',
    ],

    /*
    | Outreach channels for Alex (prospect-facing Unipile channels).
    | Primary = default recommendations. Secondary = suggest when user asks or ICP fits.
    | Command Center WhatsApp (Zernio) is separate — not listed here.
    */
    'outreach_channels' => [
        'primary' => ['linkedin', 'email'],
        'secondary' => ['whatsapp', 'instagram', 'telegram', 'twitter'],
        'default_label' => 'LinkedIn + Email',
    ],

    /*
    | When autonomy is Autopilot (3) or Autonomous (4), only allowlisted execute
    | tools may run without a separate approval. All prepare tools still use
    | Review & Launch unless Copilot mode (1).
    | Destructive tools (delete_*) are never allowlisted and never auto-execute.
    */
];
