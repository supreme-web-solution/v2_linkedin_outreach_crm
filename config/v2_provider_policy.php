<?php

return [
    'default_provider' => 'unipile',

    'fallbacks' => [
        'lead_search' => ['unipile'],
        'profile_enrichment' => ['unipile'],
        'feed_discovery' => ['rapidapi'],
        'post_engagers' => ['unipile'],
        'campaign_send_invitation' => ['unipile'],
        'campaign_send_message' => ['unipile'],
        'campaign_start_chat' => ['unipile'],
        'campaign_profile_action' => ['unipile'],
        'campaign_post_action' => ['unipile', 'rapidapi'],
        'campaign_invitation_action' => ['unipile'],
        'campaign_read_state' => ['unipile'],
    ],

    'strict_unipile_actions' => [
        'account_connect',
        'account_reconnect',
        'send_invitation',
        'accept_invitation',
        'send_message',
        'list_chats',
        'list_messages',
    ],
];
