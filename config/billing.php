<?php

return [

    'require_entitlement' => env('BILLING_REQUIRE_ENTITLEMENT', true),

    'jvzoo_secret' => env('JVZOO_SECRET'),

    'platform_admin_emails' => array_filter(array_map(
        'trim',
        explode(',', env('PLATFORM_ADMIN_EMAILS', ''))
    )),

    'entitlements' => [
        'FE',
        'OTO1',
        'OTO2',
        'OTO3',
        'OTO4',
        'OTO5',
        'OTO6',
        'OTO7',
        'OTO8',
        'Bundle',
    ],

    'bundles' => [
        'fe' => ['FE'],
        'reseller' => ['FE', 'OTO5'],
        'full' => ['FE', 'OTO1', 'OTO2', 'OTO3', 'OTO4', 'OTO5', 'OTO6', 'OTO7', 'OTO8', 'Bundle'],
    ],

];
