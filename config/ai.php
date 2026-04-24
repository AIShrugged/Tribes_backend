<?php

return [
    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'models'  => [
                'followup'        => 'claude-sonnet-4-6',
                'scheme'          => 'claude-sonnet-4-6',
                'wanda'           => 'claude-sonnet-4-6',
                'telegram_agent'  => 'claude-sonnet-4-6',
                'meeting_summary' => 'claude-sonnet-4-6',
                'meeting_review'  => 'claude-sonnet-4-6',
                'meeting_tasks'   => 'claude-sonnet-4-6',
                'insight'         => 'claude-sonnet-4-6',
                'demo'            => 'claude-sonnet-4-6',
                'agenda'          => 'claude-sonnet-4-6',
                'today_nudge'     => 'claude-sonnet-4-6',
            ],
            'fallback_models' => [
                'claude-haiku-4-5-20251001',
            ],
        ],
    ],

    'bot_name' => 'Bot',

    'monitoring' => [
        'balance_threshold'          => 5,
        'telegram_chat_id'           => -1003705371486,
        'telegram_message_thread_id' => 334,
    ],
];
