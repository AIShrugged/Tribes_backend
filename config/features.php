<?php

/**
 * Feature flags for staged rollout of Helper-Agent notification features.
 *
 * Defaults are ON. Use .env to explicitly disable a feature if needed
 * (e.g. ENABLE_DAILY_DIGEST=false for incidents).
 */
return [
    'enable_daily_digest' => env('ENABLE_DAILY_DIGEST', true),
    'enable_weekly_digest' => env('ENABLE_WEEKLY_DIGEST', true),
    'enable_personal_premeeting_brief' => env('ENABLE_PERSONAL_PREMEETING_BRIEF', true),

    /*
     * Master kill-switch for the autonomous, LLM-generating scheduled jobs
     * (nudges, digests, agendas, meeting advice, health reports, Telegram
     * extraction, insight consolidation/rebuild). When false, those scheduled
     * commands are not dispatched at all — the scheduler keeps running and all
     * non-AI jobs (prunes, queue:monitor, openrouter:check-balance, critical
     * path, notification delivery) are unaffected.
     *
     * Scope: the SCHEDULED layer only. Reactive LLM work (Recall transcript
     * extraction, interactive agent chat) is not gated here.
     *
     * Toggle: set ENABLE_AI_SCHEDULER=false, then rebuild the config cache
     * (`php artisan config:cache`, or `config:clear`). schedule:work spawns a
     * fresh schedule:run subprocess every minute, so the change takes effect on
     * the next tick — no scheduler restart required.
     */
    'enable_ai_scheduler' => env('ENABLE_AI_SCHEDULER', true),

    /*
     * Separate switch for the per-minute agent-task dispatcher. Kept apart from
     * enable_ai_scheduler on purpose: agent-tasks:dispatch also drives
     * user-initiated agent work (commit-report agent, paperclip, etc.), not
     * just morning generation, so it can be paused independently.
     */
    'enable_agent_tasks_dispatch' => env('ENABLE_AGENT_TASKS_DISPATCH', true),
];
