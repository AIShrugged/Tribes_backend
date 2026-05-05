<?php

use App\Services\Insight\InsightMaintenanceService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Insight maintenance jobs
Schedule::call(fn () => app(InsightMaintenanceService::class)->runWeeklyConsolidation())
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->name('insight:weekly-consolidation')
    ->withoutOverlapping();

Schedule::call(fn () => app(InsightMaintenanceService::class)->runMonthlyRebuild())
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

// OpenRouter balance monitoring
Schedule::command('openrouter:check-balance --morning')
    ->dailyAt('09:00')
    ->name('openrouter:check-balance:morning')
    ->withoutOverlapping();

Schedule::command('openrouter:check-balance')
    ->dailyAt('15:00')
    ->name('openrouter:check-balance:afternoon')
    ->withoutOverlapping();

Schedule::command('openrouter:check-balance')
    ->dailyAt('19:00')
    ->name('openrouter:check-balance:evening')
    ->withoutOverlapping();

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

// Morning brief: today's meetings + open tasks
Schedule::command('meetings:send-morning-brief')
    ->dailyAt('09:00')
    ->name('meetings:send-morning-brief')
    ->withoutOverlapping();

// Stuck Detector: tasks with no activity for N days
Schedule::command('notify:stuck-tasks')
    ->dailyAt('10:00')
    ->name('notify:stuck-tasks')
    ->withoutOverlapping();

// Idle users: closed last task in previous hour and now have 0 open
Schedule::command('tasks:notify-idle-users')
    ->hourly()
    ->name('tasks:notify-idle-users')
    ->withoutOverlapping();

// Critical Path: flush pending-issue buffer and run incremental/full rebuild per org
Schedule::command('cpm:process-pending')
    ->twiceDaily(3, 15)
    ->name('cpm:process-pending')
    ->withoutOverlapping();

// Critical Path: one batched personal reminder per participant in the morning
Schedule::command('critical-path:send-daily-reminders')
    ->dailyAt('09:05')
    ->name('critical-path:send-daily-reminders')
    ->withoutOverlapping();

// Prune pending (unbound) issue attachments older than 24 hours
Schedule::command('attachments:prune-orphans')
    ->hourly()
    ->name('attachments:prune-orphans')
    ->withoutOverlapping();
