<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Second Brain (multi-tenant sidecar) orchestration
    |--------------------------------------------------------------------------
    |
    | The dedicated `brain-orchestrator` service (php artisan brain:reconcile
    | --watch) runs one Claude Code container per enabled organization. These
    | knobs control the image, the docker network, per-container resource caps
    | and the safety ceiling on how many containers may run at once.
    |
    | Per-org secrets (TRIBESMCP_TOKEN + the org's Claude credential) live in the
    | `second_brain_instances` table, NOT here.
    |
    */

    // Image tag the reconciler runs (built from docker/second-brain).
    'image' => env('SECOND_BRAIN_IMAGE', 'spodial-second-brain:latest'),

    // Build context used by ensureImageBuilt() when the image is missing.
    'build_context' => env('SECOND_BRAIN_BUILD_CONTEXT', base_path('docker/second-brain')),

    // Docker network the org containers join (so they resolve the `nginx` alias).
    // Defaults to the same network the agent-task sandboxes use.
    'network' => env('SECOND_BRAIN_NETWORK', env('AGENT_TASK_SANDBOX_NETWORK', '')),

    // Gateway base URL the container posts brain events to. Empty => auto-resolve
    // the real nginx container name on the network (Coolify-safe).
    'internal_base_url' => env('SECOND_BRAIN_INTERNAL_BASE_URL', env('SANDBOX_INTERNAL_BASE_URL', '')),

    // Per-container resource caps.
    'cpus' => env('SECOND_BRAIN_CPUS', '1'),
    'memory' => env('SECOND_BRAIN_MEMORY', '1g'),
    'pids_limit' => (int) env('SECOND_BRAIN_PIDS_LIMIT', 512),

    // Hard ceiling on concurrently running org containers (host protection).
    'max_containers' => (int) env('SECOND_BRAIN_MAX_CONTAINERS', 20),

    // Watch-loop cadence for `brain:reconcile --watch` (seconds).
    'reconcile_interval' => (int) env('SECOND_BRAIN_RECONCILE_INTERVAL', 15),

    // Passed through to each org container (see docker/second-brain/entrypoint.sh).
    'restart_delay' => env('BRAIN_SESSION_RESTART_DELAY', 60),
    'allow_status_writes' => env('BRAIN_ALLOW_STATUS_WRITES', 'false'),

    // Optional shared GitHub token (repos are cloned anonymously by default).
    'github_token' => env('BRAIN_GITHUB_TOKEN', ''),

];
