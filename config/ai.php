<?php

return [
    'providers' => [
        'openrouter' => [
            'api_token' => env('OPENROUTER_API_TOKEN'),
            'models' => [
                'followup' => 'google/gemini-3-pro-preview'
            ]
        ]
    ]
];
