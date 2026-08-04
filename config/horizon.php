<?php

use Illuminate\Support\Str;

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => env('HORIZON_REDIS_CONNECTION', 'default'),

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web', 'auth'],

    /*
    | Long waits for outreach/campaigns are intentional: LinkedIn / Unipile /
    | enrichment work can sit behind rate limits and retries.
    */
    'waits' => [
        'redis:default' => 60,
        'redis:outreach' => 30,
        'redis:campaigns' => 45,
        'redis:webhooks' => 30,
        'redis:enrichment' => 90,
    ],

    /*
    | Keep recent/completed short. Failed retention is 1 day so Redis does not
    | grow on a small Forge box; bump if you need longer failure forensics.
    */
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 1440,
        'failed' => 1440,
        'monitored' => 1440,
    ],

    'silenced' => [
        //
    ],

    'silenced_tags' => [
        //
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    /*
    | Tuned for a ~4 GB Forge host shared with Nginx, PHP-FPM, MySQL, Redis
    | and other apps. Keep total Horizon processes low.
    |
    | - supervisor-main: outreach / campaigns / webhooks / harvest / misc
    | - supervisor-enrichment: FullEnrich + profile email/phone lookups only
    |   (slow, isolated so enrich clicks cannot starve competitor harvest)
    */
    'defaults' => [
        'supervisor-main' => [
            'connection' => 'redis',
            'queue' => ['outreach', 'campaigns', 'webhooks', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 900,
            'sleep' => 3,
            'maxJobs' => 500,
            'maxTime' => 3600,
            'force' => true,
            'nice' => 0,
        ],
        'supervisor-enrichment' => [
            'connection' => 'redis',
            'queue' => ['enrichment'],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 900,
            'sleep' => 3,
            'maxJobs' => 200,
            'maxTime' => 3600,
            'force' => true,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            // Cap main at 2 so enrich (1) + main (2) ≤ 3 workers total on the shared 4GB box.
            'supervisor-main' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'force' => true,
            ],
            'supervisor-enrichment' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
                'force' => true,
            ],
        ],

        'local' => [
            'supervisor-main' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-enrichment' => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
