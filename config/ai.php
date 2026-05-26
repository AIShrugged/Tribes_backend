<?php

return [
    'providers' => [
        'openrouter' => [
            'api_token' => env('OPENROUTER_API_TOKEN'),
            'models'    => [
                'followup'        => 'google/gemini-3.1-pro-preview',
                'scheme'          => 'google/gemini-3.1-pro-preview',
                'wanda'           => 'google/gemini-3.1-pro-preview',
                'telegram_agent'  => 'anthropic/claude-sonnet-4-5',
                'meeting_summary' => 'google/gemini-3.1-pro-preview',
                'meeting_review'  => 'google/gemini-3.1-pro-preview',
                'meeting_tasks'   => 'google/gemini-3.1-pro-preview',
                'insight'         => 'google/gemini-3.1-pro-preview',
                'demo'            => 'google/gemini-3.1-pro-preview',
                'agenda'          => 'google/gemini-3.1-pro-preview',
                'today_nudge'     => 'google/gemini-3.1-pro-preview',
                'digest'          => 'google/gemini-3.1-pro-preview',
                'critical_path'   => env('AI_MODEL_CRITICAL_PATH', 'google/gemini-3.1-pro-preview'),
                'onboarding'      => env('AI_MODEL_ONBOARDING', 'google/gemini-3.1-pro-preview'),
                'transcript_format_detector' => env('AI_MODEL_TRANSCRIPT_FORMAT_DETECTOR', 'google/gemini-3.1-pro-preview'),
            ],
            'fallback_models' => [
                // anthropic/claude-3.5-sonnet was deprecated on OpenRouter (404 No endpoints found),
                // which silently broke the fallback chain whenever gemini-3.1-pro-preview truncated.
                'anthropic/claude-sonnet-4-5',
                'openai/gpt-4o-mini',
            ],
        ]
    ],

    'bot_name' => 'Bot',

    // Extended thinking budget (tokens). Applies to all interactive agent runs.
    // Keep max_tokens >= thinking_budget + 2000 (hard invariant).
    'thinking_budget' => (int) env('AGENT_THINKING_BUDGET', 4000),
    'agent_max_tokens' => (int) env('AGENT_MAX_TOKENS', 16000),

    'monitoring' => [
        'balance_threshold'        => 5,
        'telegram_chat_id'         => -1003705371486,
        'telegram_message_thread_id' => 334,
    ],
];
