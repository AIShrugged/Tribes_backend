<?php

return [
    'providers' => [
        'openrouter' => [
            'api_token' => env('OPENROUTER_API_TOKEN'),
            'models'    => [
                'followup'        => 'google/gemini-2.5-pro-preview',
                'scheme'          => 'google/gemini-2.5-pro-preview',
                'wanda'           => 'google/gemini-2.5-pro-preview',
                'telegram_agent'  => 'anthropic/claude-3.5-sonnet',
                'meeting_summary' => 'google/gemini-2.5-pro-preview',
                'meeting_review'  => 'google/gemini-2.5-pro-preview',
                'meeting_tasks'   => 'google/gemini-2.5-pro-preview',
                'insight'         => 'google/gemini-2.5-pro-preview',
                'demo'            => 'google/gemini-2.5-pro-preview',
                'agenda'          => 'google/gemini-2.5-pro-preview',
            ],
            'fallback_models' => [
                'anthropic/claude-3.5-sonnet',
                'openai/gpt-4o-mini',
            ],
        ]
    ],

    'monitoring' => [
        'balance_threshold'        => 5,
        'telegram_chat_id'         => -1003705371486,
        'telegram_message_thread_id' => 334,
    ],
];
