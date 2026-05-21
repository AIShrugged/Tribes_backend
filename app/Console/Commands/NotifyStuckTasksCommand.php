<?php

namespace App\Console\Commands;

use App\Services\Issue\StuckIssueNudgeService;
use Illuminate\Console\Command;

/**
 * Multi-step nudge for stuck Issues + manager escalation.
 *
 * Schedule: dailyAt('10:00')->withoutOverlapping(60) — see routes/console.php.
 *
 * --test-user redirects ALL Telegram sends to the given chat_id, while the real
 * recipient_user_id is still recorded in issue_nudges for audit.
 * NOTE: this is chat_id override — DIFFERENT semantics from
 * SendWeeklyTaskDigestsCommand --test-user which is user.id filter.
 * Chosen for backward-compat + Telegram safety memory rule.
 *
 * --force is REQUIRED to use --test-user in production. Without it the command
 * exits with failure. Using --force in scripts/CI is fine — confirm() would hang
 * in non-interactive contexts, so this is a flag-based guard.
 *
 * NOTE: CLI name `notify:stuck-tasks` retained for backward-compat (public API).
 * Future: deprecate to `notify:stuck-issues` with alias.
 */
class NotifyStuckTasksCommand extends Command
{
    protected $signature = 'notify:stuck-tasks
        {--test-user= : Override chat_id for all sends (debug); requires --force in production}
        {--force : Required to use --test-user in production}';

    protected $description = 'Multi-step nudge + manager escalation for stuck Issues';

    public function handle(StuckIssueNudgeService $service): int
    {
        $testUser = $this->option('test-user');
        $force = $this->option('force');

        if ($testUser && app()->environment('production') && ! $force) {
            $this->error('--test-user in production requires --force. All TG sends would be redirected.');

            return self::FAILURE;
        }

        $stats = $service->run(testTelegramUserId: $testUser);
        $this->info(json_encode($stats, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
