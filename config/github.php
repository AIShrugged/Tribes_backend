<?php

return [
    'app_id' => env('GITHUB_APP_ID'),
    'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),
    'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
    'api_base_url' => rtrim((string) env('GITHUB_API_BASE_URL', 'https://api.github.com'), '/'),

    'default_owner' => env('GITHUB_DEFAULT_OWNER', ''),
    'default_repo' => env('GITHUB_DEFAULT_REPO', ''),
];
