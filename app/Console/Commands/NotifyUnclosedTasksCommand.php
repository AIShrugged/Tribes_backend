<?php

namespace App\Console\Commands;

use App\Models\Issue;
use App\Models\Source;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class NotifyUnclosedTasksCommand extends Command
{
    protected $signature = 'notify:unclosed-tasks';

    protected $description = 'Send Telegram reminders about unclosed tasks to each user';

    public function handle(): void
    {
        $userIdsWithMeetingsToday = Source::whereHas('calendarEvents', function ($q) {
            $q->whereDate('starts_at', now()->toDateString());
        })->pluck('user_id');

        if ($userIdsWithMeetingsToday->isEmpty()) {
            return;
        }

        $teamIdsWithMeetings = DB::table('team_user')
            ->whereIn('user_id', $userIdsWithMeetingsToday)
            ->pluck('team_id')
            ->unique();

        if ($teamIdsWithMeetings->isEmpty()) {
            return;
        }

        $users = User::with('telegramUser')
            ->whereHas('telegramUser')
            ->whereHas('teams', fn ($q) => $q->whereIn('teams.id', $teamIdsWithMeetings))
            ->get();

        foreach ($users as $user) {
            $issues = Issue::where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhere('assignee_id', $user->id);
                })
                ->whereNotIn('status', ['done', 'closed'])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', now()->toDateString())
                ->get();

            if ($issues->isEmpty()) {
                continue;
            }

            $this->sendNotification($user, $issues);
        }
    }

    private function sendNotification(User $user, $issues): void
    {
        $telegramUserId = $user->telegramUser->telegram_user_id;
        $text = $this->formatMessage($issues);

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'                  => $telegramUserId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyUnclosedTasks: failed to send', [
                'user_id'          => $user->id,
                'telegram_user_id' => $telegramUserId,
                'error'            => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage($issues): string
    {
        $lines = [];
        $lines[] = '📋 <b>Незакрытые задачи</b>';
        $lines[] = '';

        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        foreach ($issues as $i => $issue) {
            $num = $i + 1;
            $title = e($issue->name);
            $due = $issue->due_date ? ' <i>(' . $issue->due_date->format('d.m') . ')</i>' : '';
            $url = $frontendUrl . '/dashboard/issues/' . $issue->id;
            $lines[] = "{$num}. <a href=\"{$url}\">{$title}</a>{$due}";
        }

        return implode("\n", $lines);
    }
}
