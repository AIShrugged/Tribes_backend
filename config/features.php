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
];
