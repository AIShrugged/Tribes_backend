<?php

namespace App\Services\Agenda;

use App\Models\AgendaTemplate;
use App\Models\CalendarEvent;
use App\Models\User;
use Carbon\Carbon;

class AgendaRenderer
{
    public function renderForTelegram(array $data, CalendarEvent $event, ?AgendaTemplate $template = null): string
    {
        $lines = [
            "<b>{$event->title}</b>",
            '🕐 ' . Carbon::parse($event->starts_at)->format('d.m.Y') . ' · ' . Carbon::parse($event->starts_at)->format('H:i'),
        ];

        foreach ($this->sections($template) as $section) {
            $body = $this->renderSection($section, $data, 'telegram');
            if ($body !== '') {
                $lines[] = '';
                $lines[] = $body;
            }
        }

        return implode("\n", $lines);
    }

    public function renderForWeb(array $data, CalendarEvent $event, ?AgendaTemplate $template = null): string
    {
        $lines = [
            "📅 {$event->title}",
            '🕐 ' . Carbon::parse($event->starts_at)->format('d.m.Y') . ' · ' . Carbon::parse($event->starts_at)->format('H:i'),
        ];

        foreach ($this->sections($template) as $section) {
            $body = $this->renderSection($section, $data, 'web');
            if ($body !== '') {
                $lines[] = '';
                $lines[] = $body;
            }
        }

        return implode("\n", $lines);
    }

    private function sections(?AgendaTemplate $template): array
    {
        return $template?->orderedSections() ?? AgendaTemplate::DEFAULT_SECTIONS;
    }

    private function renderSection(string $section, array $data, string $mode): string
    {
        if (empty($data[$section]) && ! in_array($section, ['backlog_stats', 'commitments_check'], true)) {
            return '';
        }

        return match ($section) {
            'meeting_goal'         => $this->renderMeetingGoal($data['meeting_goal'] ?? null, $mode),
            'discussion_topics'    => $this->renderDiscussionTopics($data['discussion_topics'] ?? [], $mode),
            'main_problem'         => $this->renderMainProblem($data['main_problem'] ?? null, $mode),
            'prev_topics'          => $this->renderPrevTopics($data['prev_topics'] ?? [], $mode),
            'commitments_check'    => $this->renderCommitmentsCheck($data, $mode),
            'tasks_between'        => $this->renderTasksBetween($data['tasks_between'] ?? [], $mode),
            'backlog_stats'        => $this->renderBacklogStats($data['backlog_stats'] ?? null, $mode),
            'tg_topics'            => $this->renderTgTopics($data['tg_topics'] ?? [], $mode),
            'next_meeting_context' => $this->renderNextMeetingContext($data['next_meeting_context'] ?? null, $mode),
            'follow_up_items'      => $this->renderFollowUpItems($data['follow_up_items'] ?? [], $mode),
            'open_questions'       => $this->renderOpenQuestions($data['open_questions'] ?? [], $mode),
            'focus_areas'          => $this->renderFocusAreas($data['focus_areas'] ?? [], $mode),
            default                => '',
        };
    }

    private function renderMeetingGoal(?string $value, string $mode): string
    {
        if (! $value) {
            return '';
        }

        return $mode === 'telegram'
            ? '<b>Цель:</b> ' . e($value)
            : '🎯 Цель: ' . $value;
    }

    private function renderDiscussionTopics(array $topics, string $mode): string
    {
        if (empty($topics)) {
            return '';
        }

        $lines = [$mode === 'telegram' ? '<b>Темы для обсуждения</b>' : '🗂 Темы для обсуждения'];
        foreach ($topics as $topic) {
            $title = $topic['title'] ?? $topic;
            $desc  = $topic['description'] ?? '';
            if ($mode === 'telegram') {
                $lines[] = '● ' . e($title);
                if ($desc) {
                    $lines[] = '  <i>' . e($desc) . '</i>';
                }
            } else {
                $lines[] = "● {$title}";
                if ($desc) {
                    $lines[] = "  {$desc}";
                }
            }
        }

        return implode("\n", $lines);
    }

