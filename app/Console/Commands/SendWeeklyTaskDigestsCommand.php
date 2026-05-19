<?php

namespace App\Console\Commands;

use App\Models\TaskDigest;
use App\Models\User;
use App\Notifications\ProgressNotification;
use App\Services\Digest\TaskDigestService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Telegram\Bot\Api;

/**
 * Weekly digest: managers only in v1.
 *
 * Unlike daily (which is pre-generated at 06:30 and consumed by morning brief at 09:00),
 * weekly is inline: generated + sent in one command. Weekly cadence allows synchronous LLM.
 */
class SendWeeklyTaskDigestsCommand extends Command
{
    protected $signature = 'tasks:send-weekly-digests
        {--test-user= : Filter to this user.id only (digest goes to that user\'s real TG)}
        {--dry-run : Log targets without actually generating/sending}';

    protected $description = 'Generate and send weekly task progress digests to organization managers.';

    public function handle(TaskDigestService $service): int
    {
        if (! config('features.enable_weekly_digest', false)) {
            $this->info('Weekly digest disabled (ENABLE_WEEKLY_DIGEST=false). Skipping.');
            return self::SUCCESS;
        }

        $testUser = $this->option('test-user');
        $dryRun = (bool) $this->option('dry-run');
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        // Managers only — query org_user pivot for role=manager, intersected with TelegramUser presence.
        // `wherePivot` only works on the relation itself, not inside whereHas — use raw column reference.
        $query = User::query()
            ->whereHas('telegramUser')
            ->whereHas('organizations', fn ($q) => $q->where('organization_user.role', 'manager'));

        if ($testUser) {
            $query->where('id', (int) $testUser);
        }

        $count = 0;
        $generated = 0;

        $query->with('organizations')->chunkById(100, function ($managers) use (&$count, &$generated, $weekStart, $dryRun, $service, $testUser) {
            foreach ($managers as $manager) {
                foreach ($manager->organizations as $org) {
                    if (($org->pivot->role ?? null) !== 'manager') {
                        continue; // only manager-role orgs
                    }
                    $count++;

                    if ($dryRun) {
                        $this->line(sprintf('DRY: manager=%d (%s) org=%d (%s)', $manager->id, $manager->name, $org->id, $org->name));
                        continue;
                    }

                    $content = $service->generate($manager, $org, TaskDigest::PERIOD_WEEKLY, $weekStart);
                    if ($content !== null) {
                        $generated++;
                        // Dashboard notification (DB-write first, transactional)
                        try {
                            $manager->notify(new ProgressNotification($content));
                        } catch (\Throwable $e) {
                            Log::warning('SendWeeklyTaskDigests: notify failed', [
                                'user_id' => $manager->id,
                                'org_id' => $org->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                        // Then TG send (fire-and-forget) — always to manager's real TG.
                        // --test-user only filters which users get processed, never overrides chat_id.
                        $this->sendTelegram($manager, $content);
                    }
                }
            }
        });

        $this->info(sprintf(
            'Weekly digest: evaluated %d (manager, org) pairs, generated+sent %d%s.',
            $count,
            $generated,
            $dryRun ? ' (dry-run, none sent)' : '',
        ));

        return self::SUCCESS;
    }

    private function sendTelegram(User $manager, array $content): void
    {
        $tgUserId = $manager->telegramUser?->telegram_user_id;
        if (! $tgUserId) {
            return;
        }

        $lines = [];
        $lines[] = '📊 <b>Еженедельный digest</b>';

        if (! empty($content['progress'])) {
            $lines[] = '';
            $lines[] = '<b>Прогресс:</b>';
            foreach ((array) $content['progress'] as $p) {
                $lines[] = '• ' . e((string) $p);
            }
        }

        if (! empty($content['problems'])) {
            $lines[] = '';
            $lines[] = '<b>Проблемы:</b>';
            foreach ((array) $content['problems'] as $p) {
                $text = is_array($p) ? ($p['text'] ?? '') : (string) $p;
                $sev = is_array($p) ? ($p['severity'] ?? 'M') : null;
                $marker = $sev === 'H' ? '🔴' : ($sev === 'M' ? '🟡' : '🔵');
                $lines[] = "{$marker} " . e((string) $text);
            }
        }

        if (! empty($content['priorities'])) {
            $lines[] = '';
            $lines[] = '<b>Приоритеты на неделю:</b>';
            foreach ((array) $content['priorities'] as $pr) {
                $lines[] = '• ' . e((string) $pr);
            }
        }

        $goals = $content['manager_extras']['organization_goals'] ?? [];
        if (is_array($goals) && ! empty($goals)) {
            $lines[] = '';
            $lines[] = '🎯 <b>Цели организации:</b>';
            foreach ($goals as $g) {
                if (! is_array($g)) continue;
                $name = e((string) ($g['name'] ?? '—'));
                $children = $g['children'] ?? [];
                $done = (int) ($children['done'] ?? 0);
                $total = (int) ($children['total'] ?? 0);
                $overdue = (int) ($children['overdue'] ?? 0);
                $pct = (int) ($children['progress_pct'] ?? 0);
                $marker = $overdue > 0 ? '🔴' : ($total === 0 ? '⚪' : ($pct >= 50 ? '🟢' : '🟡'));
                $line = "{$marker} <b>{$name}</b> — ";
                if ($total > 0) {
                    $line .= "{$done}/{$total} ({$pct}%)";
                    if ($overdue > 0) $line .= ", {$overdue} просрочены";
                } else {
                    $line .= '0 подзадач — нет декомпозиции';
                }
                $lines[] = $line;
            }
        }

        $commentary = (array) ($content['goals_commentary'] ?? []);
        if (! empty($commentary)) {
            $lines[] = '';
            $lines[] = '🧭 <b>По целям:</b>';
            foreach ($commentary as $c) {
                $lines[] = '• ' . e((string) $c);
            }
        }

        $extras = $content['manager_extras']['teams_breakdown'] ?? null;
        if (is_array($extras) && ! empty($extras)) {
            $lines[] = '';
            $lines[] = '📈 <b>Команды:</b>';
            foreach ($extras as $team) {
                if (! is_array($team)) continue;
                $name = e((string) ($team['team_name'] ?? '—'));
                $done = (int) ($team['done'] ?? 0);
                $inProgress = (int) ($team['in_progress'] ?? 0);
                $overdue = (int) ($team['overdue'] ?? 0);
                $lines[] = "• <b>{$name}:</b> done {$done}, in_progress {$inProgress}, overdue {$overdue}";
            }
        }

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id' => $tgUserId,
                'text' => implode("\n", $lines),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendWeeklyTaskDigests: send failed', [
                'user_id' => $manager->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
