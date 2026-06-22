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
        // insight_* tables removed from raw-SQL access (Stage 0 hardening): they hold
        // PII / psychological profiles. The agent reads insights only via dedicated,
        // tenant-scoped tools (get_user_insights, get_insight_profile_history,
        // get_relationship_insight, get_user_short_term_memory). Comprehensive raw-SQL
        // scoping for the remaining tables is superseded by the Stage 1 structured-query
        // layer + TenantScopeGate.
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
        'enabled' => false,
        'threshold' => 7,
        'model' => 'openai/gpt-4.1-mini',
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
        // Recent-history window loaded into the interactive web-chat run.
        // Mirrors the Telegram worker so long chats don't load unbounded history.
        'history_limit' => 30,
        'history_window_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Interactive Run Budget
    |--------------------------------------------------------------------------
    |
    | Wall-clock and per-LLM-call limits for INTERACTIVE agent runs (web chat,
    | Telegram). Agent-task runs are unaffected and keep the client default.
    |
    */

    'run' => [
        'max_seconds' => (int) env('AGENT_RUN_MAX_SECONDS', 180),
        'llm_timeout_seconds' => (int) env('AGENT_RUN_LLM_TIMEOUT_SECONDS', 180),
        // Stop-flag TTL must outlive a full run so /stop stays effective.
        'stop_flag_ttl_seconds' => (int) env('AGENT_RUN_STOP_FLAG_TTL_SECONDS', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Estimation
    |--------------------------------------------------------------------------
    |
    | Rough chars-per-token divisor used for the in-run token budget. Counted
    | with mb_strlen (characters, not bytes) so Cyrillic isn't over-counted ~2x.
    |
    */

    'token_estimation' => [
        'chars_per_token' => (float) env('AGENT_CHARS_PER_TOKEN', 3.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Context Limits
    |--------------------------------------------------------------------------
    |
    | Approximate context window (tokens) per model id, used to drive in-run
    | masking/compaction. Falls back to `default` for unlisted models.
    |
    */

    'model_context_limits' => [
        'default' => 200000,
        'anthropic/claude-sonnet-4.6' => 200000,
        'anthropic/claude-sonnet-4-5' => 200000,
        'openai/gpt-4.1-mini' => 1000000,
        'openai/gpt-4o-mini' => 128000,
        'google/gemini-3.1-pro-preview' => 1000000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Router (two-phase tool selection)
    |--------------------------------------------------------------------------
    |
    | When enabled, a cheap pre-pass model picks which tool categories are
    | relevant to the user's message, and only those (plus the always-on core)
    | are exposed to the main interactive loop — instead of sending all ~50 tool
    | schemas on every LLM call. Applies to INTERACTIVE runs only; on any failure
    | it falls back to the full toolset. Enabled by default — set
    | AGENT_TOOL_ROUTER_ENABLED=false to turn it off.
    |
    */

    'tool_router' => [
        'enabled' => (bool) env('AGENT_TOOL_ROUTER_ENABLED', true),
        'model' => env('AGENT_TOOL_ROUTER_MODEL', 'openai/gpt-4.1-mini'),
        'timeout_seconds' => (int) env('AGENT_TOOL_ROUTER_TIMEOUT_SECONDS', 12),
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
        'sandbox_run_root' => env('AGENT_TASK_SANDBOX_RUN_ROOT', sys_get_temp_dir().'/tribesmcp-sandbox-runs'),
        'persistent_workspace_root' => env('AGENT_TASK_PERSISTENT_WORKSPACE_ROOT'),
        'sandbox_cpus' => env('AGENT_TASK_SANDBOX_CPUS', '2'),
        'sandbox_memory' => env('AGENT_TASK_SANDBOX_MEMORY', '2g'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Commit Report (changelog agent)
    |--------------------------------------------------------------------------
    |
    | Pass-2 fan-out depth/cap and the Pass-1 issue-search call budget.
    |
    */

    'commit_report' => [
        'review' => [
            'max_subruns_per_report' => (int) env('COMMIT_REPORT_REVIEW_MAX_SUBRUNS', 30),
            'sub_run_max_attempts' => (int) env('COMMIT_REPORT_REVIEW_MAX_ATTEMPTS', 2),
            // Under QUEUE_CONNECTION=sync, dispatch would re-enter inline sub-runs synchronously.
            'skip_under_sync' => (bool) env('COMMIT_REPORT_REVIEW_SKIP_UNDER_SYNC', true),
        ],
        'match' => [
            'search_budget_per_run' => (int) env('COMMIT_REPORT_SEARCH_BUDGET', 8),
        ],
    ],

];
