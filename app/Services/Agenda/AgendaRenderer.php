<?php

namespace App\Services\Agenda;

use Carbon\Carbon;
use App\Models\CalendarEvent;
use App\Models\User;

class AgendaRenderer
{
    public static function renderForWeb(array $data, CalendarEvent $event): string
    {
        $lines = [];

        $date = Carbon::parse($event->starts_at)->format('d.m.Y');
        $time = Carbon::parse($event->starts_at)->format('H:i');
        $lines[] = "📅 {$event->title}";
        $lines[] = "🕐 {$date} · {$time}";

        if (!empty($data['meeting_goal'])) {
            $lines[] = '';
            $lines[] = '🎯 Цель: ' . $data['meeting_goal'];
        }

        if (!empty($data['discussion_topics'])) {
            $lines[] = '';
            $lines[] = '🗂 Темы для обсуждения';
            foreach ($data['discussion_topics'] as $topic) {
                $title = $topic['title'] ?? $topic;
                $desc  = $topic['description'] ?? '';
                $lines[] = "● {$title}";
                if ($desc) {
                    $lines[] = "  {$desc}";
                }
            }
        }

        if (!empty($data['main_problem'])) {
            $lines[] = '';
            $lines[] = '⚠️ Главная проблематика';
            $lines[] = $data['main_problem'];
        }

        if (!empty($data['prev_topics'])) {
            $lines[] = '';
            $lines[] = '🔙 Темы прошлого митинга';
            foreach ($data['prev_topics'] as $topic) {
                $topic   = trim(str_replace(['**', '*'], '', $topic));
                $lines[] = "● {$topic}";
            }
        }

        if (!empty($data['commitments_check'])) {
            $done  = $data['commitments_done'] ?? 0;
            $total = $data['commitments_total'] ?? count($data['commitments_check']);
            $pct   = $total > 0 ? round($done / $total * 100) : 0;

            $lines[] = '';
            $lines[] = "📋 Задачи с прошлого митинга — {$done} из {$total} ({$pct}%)";
            foreach ($data['commitments_check'] as $c) {
                $statusIcon = match ($c['status']) {
                    'готово'   => '✅',
                    'в работе' => '🔄',
                    'отменено' => '❌',
                    default    => '⏳',
                };
                $deadline = $c['deadline'] ? " ({$c['deadline']})" : '';
                $lines[]  = "{$statusIcon} {$c['person']} — {$c['commitment']}{$deadline}";
            }
        }

        if (!empty($data['tasks_between'])) {
            $btTotal = count($data['tasks_between']);
            $btDone  = count(array_filter($data['tasks_between'], fn ($t) => $t['status'] === 'done'));

            $lines[] = '';
            $lines[] = "🔵 Задачи между митингами — {$btDone} из {$btTotal}";
            foreach ($data['tasks_between'] as $t) {
                $icon    = $t['status'] === 'done' ? '✅' : '🔵';
                $lines[] = "{$icon} {$t['assignee']} — {$t['name']}";
            }
        }

        if (!empty($data['backlog_stats'])) {
            $bs        = $data['backlog_stats'];
            $openDelta = $bs['delta_open'] ? " (+{$bs['delta_open']})" : '';
            $doneDelta = $bs['delta_done'] ? " (+{$bs['delta_done']})" : '';
            $lines[]   = '';
            $lines[]   = '📊 Прогресс по бэклогу';
            $lines[]   = "Всего: {$bs['total']} | Открыто: {$bs['open']}{$openDelta} | В работе: {$bs['in_progress']} | Закрыто: {$bs['done']}{$doneDelta}";
            if ($bs['total'] > 0) {
                $pct     = round($bs['done'] / $bs['total'] * 100);
                $lines[] = "Прогресс — {$bs['done']} из {$bs['total']} ({$pct}%)";
            }
        }

        return implode("\n", $lines);
    }