    private function renderMainProblem(?string $value, string $mode): string
    {
        if (! $value) {
            return '';
        }

        return $mode === 'telegram'
            ? "<b>Главная проблематика</b>\n" . e($value)
            : "⚠️ Главная проблематика\n{$value}";
    }

    private function renderPrevTopics(array $topics, string $mode): string
    {
        if (empty($topics)) {
            return '';
        }

        $lines = [$mode === 'telegram' ? '<b>Темы прошлого митинга</b>' : '🔙 Темы прошлого митинга'];
        foreach ($topics as $topic) {
            $clean = trim(str_replace(['**', '*'], '', $topic));
            $lines[] = $mode === 'telegram' ? '● ' . e($clean) : "● {$clean}";
        }

        return implode("\n", $lines);
    }

    private function renderCommitmentsCheck(array $data, string $mode): string
    {
        $check = $data['commitments_check'] ?? [];
        if (empty($check)) {
            return '';
        }

        $done  = $data['commitments_done']  ?? 0;
        $total = $data['commitments_total'] ?? count($check);
        $pct   = $total > 0 ? round($done / $total * 100) : 0;

        $lines = [];
        if ($mode === 'telegram') {
            $lines[] = '<b>Задачи с прошлого митинга</b>';
            $lines[] = "Выполнено — {$done} из {$total} ({$pct}%)";
        } else {
            $lines[] = "📋 Задачи с прошлого митинга — {$done} из {$total} ({$pct}%)";
        }

        foreach ($check as $c) {
            $icon     = match ($c['status']) {
                'готово'   => '✅',
                'в работе' => '🔄',
                'отменено' => '❌',
                default    => '⏳',
            };
            $deadline = $c['deadline'] ?? null;
            if ($mode === 'telegram') {
                $tail = $deadline ? " <i>({$deadline})</i>" : '';
                $lines[] = "{$icon} <b>" . e($c['person']) . '</b> — ' . e($c['commitment']) . $tail;
            } else {
                $tail = $deadline ? " ({$deadline})" : '';
                $lines[] = "{$icon} {$c['person']} — {$c['commitment']}{$tail}";
            }
        }

        return implode("\n", $lines);
    }

    private function renderTasksBetween(array $tasks, string $mode): string
    {
        if (empty($tasks)) {
            return '';
        }

        $total = count($tasks);
        $done  = count(array_filter($tasks, fn ($t) => ($t['status'] ?? null) === 'done'));

        $lines = [];
        if ($mode === 'telegram') {
            $lines[] = '<b>Задачи между митингами</b>';
            $lines[] = "Выполнено — {$done} из {$total}";
        } else {
            $lines[] = "🔵 Задачи между митингами — {$done} из {$total}";
        }

        foreach ($tasks as $t) {
            $icon = ($t['status'] ?? null) === 'done' ? '✅' : '🔵';
            if ($mode === 'telegram') {
                $lines[] = "{$icon} <b>" . e($t['assignee']) . '</b> — ' . e($t['name']);
            } else {
                $lines[] = "{$icon} {$t['assignee']} — {$t['name']}";
            }
        }

        return implode("\n", $lines);
    }

    private function renderBacklogStats(?array $bs, string $mode): string
    {
        if (! $bs) {
            return '';
        }

        $openDelta = ! empty($bs['delta_open']) ? " (+{$bs['delta_open']})" : '';
        $doneDelta = ! empty($bs['delta_done']) ? " (+{$bs['delta_done']})" : '';

        $lines = [];
        $lines[] = $mode === 'telegram' ? '<b>Прогресс по бэклогу</b>' : '📊 Прогресс по бэклогу';
        $lines[] = "Всего: {$bs['total']} | Открыто: {$bs['open']}{$openDelta} | В работе: {$bs['in_progress']} | Закрыто: {$bs['done']}{$doneDelta}";

        if (($bs['total'] ?? 0) > 0) {
            $pct = round(($bs['done'] ?? 0) / $bs['total'] * 100);
            $lines[] = "Прогресс — {$bs['done']} из {$bs['total']} ({$pct}%)";
        }

        return implode("\n", $lines);
    }

