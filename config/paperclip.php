<?php

return [
    'api_url'    => env('PAPERCLIP_API_URL', 'http://localhost:3100'),
    'api_key'    => env('PAPERCLIP_API_KEY'),
    'company_id' => env('PAPERCLIP_COMPANY_ID'),
    'agent_id'   => env('PAPERCLIP_AGENT_ID'),

    'polling' => [
        // Backoff intervals in seconds; the last value is repeated indefinitely
        'intervals'   => [1, 2, 5, 10, 10, 10],
        'max_seconds' => 1800,
    ],
];
