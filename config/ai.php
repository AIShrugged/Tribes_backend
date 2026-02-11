<?php

return [
    'providers' => [
        'openrouter' => [
            'api_token' => env('OPENROUTER_API_TOKEN'),
            'models'    => [
                'followup'        => 'google/gemini-3-pro-preview',
                'scheme'          => 'google/gemini-3-pro-preview',
                'wanda'           => 'google/gemini-3-pro-preview',
                'telegram_agent'  => 'anthropic/claude-3.5-sonnet',
            ]
        ]
    ]
];
