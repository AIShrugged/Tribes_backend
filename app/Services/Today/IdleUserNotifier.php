<?php

namespace App\Services\Today;

use App\Models\Issue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class IdleUserNotifier
{
    private const TERMINAL_STATUSES = ['done', 'closed', 'cancelled'];

    private const SUGGESTION_LIMIT = 5;

    public function notifyForWindow(Carbon $windowStart, Carbon $windowEnd): array
    {
        $stats = [
            'window_start'        => $windowStart->toIso8601String(),
            'window_end'          => $windowEnd->toIso8601String(),
            'candidates'          => 0,
            'users_notified'      => 0,
            'managers_notified'   => 0,
            'skipped_have_tasks'  => 0,
            'skipped_no_telegram' => 0,
        ];

        $candidateUserIds = Issue::query()
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->whereBetween('close_date', [$windowStart, $windowEnd])
            ->whereNotNull('assignee_id')
            ->pluck('assignee_id')
            ->unique()
            ->values();

        $stats['candidates'] = $candidateUserIds->count();

        foreach ($candidateUserIds as $userId) {
            $user = User::with(['telegramUser', 'teams', 'organizations'])->find($userId);
            if (! $user) {
                continue;
            }

            $openCount = Issue::query()
                ->where('assignee_id', $user->id)
                ->whereNotIn('status', self::TERMINAL_STATUSES)
                ->count();

            if ($openCount > 0) {
                $stats['skipped_have_tasks']++;
                continue;
            }

            $suggestions = $this->getSuggestions($user, self::SUGGESTION_LIMIT);

            if ($suggestions->isNotEmpty()) {
                if ($this->send($user, $this->formatUserMessage($suggestions))) {
                    $stats['users_notified']++;
                } else {
                    $stats['skipped_no_telegram']++;
                }
            }

            $managers = $this->getManagers($user);
            $managerText = $this->formatManagerMessage($user, $suggestions);
            foreach ($managers as $manager) {
                if ($this->send($manager, $managerText)) {
                    $stats['managers_notified']++;
                }
            }
        }

        return $stats;
    }

    private function getSuggestions(User $user, int $limit): Collection
    {
        $teamIds = $user->teams->pluck('id');
        $orgIds  = $user->organizations->pluck('id');

        if ($teamIds->isNotEmpty()) {
            $byTeam = $this->baseSuggestionQuery()
                ->whereIn('team_id', $teamIds)
                ->limit($limit)
                ->get();

            if ($byTeam->isNotEmpty()) {
                return $byTeam;
            }
        }

        if ($orgIds->isNotEmpty()) {
            return $this->baseSuggestionQuery()
                ->whereIn('organization_id', $orgIds)
                ->limit($limit)
                ->get();
        }

        return collect();
    }

    private function baseSuggestionQuery()
    {
        return Issue::query()
            ->whereNull('assignee_id')
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->orderByDesc('priority');
    }

    private function getManagers(User $user): Collection
    {
        $orgIds = $user->organizations->pluck('id');
        if ($orgIds->isEmpty()) {
            return collect();
        }

        $managerIds = \DB::table('organization_user')
            ->whereIn('organization_id', $orgIds)
            ->where('role', 'manager')
            ->where('user_id', '!=', $user->id)
            ->pluck('user_id')
            ->unique();

        if ($managerIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $managerIds)
            ->with('telegramUser')
            ->get();
    }

    private function send(User $user, string $text): bool
    {
        if (! $user->telegramUser) {
            return false;
        }

        try {
            (new Api(config('telegram.bot_token')))->sendMessage([
                'chat_id'                  => $user->telegramUser->telegram_user_id,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::warning('IdleUserNotifier: send failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function formatUserMessage(Collection $suggestions): string
    {
        $lines = [];
        $lines[] = '🎯 <b>У вас сейчас нет открытых задач.</b>';
        $lines[] = '';
        $lines[] = '<b>Доступные задачи (можно взять):</b>';
        foreach ($suggestions as $issue) {
            $lines[] = '• ' . $this->formatTaskLine($issue);
        }
        $lines[] = '';
        $lines[] = '<i>Возьмите задачу в работу или попросите менеджера назначить.</i>';

        return implode("\n", $lines);
    }

    private function formatManagerMessage(User $idleUser, Collection $suggestions): string
    {
        $lines = [];
        $name  = e($idleUser->name);

        if ($suggestions->isEmpty()) {
            $lines[] = "⚠️ <b>У {$name} закончились задачи, и в резерве свободных нет.</b>";
            $lines[] = '';
            $lines[] = '<i>Создайте новые задачи или перераспределите.</i>';
        } else {
            $lines[] = "ℹ️ <b>У {$name} закончились задачи.</b>";
            $lines[] = '';
            $lines[] = '<b>Свободные задачи:</b>';
            foreach ($suggestions as $issue) {
                $lines[] = '• ' . $this->formatTaskLine($issue);
            }
            $lines[] = '';
            $lines[] = '<i>Назначьте подходящую.</i>';
        }

        return implode("\n", $lines);
    }

    private function formatTaskLine(Issue $issue): string
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $url  = $frontendUrl . '/dashboard/issues/' . $issue->id;
        $name = e($issue->name);
        $line = "<a href=\"{$url}\">{$name}</a>";

        if ($issue->due_date) {
            $due = Carbon::parse($issue->due_date);
            $line .= ' <i>(до ' . $due->format('d.m') . ')</i>';
        } else {
            $line .= ' <i>(без срока)</i>';
        }

        return $line;
    }
}
