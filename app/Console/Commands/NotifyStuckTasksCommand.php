<?php

namespace App\Console\Commands;

use App\Models\Issue;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class NotifyStuckTasksCommand extends Command
{
    protected $signature = 'notify:stuck-tasks {--test-user= : Send all messages only to this Telegram user ID}';

    protected $description = 'Notify assignees about tasks with no activity for N days';

    public function handle(): int
    {
        $threshold = (int) env('STUCK_DETECTOR_THRESHOLD_DAYS', 3);
        $testUser = $this->option('test-user');

        $users = User::with('telegramUser')
            ->whereHas('telegramUser')
            ->get();

        foreach ($users as $user) {
            $stuckIssues = $this->getStuckIssues($user, $threshold);

            if ($stuckIssues->isEmpty()) {
                continue;
            }

            $this->sendNotification($user, $stuckIssues, $threshold, $testUser);
        }

        return self::SUCCESS;
    }

    private function getStuckIssues(User $user, int $threshold): Collection
    {
        return Issue::where('assignee_id', $user->id)
            ->whereNotIn('status', ['done', 'closed', 'cancelled'])
            ->where('updated_at', '<', now()->subDays($threshold))
            ->where('updated_at', '>=', now()->subDays($threshold + 1))
            ->get();
    }

    private function sendNotification(User $user, Collection $issues, int $threshold, ?string $testUser = null): void
    {
        $telegramUserId = $testUser ?? $user->telegramUser->telegram_user_id;
        $text = $this->formatMessage($issues, $threshold);

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'                  => $telegramUserId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyStuckTasks: failed to send', [
                'user_id'          => $user->id,
                'telegram_user_id' => $telegramUserId,
                'error'            => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage(Collection $issues, int $threshold): string
    {
        $lines = [];
        $lines[] = "🟡 <b>Зависшие задачи — нет активности {$threshold}+ дней</b>";
        $lines[] = '';

        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        foreach ($issues as $issue) {
            $title = e($issue->name);
            $days  = (int) now()->diffInDays($issue->updated_at);
            $url   = $frontendUrl . '/dashboard/issues/' . $issue->id;
            $lines[] = "• <a href=\"{$url}\">{$title}</a> <i>({$days}д без изменений)</i>";
        }

        $lines[] = '';
        $lines[] = 'Обнови статус или закрой задачу, если она уже не актуальна.';

        return implode("\n", $lines);
    }
}
