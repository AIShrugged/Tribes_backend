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

Schedule::command('agent-tasks:dispatch --limit='.config('agent.agent_tasks.dispatch_limit', 50))
    ->everyMinute()
    ->name('agent-tasks:dispatch')
    ->withoutOverlapping();
