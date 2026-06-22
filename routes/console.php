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

// US-12.5: Personal pre-meeting brief in each participant's private TG (10-20 min before)
Schedule::command('meetings:send-personal-pre-briefs')
    ->everyTenMinutes()
    ->name('meetings:send-personal-pre-briefs')
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

// Multi-step nudge + manager escalation for stuck Issues (cadence 2/4/6 days)
Schedule::command('notify:stuck-tasks')
    ->dailyAt('10:00')
    ->name('notify:stuck-tasks')
    ->withoutOverlapping(60); // 60 minutes TTL — protect against stuck lock on crashed process

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
// TEMP (2026-06-02): личный дайджест «Критический путь» временно отключён по запросу.
// Чтобы вернуть — раскомментировать блок ниже. Командные CP-уведомления (notifyTeam)
// и пересчёт графа (cpm:process-pending) НЕ затронуты. Команду можно гонять руками:
//   php artisan critical-path:send-daily-reminders --test-user=<tg_id>
// Schedule::command('critical-path:send-daily-reminders')
//     ->dailyAt('09:05')
//     ->name('critical-path:send-daily-reminders')
//     ->withoutOverlapping();

// Prune pending (unbound) issue attachments older than 24 hours
Schedule::command('attachments:prune-orphans')
    ->hourly()
    ->name('attachments:prune-orphans')
    ->withoutOverlapping();

// Pre-generate daily task progress digests for morning brief consumption (06:30)
Schedule::command('tasks:generate-daily-digests')
    ->dailyAt('06:30')
    ->name('tasks:generate-daily-digests')
    ->withoutOverlapping();

// US-12.4: Pre-generate agendas for ALL today's meetings (bypass minutes_before)
// so meetings:advice has data to work with at 08:30.
Schedule::command('agenda:generate --all-today')
    ->dailyAt('07:30')
    ->name('agenda:generate:all-today')
    ->withoutOverlapping();

// US-12.4: Generate per-meeting AI preparation advice for morning brief consumption (08:30)
Schedule::command('tasks:generate-meetings-advice')
    ->dailyAt('08:30')
    ->name('tasks:generate-meetings-advice')
    ->withoutOverlapping();

// US-12.3: Weekly task digest for managers (Saturday morning)
Schedule::command('tasks:send-weekly-digests')
    ->weekly()->saturdays()->at('09:00')
    ->name('tasks:send-weekly-digests')
    ->withoutOverlapping();

// Daily cleanup of expired digests
Schedule::command('digests:prune')
    ->daily()
    ->name('digests:prune')
    ->withoutOverlapping();

// Pre-moderation: fail extraction plans stuck in 'collecting' beyond TTL (anti-strand backstop).
// No-op unless a manual upload has produced plans; never touches the Recall flow.
Schedule::command('extraction:reap-stuck-plans')
    ->everyFifteenMinutes()
    ->name('extraction:reap-stuck-plans')
    ->withoutOverlapping();

// Daily issue health analysis per team
Schedule::command('issues:generate-health-reports')
    ->dailyAt('10:00')
    ->name('issues:generate-health-reports')
    ->withoutOverlapping();

// Prune Telescope entries to prevent unbounded table growth
Schedule::command('telescope:prune --hours=48')
    ->daily()
    ->name('telescope:prune')
    ->withoutOverlapping();

// Monitor queue depths — fires QueueBusy event (logged as error) when a queue
// exceeds the threshold. Tweak --max values based on observed normal load.
Schedule::command(
    'queue:monitor ' .
    '--queues=chat:50,heavy:30,notifications:100,default:100,agent-tasks:20'
)
    ->everyFiveMinutes()
    ->name('queue:monitor')
    ->withoutOverlapping();

// Prune failed jobs older than 7 days to keep the table clean
Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->name('queue:prune-failed')
    ->withoutOverlapping();

// Purge agent-command undo snapshots after 7 days (keeps the audit record itself)
Schedule::command('agent:purge-command-undo --days=7')
    ->daily()
    ->name('agent:purge-command-undo')
    ->withoutOverlapping();
