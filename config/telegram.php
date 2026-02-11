<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Bot Token
    |--------------------------------------------------------------------------
    |
    | Your Telegram Bot API token from @BotFather
    |
    */
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    'allowed_users' => env('TELEGRAM_ALLOWED_USERS', ''),
];