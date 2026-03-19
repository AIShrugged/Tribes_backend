<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workspace Storage
    |--------------------------------------------------------------------------
    |
    | Agent workspaces are stored in object storage behind Laravel's filesystem
    | abstraction. The disk defaults to the S3-compatible driver so local
    | environments can use MinIO and production can switch to AWS S3 later.
    |
    */

    'disk' => env('WORKSPACE_FILESYSTEM_DISK', 's3'),

    'storage_prefix' => trim((string) env('WORKSPACE_STORAGE_PREFIX', 'workspaces'), '/'),

];
