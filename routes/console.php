<?php

use App\Services\Insight\InsightMaintenanceService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Insight maintenance jobs
Schedule::call(fn() => app(InsightMaintenanceService::class)->runWeeklyConsolidation())
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->name('insight:weekly-consolidation')
    ->withoutOverlapping();

Schedule::call(fn() => app(InsightMaintenanceService::class)->runMonthlyRebuild())
    ->monthly()
    ->name('insight:monthly-rebuild')
    ->withoutOverlapping();

// Enrich Insight profiles from Telegram chat history
Schedule::command('insight:process-telegram')
    ->everyFourHours()
    ->name('insight:process-telegram')
    ->withoutOverlapping();

// Extract tasks from Telegram messages
Schedule::command('tasks:process-telegram')
    ->everyThreeHours()
    ->name('tasks:process-telegram')
    ->withoutOverlapping();

// NOTE: OpenRouter balance monitoring has been removed.
// Monitor Anthropic API usage at https://console.anthropic.com.

Schedule::command('meetings:send-pre-briefs')
    ->everyTenMinutes()
    ->name('meetings:send-pre-briefs')
    ->withoutOverlapping();

Schedule::command('agent-tasks:dispatch --limit='.config('agent.agent_tasks.dispatch_limit', 50))
    ->everyMinute()
    ->name('agent-tasks:dispatch')
    ->withoutOverlapping();

// Meeting agenda generation and delivery
Schedule::command('agenda:generate')
    ->everyFiveMinutes()
    ->name('agenda:generate')
    ->withoutOverlapping();

Schedule::command('agenda:send')
    ->everyFiveMinutes()
    ->name('agenda:send')
    ->withoutOverlapping();

// Daily AI nudge generation for Today briefing page
Schedule::command('today:generate-nudges')
    ->dailyAt('06:00')
    ->name('today:generate-nudges')
    ->withoutOverlapping();
