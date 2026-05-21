<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SQL Allowed Tables
    |--------------------------------------------------------------------------
    |
    | Tables the agent is permitted to query via execute_sql_query tool.
    | This list is the single source of truth used by both SqlQueryExecutor
    | (enforcement) and DatabaseSchemaService (schema generation for the LLM).
    |
    */

    'sql_allowed_tables' => [
        'users',
        'organizations',
        'organization_user',
        'teams',
        'team_user',
        'sources',
        'source_oauths',
        'calendar_events',
        'calendar_event_profile',
        'participants',
        'transcript_entries',
        'followups',
        'methodologies',
        'profiles',
        'channels',
        'meeting_summaries',
        'issues',
        'insight_sources',
        'insight_items',
        'insight_profiles',
        'insight_profile_history',
        'insight_relationships',
        'insight_short_term',
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Column Blacklist
    |--------------------------------------------------------------------------
    |
    | Columns the agent must never see or retrieve, keyed by table name.
    | DatabaseSchemaService excludes these from the schema description shown
    | to the LLM. SqlQueryExecutor strips them from query results and blocks
    | explicit references in SQL strings.
    |
    */

    'sql_column_blacklist' => [
        'users' => ['password', 'remember_token'],
        'source_oauths' => ['access_token', 'refresh_token'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Routing
    |--------------------------------------------------------------------------
    |
    | Centralized defaults for agent task types. Settings values may override
    | these keys via `model.<task_type>`.
    |
    */

    'models' => [
        'interactive' => 'anthropic/claude-sonnet-4.6',
        'summarization' => 'anthropic/claude-sonnet-4.6',
        'extraction' => 'openai/gpt-4.1-mini',
        'background' => 'anthropic/claude-sonnet-4.6',
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Compaction
    |--------------------------------------------------------------------------
    |
    | Keep recent messages verbatim and fold older turns into a deterministic
    | summary before sending them to the LLM.
    |
    */

    'compaction' => [
        'keep_recent_messages' => 8,
        'max_summary_chars' => 2500,
    ],

    /*
    |--------------------------------------------------------------------------
    | In-Run Tool Result Masking
    |--------------------------------------------------------------------------
    |
    | Controls how tool results are masked between iterations of the agentic
    | loop. Batch-aware masking keeps all results from the last N iterations
    | instead of the last N individual messages, preventing hallucinations
    | when the LLM makes parallel tool calls within one iteration.
    |
    */

    'in_run_masking' => [
        'keep_recent_iterations' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | In-Run LLM Compaction
    |--------------------------------------------------------------------------
    |
    | After a long run (threshold iterations), makes an extra LLM call to
    | compress accumulated tool results into a compact working memory block
    | injected into the system prompt. Disabled by default — enable when
    | agents regularly exceed 7+ iterations.
    |
    */

    'in_run_compaction' => [
        'enabled'            => false,
        'threshold'          => 7,
        'model'              => 'openai/gpt-4.1-mini',
        'max_summary_tokens' => 800,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram Coalescing
    |--------------------------------------------------------------------------
    |
    | Burst Telegram messages are batched into a single agent turn.
    |
    */

    'telegram' => [
        'coalesce_window_seconds' => 4,
        'typing_interval_seconds' => 4,
        'typing_ttl_seconds' => 150,
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Chat Runs
    |--------------------------------------------------------------------------
    |
    | Retry policy and runtime metadata for async web chat agent runs.
    |
    */

    'chat' => [
        'max_attempts' => 3,
        'backoff_seconds' => [10, 30],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled Agent Tasks
    |--------------------------------------------------------------------------
    |
    | Runtime settings for one-off and interval-based agent tasks.
    |
    */

    'agent_tasks' => [
        'dispatch_limit' => 50,
        'lock_ttl_seconds' => 3900,
        'backoff_seconds' => [30, 120],
        'default_timeout_seconds' => 1800,
        'max_timeout_seconds' => 1800,
        'followups' => [
            'max_depth' => 5,
            'max_delay_seconds' => 86400,
            'max_per_run' => 10,
        ],
        'sandbox_run_token_ttl_seconds' => 3600,
        'sandbox_internal_base_url' => env('SANDBOX_INTERNAL_BASE_URL', 'http://app'),
        'default_sandbox_image' => env('AGENT_TASK_SANDBOX_IMAGE', 'spodial-agent-python:latest'),
        'sandbox_network' => env('AGENT_TASK_SANDBOX_NETWORK', 'bridge'),
        'sandbox_host_runs_root' => env('AGENT_TASK_SANDBOX_HOST_RUNS_ROOT'),
        'sandbox_run_root' => env('AGENT_TASK_SANDBOX_RUN_ROOT', sys_get_temp_dir().'/spodial-sandbox-runs'),
        'persistent_workspace_root' => env('AGENT_TASK_PERSISTENT_WORKSPACE_ROOT'),
        'sandbox_cpus' => env('AGENT_TASK_SANDBOX_CPUS', '2'),
        'sandbox_memory' => env('AGENT_TASK_SANDBOX_MEMORY', '2g'),
    ],

];