    public static function renderForTelegram(array $data, CalendarEvent $event): string
    {
        $lines = [];

        $date = Carbon::parse($event->starts_at)->format('d.m.Y');
        $time = Carbon::parse($event->starts_at)->format('H:i');
        $lines[] = "<b>{$event->title}</b>";
        $lines[] = "🕐 {$date} · {$time}";

        if (!empty($data['meeting_goal'])) {
            $lines[] = '';
            $lines[] = '<b>Цель:</b> ' . e($data['meeting_goal']);
        }

        if (!empty($data['discussion_topics'])) {
            $lines[] = '';
            $lines[] = '<b>1. Темы для обсуждения</b>';
            foreach ($data['discussion_topics'] as $topic) {
                $title = e($topic['title'] ?? $topic);
                $desc  = e($topic['description'] ?? '');
                $lines[] = "● {$title}";
                if ($desc) {
                    $lines[] = "  <i>{$desc}</i>";
                }
            }
        }

        if (!empty($data['main_problem'])) {
            $lines[] = '';
            $lines[] = '<b>2. Главная проблематика</b>';
            $lines[] = e($data['main_problem']);
        }

        if (!empty($data['prev_topics'])) {
            $lines[] = '';
            $lines[] = '<b>3. Темы прошлого митинга</b>';
            foreach ($data['prev_topics'] as $topic) {
                $topic   = trim(str_replace(['**', '*'], '', $topic));
                $lines[] = '● ' . e($topic);
            }
        }

        if (!empty($data['commitments_check'])) {
            $done  = $data['commitments_done'] ?? 0;
            $total = $data['commitments_total'] ?? count($data['commitments_check']);
            $pct   = $total > 0 ? round($done / $total * 100) : 0;

            $lines[] = '';
            $lines[] = '<b>4. Задачи с прошлого митинга</b>';
            $lines[] = "Выполнено — {$done} из {$total} ({$pct}%)";
            foreach ($data['commitments_check'] as $c) {
                $statusIcon = match ($c['status']) {
                    'готово'   => '✅',
                    'в работе' => '🔄',
                    'отменено' => '❌',
                    default    => '⏳',
                };
                $deadline = $c['deadline'] ? " <i>({$c['deadline']})</i>" : '';
                $lines[]  = "{$statusIcon} <b>" . e($c['person']) . '</b> — ' . e($c['commitment']) . $deadline;
            }
        }

        if (!empty($data['tasks_between'])) {
            $btTotal = count($data['tasks_between']);
            $btDone  = count(array_filter($data['tasks_between'], fn ($t) => $t['status'] === 'done'));

            $lines[] = '';
            $lines[] = '<b>5. Задачи между митингами</b>';
            $lines[] = "Выполнено — {$btDone} из {$btTotal}";
            foreach ($data['tasks_between'] as $t) {
                $icon    = $t['status'] === 'done' ? '✅' : '🔵';
                $lines[] = "{$icon} <b>" . e($t['assignee']) . '</b> — ' . e($t['name']);
            }
        }

        if (!empty($data['backlog_stats'])) {
            $bs        = $data['backlog_stats'];
            $openDelta = $bs['delta_open'] ? " (+{$bs['delta_open']})" : '';
            $doneDelta = $bs['delta_done'] ? " (+{$bs['delta_done']})" : '';
            $lines[]   = '';
            $lines[]   = '<b>6. Прогресс по бэклогу</b>';
            $lines[]   = "Всего: {$bs['total']} | Открыто: {$bs['open']}{$openDelta} | В работе: {$bs['in_progress']} | Закрыто: {$bs['done']}{$doneDelta}";
            if ($bs['total'] > 0) {
                $pct     = round($bs['done'] / $bs['total'] * 100);
                $lines[] = "Прогресс — {$bs['done']} из {$bs['total']} ({$pct}%)";
            }
        }

        return implode("\n", $lines);
    }

    public function renderGeneralContent(array $data): string
    {
        $event = $data['event'] ?? null;
        $lines = [];

        $title = $event?->title ?? 'Встреча';
        $date  = $event ? Carbon::parse($event->starts_at)->format('d.m.Y') : '';
        $time  = $event ? Carbon::parse($event->starts_at)->format('H:i') : '';
        $lines[] = "{$title}";
        $lines[] = "{$date}  ·  {$time}";

        if (!empty($data['discussion_topics'])) {
            $lines[] = '';
            $lines[] = '1. Темы для обсуждения';
            foreach ($data['discussion_topics'] as $topic) {
                $title = $topic['title'] ?? $topic;
                $desc  = $topic['description'] ?? '';
                $lines[] = "● {$title}";
                if ($desc) {
                    $lines[] = "  {$desc}";
                }
            }
        }

        if (!empty($data['main_problem'])) {
            $lines[] = '';
            $lines[] = '2. Главная проблематика';
            $lines[] = $data['main_problem'];
        }

        if (!empty($data['prev_topics'])) {
            $lines[] = '';
            $lines[] = '3. Темы прошлого митинга';
            foreach ($data['prev_topics'] as $topic) {
                $lines[] = "● {$topic}";
            }
        }

        return implode("\n", $lines);
    }

    public function renderPersonalContent(array $data, User $user): string
    {
        $lines   = [];
        $lines[] = "👤 Личная агенда: {$user->name}";
        $lines[] = '';

        if (!empty($data['previous_meeting_recap'])) {
            $lines[] = '🔙 Прошлый митинг:';
            $lines[] = $data['previous_meeting_recap'];
            $lines[] = '';
        }

        if (!empty($data['assigned_tasks'])) {
            $lines[] = '📋 Текущие задачи:';
            foreach ($data['assigned_tasks'] as $task) {
                $due     = !empty($task['due_date']) ? " (срок: {$task['due_date']})" : '';
                $lines[] = "- [{$task['status']}] {$task['name']}{$due}";
            }
            $lines[] = '';
        }

        if (!empty($data['due_by_this_meeting'])) {
            $lines[] = '⏰ Дедлайн до этого митинга:';
            foreach ($data['due_by_this_meeting'] as $task) {
                $lines[] = "- {$task['name']}";
            }
            $lines[] = '';
        }

        if (!empty($data['completed_since_last'])) {
            $lines[] = '✅ Завершённые задачи:';
            foreach ($data['completed_since_last'] as $task) {
                $closed  = !empty($task['close_date']) ? " ({$task['close_date']})" : '';
                $lines[] = "- {$task['name']}{$closed}";
            }
            $lines[] = '';
        }

        if (!empty($data['discussion_points'])) {
            $lines[] = '💬 Темы для обсуждения:';
            foreach ($data['discussion_points'] as $i => $point) {
                $lines[] = ($i + 1) . '. ' . $point;
            }
        }

        return implode("\n", $lines);
    }
}
