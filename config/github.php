<?php

return [
    'app_id' => env('GITHUB_APP_ID'),
    'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),
    'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
    'private_key_pem' => env('GITHUB_APP_PRIVATE_KEY_PEM'),
    'api_base_url' => rtrim((string) env('GITHUB_API_BASE_URL', 'https://api.github.com'), '/'),

    // The GitHub App installation can read exactly AIShrugged/Tribes_backend (+ _frontend),
    // so default to the backend repo when the env is unset/stale.
    'default_owner' => env('GITHUB_DEFAULT_OWNER', 'AIShrugged'),
    'default_repo' => env('GITHUB_DEFAULT_REPO', 'Tribes_backend'),
];