    private function renderTgTopics(array $topics, string $mode): string
    {
        if (empty($topics)) {
            return '';
        }

        $lines = [$mode === 'telegram' ? '📱 <b>Новые темы из Telegram:</b>' : '📱 Новые темы из Telegram:'];
        foreach ($topics as $topic) {
            $created = $topic['created_at'] ?? '';
            $author  = $topic['author'] ?? '';
            $text    = $topic['text'] ?? '';
            if ($mode === 'telegram') {
                $lines[] = '• [' . e($created) . '] ' . e($author) . ': ' . e($text);
            } else {
                $lines[] = "• [{$created}] {$author}: {$text}";
            }
        }

        return implode("\n", $lines);
    }

    private function renderNextMeetingContext(?string $value, string $mode): string
    {
        if (! $value) {
            return '';
        }

        return $mode === 'telegram'
            ? "<b>🎯 Контекст следующей встречи</b>\n" . e($value)
            : "🎯 Контекст следующей встречи\n{$value}";
    }

    private function renderFollowUpItems(array $items, string $mode): string
    {
        if (empty($items)) {
            return '';
        }

        $lines = [$mode === 'telegram' ? '<b>✅ Follow-up</b>' : '✅ Follow-up'];
        foreach ($items as $item) {
            $lines[] = $mode === 'telegram' ? '● ' . e($item) : "● {$item}";
        }

        return implode("\n", $lines);
    }

    private function renderOpenQuestions(array $questions, string $mode): string
    {
        if (empty($questions)) {
            return '';
        }

        $lines = [$mode === 'telegram' ? '<b>❓ Открытые вопросы</b>' : '❓ Открытые вопросы'];
        foreach ($questions as $q) {
            $lines[] = $mode === 'telegram' ? '● ' . e($q) : "● {$q}";
        }

        return implode("\n", $lines);
    }

    private function renderFocusAreas(array $areas, string $mode): string
    {
        if (empty($areas)) {
            return '';
        }

        $lines = [$mode === 'telegram' ? '<b>🔍 Фокус на следующей встрече</b>' : '🔍 Фокус на следующей встрече'];
        foreach ($areas as $area) {
            $lines[] = $mode === 'telegram' ? '● ' . e($area) : "● {$area}";
        }

        return implode("\n", $lines);
    }

    public function renderPersonalContent(array $data, User $user): string
    {
        $lines = [
            "👤 Личная агенда: {$user->name}",
            '',
        ];

        if (! empty($data['previous_meeting_recap'])) {
            $lines[] = '🔙 Прошлый митинг:';
            $lines[] = $data['previous_meeting_recap'];
            $lines[] = '';
        }

        if (! empty($data['assigned_tasks'])) {
            $lines[] = '📋 Текущие задачи:';
            foreach ($data['assigned_tasks'] as $task) {
                $due     = ! empty($task['due_date']) ? " (срок: {$task['due_date']})" : '';
                $lines[] = "- [{$task['status']}] {$task['name']}{$due}";
            }
            $lines[] = '';
        }

        if (! empty($data['due_by_this_meeting'])) {
            $lines[] = '⏰ Дедлайн до этого митинга:';
            foreach ($data['due_by_this_meeting'] as $task) {
                $lines[] = "- {$task['name']}";
            }
            $lines[] = '';
        }

        if (! empty($data['completed_since_last'])) {
            $lines[] = '✅ Завершённые задачи:';
            foreach ($data['completed_since_last'] as $task) {
                $closed  = ! empty($task['close_date']) ? " ({$task['close_date']})" : '';
                $lines[] = "- {$task['name']}{$closed}";
            }
            $lines[] = '';
        }

        if (! empty($data['discussion_points'])) {
            $lines[] = '💬 Темы для обсуждения:';
            foreach ($data['discussion_points'] as $i => $point) {
                $lines[] = ($i + 1) . '. ' . $point;
            }
        }

        return implode("\n", $lines);
    }
}
