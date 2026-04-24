<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class SendMorningBriefCommand extends Command
{
    protected $signature = 'meetings:send-morning-brief {--test-user= : Send all messages only to this Telegram user ID}';

    protected $description = 'Send morning brief with today\'s meetings and open tasks to each user';

    public function handle(): int
    {
        $today    = now()->toDateString();
        $testUser = $this->option('test-user');

        $users = User::with('telegramUser')
            ->whereHas('telegramUser')
            ->get();

        foreach ($users as $user) {
            $meetings = $this->getTodayMeetings($user, $today);
            $issues   = $this->getOpenIssues($user);

            if ($meetings->isEmpty() && $issues->isEmpty()) {
                continue;
            }

            $this->sendBrief($user, $meetings, $issues, $testUser);
        }

        return self::SUCCESS;
    }

    private function getTodayMeetings(User $user, string $today): Collection
    {
        return CalendarEvent::whereHas('sources', fn($q) => $q->where('user_id', $user->id))
            ->whereDate('starts_at', $today)
            ->orderBy('starts_at')
            ->get();
    }

    private function getOpenIssues(User $user): Collection
    {
        return Issue::where('assignee_id', $user->id)
            ->whereNotIn('status', ['done', 'closed', 'cancelled'])
            ->get();
    }

    private function groupIssues(Collection $issues): array
    {
        $today    = Carbon::today();
        $weekAgo  = Carbon::today()->subDays(7);
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        $groups = [
            'overdue' => [],
            'today'   => [],
            'stuck'   => [],
            'other'   => [],
        ];

        foreach ($issues as $issue) {
            $dueDate = $issue->due_date ? Carbon::parse($issue->due_date) : null;
            $createdAt = $issue->registration_date
                ? Carbon::parse($issue->registration_date)
                : Carbon::parse($issue->created_at);

            $url  = $frontendUrl . '/dashboard/issues/' . $issue->id;
            $name = e($issue->name);
            $link = "<a href=\"{$url}\">{$name}</a>";

            if ($dueDate && $dueDate->lt($today)) {
                $days = $dueDate->diffInDays($today);
                $groups['overdue'][] = "{$link} <i>({$days}д просрочено)</i>";
            } elseif ($dueDate && $dueDate->isSameDay($today)) {
                $groups['today'][] = "{$link} <i>(сегодня)</i>";
            } elseif ($createdAt->lte($weekAgo)) {
                $groups['stuck'][] = $link;
            } else {
                $groups['other'][] = $link;
            }
        }

        return $groups;
    }

    private function sendBrief(User $user, Collection $meetings, Collection $issues, ?string $testUser = null): void
    {
        $telegramUserId = $testUser ?? $user->telegramUser->telegram_user_id;
        $text = $this->formatMessage($meetings, $issues, $user->name, (bool) $testUser);

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'                  => $telegramUserId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendMorningBrief: failed to send', [
                'user_id'          => $user->id,
                'telegram_user_id' => $telegramUserId,
                'error'            => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage(Collection $meetings, Collection $issues, string $userName = '', bool $testMode = false): string
    {
        $lines = [];
        if ($testMode) {
            $lines[] = "🧪 <i>TEST — данные пользователя: {$userName}</i>";
            $lines[] = '';
        }
        $lines[] = '☀️ <b>Доброе утро!</b>';

        if ($meetings->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '📅 <b>Встречи сегодня:</b>';
            foreach ($meetings as $meeting) {
                $start = Carbon::parse($meeting->starts_at)->format('H:i');
                $line  = "• {$start} — " . e($meeting->title);
                if ($meeting->ends_at) {
                    $duration = Carbon::parse($meeting->starts_at)->diffInMinutes(Carbon::parse($meeting->ends_at));
                    $line .= " <i>({$duration} мин)</i>";
                }
                if ($meeting->url) {
                    $line .= ' <a href="' . e($meeting->url) . '">🔗</a>';
                }
                $lines[] = $line;
            }
        }

        if ($issues->isNotEmpty()) {
            $groups = $this->groupIssues($issues);

            $lines[] = '';
            $lines[] = '📋 <b>Задачи:</b>';

            if (!empty($groups['overdue'])) {
                $lines[] = '';
                $lines[] = '🔴 <b>Просрочено:</b>';
                foreach ($groups['overdue'] as $item) {
                    $lines[] = "• {$item}";
                }
            }

            if (!empty($groups['today'])) {
                $lines[] = '';
                $lines[] = '🟠 <b>Срочно — сегодня дедлайн:</b>';
                foreach ($groups['today'] as $item) {
                    $lines[] = "• {$item}";
                }
            }

            if (!empty($groups['stuck'])) {
                $lines[] = '';
                $lines[] = '🟡 <b>Зависшие — открыто больше недели:</b>';
                foreach ($groups['stuck'] as $item) {
                    $lines[] = "• {$item}";
                }
            }

            if (!empty($groups['other'])) {
                $lines[] = '';
                $lines[] = '⚪ <b>Остальное:</b>';
                foreach ($groups['other'] as $item) {
                    $lines[] = "• {$item}";
                }
            }
        }

        return implode("\n", $lines);
    }
}
