<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Source;
use App\Models\User;
use App\Services\Today\TaskDeadlineGrouper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class SendMorningBriefCommand extends Command
{
    protected $signature = 'meetings:send-morning-brief {--test-user= : Send all messages only to this Telegram user ID}';

    protected $description = 'Send morning brief with today\'s meetings and open tasks to each user';

    public function __construct(private readonly TaskDeadlineGrouper $taskGrouper)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $today    = now()->toDateString();
        $testUser = $this->option('test-user');

        $users = User::with('telegramUser')
            ->whereHas('telegramUser')
            ->get();

        foreach ($users as $user) {
            $meetings = $this->getTodayMeetings($user, $today);
            $groups   = $this->taskGrouper->groupForUser($user);

            $hasIssues = ($groups['focused'] ?? collect())->isNotEmpty()
                || $groups['today']->isNotEmpty()
                || $groups['current']->isNotEmpty();

            if ($meetings->isEmpty() && ! $hasIssues) {
                continue;
            }

            $this->sendBrief($user, $meetings, $groups, $testUser);
        }

        return self::SUCCESS;
    }

    private function getTodayMeetings(User $user, string $today): Collection
    {
        $sourceIds = Source::where('user_id', $user->id)->pluck('id');

        return CalendarEvent::query()
            ->where(function ($q) use ($user, $sourceIds) {
                $q->whereHas('sources', fn($sq) => $sq->where('user_id', $user->id));
                if ($sourceIds->isNotEmpty()) {
                    $q->orWhereIn('source_id', $sourceIds);
                }
                $q->orWhereHas('profiles', fn($pq) => $pq->where('user_id', $user->id));
            })
            ->whereDate('starts_at', $today)
            ->orderBy('starts_at')
            ->get();
    }

    private function sendBrief(User $user, Collection $meetings, array $groups, ?string $testUser = null): void
    {
        $telegramUserId = $testUser ?? $user->telegramUser->telegram_user_id;
        $text = $this->formatMessage($meetings, $groups, $user->name, (bool) $testUser);

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

    private function formatMessage(Collection $meetings, array $groups, string $userName = '', bool $testMode = false): string
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

        $focused = $groups['focused'] ?? collect();
        $hasIssues = $focused->isNotEmpty()
            || $groups['today']->isNotEmpty()
            || $groups['current']->isNotEmpty();

        if ($hasIssues) {
            $lines[] = '';
            $lines[] = '📋 <b>Задачи:</b>';

            if ($focused->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '⭐ <b>В фокусе:</b>';
                foreach ($focused as $issue) {
                    $lines[] = '• ' . $this->formatTaskLine($issue);
                }
            }

            if ($groups['today']->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '🟠 <b>Срочно — сегодня дедлайн:</b>';
                foreach ($groups['today'] as $issue) {
                    $lines[] = '• ' . $this->formatTaskLine($issue);
                }
            }

            if ($groups['current']->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '🔵 <b>Текущие задачи:</b>';
                foreach ($groups['current'] as $issue) {
                    $lines[] = '• ' . $this->formatTaskLine($issue);
                }
            }
        }

        return implode("\n", $lines);
    }

    private function formatTaskLine(Issue $issue): string
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $url  = $frontendUrl . '/dashboard/issues/' . $issue->id;
        $name = e($issue->name);
        $line = "<a href=\"{$url}\">{$name}</a>";

        $today = Carbon::today();
        $due   = $issue->due_date ? Carbon::parse($issue->due_date) : null;

        if ($due && $due->lt($today)) {
            $days = (int) $due->diffInDays($today);
            $line .= " <i>({$days}д просрочено)</i>";
        } elseif ($due && $due->gt($today)) {
            $line .= ' <i>(до ' . $due->format('d.m') . ')</i>';
        }

        return $line;
    }
}
