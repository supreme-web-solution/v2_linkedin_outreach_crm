<?php

return [
    'enabled' => env('SOCIFUSION_AI_ENABLED', true),

    'kill_switch' => env('SOCIFUSION_AI_KILL_SWITCH', false),

    /*
    | Default autonomy when no ai_employee_settings row exists.
    | 1=copilot, 2=assisted, 3=autopilot, 4=autonomous
    | Product default: Assisted. Autopilot/Autonomous are explicit opt-in.
    */
    'default_autonomy_level' => (int) env('SOCIFUSION_AI_DEFAULT_AUTONOMY', 2),

    'employee_name' => env('SOCIFUSION_AI_EMPLOYEE_NAME', 'Soci'),

    /** Max prospects fetched/saved in one discover_prospects or manual Instagram search pull. */
    'max_prospect_pull' => (int) env('SOCI_MAX_PROSPECT_PULL', 100),

    'persona' => <<<'TXT'
You are {employee_name}, the AI Sales Employee for SociFusion — one Command Center brain for web and WhatsApp.
Live workspace awareness (hot inbox, pending LAUNCH, campaigns, workflows) is your radar: surface what needs the owner and act within autonomy.
You do not invent CRM data — use tools. Semantic turn plan + tool descriptions own routing; do not invent a parallel playbook.
Outbound/inbox copy must be recipient-facing final text (never operator instructions or placeholders). Prefer draft_cold_outbound for single-contact cold sends; draft_reply for existing inbox threads.
Inbox conversion ladder is owned by classify_reply + ConversionNextAction: qualify → one asset link → book_meeting as last card.
Deletes always need Confirm Delete. Bulk pause/activate/delete uses one tool call with campaign_ids[] (or delete_all_outreach).
When a prospect shares a URL (lines marked URL: https://...), call research_prospect — never truncate or claim the link is incomplete.
Campaign titles: short theme labels (≤50 chars) in campaign_name; keep detailed goal separately.
Instagram ICP discovery uses discover_prospects platform=instagram with a keyword query (not @handles first). WhatsApp/Telegram need save_contacts with identifiers the user provides.
Never claim you messaged anyone unless an execute tool succeeded. Keep replies concise and action-oriented.
Builder attribution only when asked who built SociFusion/Soci: William Victor — https://www.linkedin.com/in/vicken-concept/
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
        | Show WhatsApp "typing..." while Soci prepares a reply (Zernio inbox API).
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
    /*
    | When a prospect replies in Unified Inbox, Soci researches the message (links/company),
    | drafts a tailored reply, posts it to Command Center, and LAUNCHes or auto-sends by autonomy.
    */
    'proactive_inbound_reply' => env('SOCIFUSION_AI_PROACTIVE_INBOUND_REPLY', true),
    'proactive_inbound_whatsapp' => env('SOCIFUSION_AI_PROACTIVE_INBOUND_WHATSAPP', true),
    'proactive_inbound_queue_name' => env('SOCIFUSION_AI_PROACTIVE_INBOUND_QUEUE_NAME', 'webhooks'),
    'command_center_mirror_whatsapp' => env('SOCIFUSION_AI_MIRROR_WHATSAPP', true),

    /*
    | Attention digests: twice daily (morning/evening) ONLY when inbox still needs you.
    | Not on Command Center open, not every 30 minutes. Instant alerts = hot inbound only.
    */
    'attention_digest' => [
        'enabled' => env('SOCIFUSION_AI_ATTENTION_DIGEST', true),
        'morning_at' => env('SOCIFUSION_AI_ATTENTION_DIGEST_MORNING', '08:00'),
        'evening_at' => env('SOCIFUSION_AI_ATTENTION_DIGEST_EVENING', '18:00'),
        'post_on_command_center_open' => env('SOCIFUSION_AI_ATTENTION_DIGEST_ON_OPEN', false),
        'mirror_whatsapp' => env('SOCIFUSION_AI_ATTENTION_DIGEST_WHATSAPP', true),
        'whatsapp_after_proactive' => env('SOCIFUSION_AI_ATTENTION_DIGEST_WHATSAPP_AFTER_PROACTIVE', false),
        'max_items' => (int) env('SOCIFUSION_AI_ATTENTION_DIGEST_MAX_ITEMS', 2),
        'whatsapp_max_items' => (int) env('SOCIFUSION_AI_ATTENTION_DIGEST_WHATSAPP_MAX_ITEMS', 2),
        'queue_name' => env('SOCIFUSION_AI_ATTENTION_DIGEST_QUEUE', 'default'),
    ],

    /*
    | Instant Command Center + WhatsApp ping when a NEW hot inbound arrives.
    | Skipped when Soci auto-sent (Autopilot). Non-hot threads wait for morning/evening digest.
    */
    'instant_inbound_notify' => [
        'enabled' => env('SOCIFUSION_AI_INSTANT_INBOUND_NOTIFY', true),
        'hot_only' => env('SOCIFUSION_AI_INSTANT_INBOUND_HOT_ONLY', true),
        'skip_when_ai_handled' => env('SOCIFUSION_AI_INSTANT_INBOUND_SKIP_AUTO_SENT', true),
    ],

    /*
    | Daily AI activity summary (once per day) — for in-app report; optional WhatsApp later.
    */
    'daily_ai_summary' => [
        'enabled' => env('SOCIFUSION_AI_DAILY_SUMMARY', false),
        'at' => env('SOCIFUSION_AI_DAILY_SUMMARY_AT', '09:00'),
    ],

    'web_chat_queue' => env('SOCIFUSION_AI_WEB_CHAT_QUEUE', true),
    'web_chat_queue_name' => env('SOCIFUSION_AI_WEB_CHAT_QUEUE_NAME', 'webhooks'),
    'web_chat_agent_queue_name' => env('SOCIFUSION_AI_WEB_CHAT_AGENT_QUEUE_NAME', 'default'),

    /** Queue for durable workflow continuation ticks (ContinueWorkflowRunJob). */
    'workflow_queue_name' => env('SOCIFUSION_AI_WORKFLOW_QUEUE_NAME', 'default'),
    'workflow_job_timeout' => (int) env('SOCIFUSION_AI_WORKFLOW_JOB_TIMEOUT', 600),
    'workflow_job_tries' => (int) env('SOCIFUSION_AI_WORKFLOW_JOB_TRIES', 5),
    'web_chat_job_timeout' => (int) env('SOCIFUSION_AI_WEB_CHAT_JOB_TIMEOUT', 600),
    'web_chat_job_tries' => (int) env('SOCIFUSION_AI_WEB_CHAT_JOB_TRIES', 3),
    'web_chat_stale_seconds' => (int) env('SOCIFUSION_AI_WEB_CHAT_STALE_SECONDS', 90),
    'web_chat_redispatch_attempts' => (int) env('SOCIFUSION_AI_WEB_CHAT_REDISPATCH_ATTEMPTS', 3),

    /*
    | LLM semantic turn planner (replaces regex as primary interpretation layer).
    | Falls back to IntentGoalResolverService when disabled or provider unavailable.
    */
    'semantic_turn_planner' => env('SOCIFUSION_AI_SEMANTIC_TURN_PLANNER', true),

    /*
    | Deprecated: inbox/cold outbound preflights were removed; agent tools own those turns.
    | Kept so old .env keys do not break config load.
    */
    'outbound_preflight' => false,

    /*
    | Agent model chain (Laravel AI provider failover — used by Soci planner, copy, quality, etc.).
    | Order matters: first funded provider runs; on 429 / overloaded / no credits / outage,
    | Laravel AI fails over to the next key that is actually set.
    |
    | OpenRouter is the multi-model cover slot (one key → Gemini, Claude, etc.). Prefer a
    | NON-OpenAI OpenRouter model so OpenAI overload does not also fail the OpenRouter hop.
    | Native gemini / anthropic / groq keys are extra cover when present.
    | Regex / heuristic fallbacks only run after this entire chain is exhausted.
    */
    'model_failover' => [
        'openai' => env('SOCIFUSION_AI_OPENAI_MODEL'),
        'openrouter' => env('SOCIFUSION_AI_OPENROUTER_MODEL', 'google/gemini-2.5-flash'),
        'gemini' => env('SOCIFUSION_AI_GEMINI_MODEL', 'gemini-2.5-flash'),
        'anthropic' => env('SOCIFUSION_AI_ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
        'groq' => env('SOCIFUSION_AI_GROQ_MODEL', 'llama-3.3-70b-versatile'),
    ],

    /*
    | When OpenAI is in the chain and OpenRouter is configured with an openai/* model,
    | swap OpenRouter to this cover model so failover actually leaves the OpenAI fleet.
    */
    'openrouter_non_openai_cover' => env(
        'SOCIFUSION_AI_OPENROUTER_COVER_MODEL',
        'google/gemini-2.5-flash'
    ),

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
    | Outreach channels for Soci (prospect-facing Unipile channels).
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

    /*
    | Workspace copy prefs live in ai_employee_settings.meta.copy_prefs:
    | tone, preferred_angle, style_notes, do_not_say[], proof_points[{title,outcome,industry,integration,summary,url}]
    | Fed into OutboundCopyAgent via composer — domain-agnostic, no phrase blacklists.
    */
    'copy_prefs' => [
        'max_proof_points' => 5,
        'max_do_not_say' => 20,
    ],

    /*
    | Sequence follow-ups use empty body + personalize_before_send (AI path).
    | Never ship shared "just checking in" template copy as the default.
    */
    'sequence_defaults' => [
        'personalize_follow_ups' => true,
    ],

    /*
    | Prospect intelligence web research via Laravel AI provider tools.
    | Uses keys already in config/ai.php — no separate signup.
    |
    | Provider support:
    | - openai: WebSearch only (uses OPENAI_API_KEY)
    | - anthropic: WebSearch + WebFetch (uses ANTHROPIC_API_KEY)
    | - openrouter / gemini: both tools
    |
    | Chain: JINA → Laravel AI WebFetch → HTTP (URLs)
    |        domain guess → Laravel AI WebSearch → DuckDuckGo (companies)
    */
    'web_research' => [
        'enabled' => env('SOCIFUSION_AI_WEB_RESEARCH', true),
        'provider' => env('SOCIFUSION_AI_WEB_RESEARCH_PROVIDER', 'openai'),
        'model' => env('SOCIFUSION_AI_WEB_RESEARCH_MODEL'),
        'max_searches' => (int) env('SOCIFUSION_AI_WEB_RESEARCH_MAX_SEARCHES', 2),
        'timeout' => (int) env('SOCIFUSION_AI_WEB_RESEARCH_TIMEOUT', 45),
    ],
];
