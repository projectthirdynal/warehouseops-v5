<?php

use Illuminate\Support\Str;

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_horizon:'),
    'middleware' => ['web'],
    'waits' => ['redis:default' => 60, 'redis:imports' => 300, 'redis:shop-webhooks' => 30],
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'silenced' => [],
    'metrics' => [
        'trim_snapshots' => ['job' => 24, 'queue' => 24],
    ],
    'fast_termination' => false,
    'memory_limit' => 512,
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 10,
            'minProcesses' => 1,
            'balanceCooldown' => 3,
            'balanceMaxShift' => 1,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-imports' => [
            'connection' => 'redis',
            'queue' => ['imports'],
            'balance' => 'simple',
            'maxProcesses' => 3,
            'minProcesses' => 1,
            'tries' => 1,
            'timeout' => 7200, // 2 hours — accommodates very large files (500k+ rows)
            'memory' => 1024,  // 1 GB per worker — FastExcel + batch arrays need headroom
            'nice' => 5,
        ],
        'supervisor-shop-webhooks' => [
            'connection' => 'redis',
            'queue' => ['shop-webhooks'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 5,
            'minProcesses' => 1,
            'balanceCooldown' => 3,
            'balanceMaxShift' => 1,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],
    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 2,
            ],
            'supervisor-imports' => [
                'maxProcesses' => 3,
                'timeout' => 7200,
                'memory' => 1024,
            ],
            'supervisor-shop-webhooks' => [
                'maxProcesses' => 8,
                'balanceMaxShift' => 2,
            ],
        ],
        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
            'supervisor-imports' => [
                'maxProcesses' => 1,
                'timeout' => 7200,
                'memory' => 1024,
            ],
            'supervisor-shop-webhooks' => [
                'maxProcesses' => 2,
            ],
        ],
    ],
];
