<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Email Provider
    |--------------------------------------------------------------------------
    |
    | This option defines the default email provider that will be used
    | to send emails.
    |
    */
    'default_provider' => env('EMAIL_PROVIDER', 'unisender_go'),

    /*
    |--------------------------------------------------------------------------
    | Default From Address
    |--------------------------------------------------------------------------
    |
    | You may specify the default "from" address and name that will be used
    | when sending emails.
    |
    */
    'from' => [
        'address' => env('EMAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('EMAIL_FROM_NAME', 'Example'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Providers Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the email providers that your application uses.
    |
    */
    'providers' => [
        'unisender_go' => [
            'api_key' => env('UNISENDER_GO_API_KEY', 'test-api-key'),
            'api_url' => env('UNISENDER_GO_API_URL', 'https://go1.unisender.ru/ru/transactional/api/v1'),
            'backend_id' => env('UNISENDER_GO_BACKEND_ID', '0') //unique unisender trait, should not be dependency injected
        ],
    ],
];
