<?php

namespace App\Services\Agenda;

use Carbon\Carbon;
use App\Domain\DTO\AI\MessageDTO;
use App\Enums\AgendaStatus;
use App\Models\AgentActivityLog;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingAgenda;
use App\Models\MeetingSummary;
use App\Models\Setting;
use App\Models\User;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AgendaService
{
    public function __construct(
        private readonly PreviousMeetingResolver $previousMeetingResolver,
    ) {}

    public function generateForEvent(CalendarEvent $event): void
    {
        $event->load('source.user.teams', 'source.user.organizations', 'participants.profile.user.telegramUser');

        $previousEvent = $this->previousMeetingResolver->resolve($event);
        $previousSummary = $previousEvent?->meetingSummary;

        $teamIds = $event->source->user->teams->pluck('id');
        $issues = $teamIds->isNotEmpty()
            ? Issue::query()->withoutTrashed()->whereIn('team_id', $teamIds)->get()
            : collect();

        $this->generateGeneralAgenda($event, $previousEvent, $previousSummary, $issues);

        $resolvedUsers = $event->participants
            ->filter(fn ($p) => $p->profile?->user_id)
            ->map(fn ($p) => $p->profile->user)
            ->unique('id');

        foreach ($resolvedUsers as $user) {
            $this->generatePersonalAgenda($event, $user, $previousSummary, $previousEvent, $issues);
        }
    }

    private function generateGeneralAgenda(
        CalendarEvent $event,
        ?CalendarEvent $previousEvent,
        ?MeetingSummary $previousSummary,
        Collection $issues,
    ): MeetingAgenda {
        $existingAgenda = MeetingAgenda::query()
            ->where('calendar_event_id', $event->id)
            ->whereNull('user_id')
            ->where('type', 'general')
            ->where('status', '!=', AgendaStatus::FAILED->value)
            ->first();

        if ($existingAgenda) {
            return $existingAgenda;
        }

        $agenda = MeetingAgenda::query()
            ->where('calendar_event_id', $event->id)
            ->whereNull('user_id')
            ->where('type', 'general')
            ->where('status', AgendaStatus::FAILED->value)
            ->first();

        if (! $agenda) {
            $agenda = MeetingAgenda::create([
                'calendar_event_id' => $event->id,
                'user_id' => null,
                'type' => 'general',
                'status' => AgendaStatus::IN_PROGRESS,
                'send_scheduled_at' => $event->starts_at->subMinutes(30),
            ]);
        } else {
            $agenda->update([
                'status' => AgendaStatus::IN_PROGRESS,
                'raw_json' => null,
                'content' => null,
                'send_scheduled_at' => $event->starts_at->subMinutes(30),
            ]);
        }

        try {
            $prompt = $this->buildGeneralPrompt($event, $previousSummary, $issues);
            $json = OpenRouterClient::chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.agenda', config('ai.providers.openrouter.models.agenda')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            $llmData = json_decode($json, true);
            $structuredData = $this->collectStructuredData($event, $previousEvent, $previousSummary);

            $agenda->update([
                'status' => AgendaStatus::DONE,
                'raw_json' => array_merge($llmData, $structuredData),
                'content' => $this->renderGeneralContent($llmData),
            ]);

            if ($event->source?->user) {
                AgentActivityLog::recordActivity(
                    user: $event->source->user,
                    toolName: 'agenda_generated_general',
                    toolResult: [
                        'calendar_event_id' => $event->id,
                    ],
                );
            }
        } catch (\Throwable $e) {
            $agenda->update(['status' => AgendaStatus::FAILED]);
            Log::error('General agenda generation failed', [
                'calendar_event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $agenda;
    }

    private function generatePersonalAgenda(
        CalendarEvent $event,
        User $user,
        ?MeetingSummary $previousSummary,
        ?CalendarEvent $previousEvent,
        Collection $allIssues,
    ): MeetingAgenda {
        $existingAgenda = MeetingAgenda::query()
            ->where('calendar_event_id', $event->id)
            ->where('user_id', $user->id)
            ->where('type', 'personal')
            ->where('status', '!=', AgendaStatus::FAILED->value)
            ->first();

        if ($existingAgenda) {
            return $existingAgenda;
        }

        $agenda = MeetingAgenda::query()
            ->where('calendar_event_id', $event->id)
            ->where('user_id', $user->id)
            ->where('type', 'personal')
            ->where('status', AgendaStatus::FAILED->value)
            ->first();

        if (! $agenda) {
            $agenda = MeetingAgenda::create([
                'calendar_event_id' => $event->id,
                'user_id' => $user->id,
                'type' => 'personal',
                'status' => AgendaStatus::IN_PROGRESS,
                'send_scheduled_at' => $event->starts_at->subMinutes(30),
            ]);
        } else {
            $agenda->update([
                'status' => AgendaStatus::IN_PROGRESS,
                'raw_json' => null,
                'content' => null,
                'send_scheduled_at' => $event->starts_at->subMinutes(30),
            ]);
        }

        try {
            $userIssues = $allIssues->where('assignee_id', $user->id);
            $prompt = $this->buildPersonalPrompt($event, $user, $previousSummary, $previousEvent, $userIssues);
            $json = OpenRouterClient::chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.agenda', config('ai.providers.openrouter.models.agenda')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            $agenda->update([
                'status' => AgendaStatus::DONE,
                'raw_json' => json_decode($json, true),
                'content' => $this->renderPersonalContent(json_decode($json, true), $user),
            ]);

            AgentActivityLog::recordActivity(
                user: $user,
                toolName: 'agenda_generated_personal',
                toolResult: [
                    'calendar_event_id' => $event->id,
                    'user_id' => $user->id,
                ],
            );
        } catch (\Throwable $e) {
            $agenda->update(['status' => AgendaStatus::FAILED]);
            Log::error('Personal agenda generation failed', [
                'calendar_event_id' => $event->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $agenda;
    }

    private function collectStructuredData(
        CalendarEvent $event,
        ?CalendarEvent $previousEvent,
        ?MeetingSummary $previousSummary,
    ): array {
        $event->loadMissing('profiles.user');
        $attendees = $event->profiles->map(fn($p) => $p->user?->name)->filter()->values()->toArray();
        $attendeeEmails = $event->profiles->map(fn($p) => $p->user?->email)->filter()->values()->toArray();

        $previousSummaryData = null;
        if ($previousSummary && $previousEvent) {
            $daysSince = (int) Carbon::parse($previousEvent->starts_at)->diffInDays(Carbon::parse($event->starts_at));
            $previousSummaryData = [
                'days_ago' => $daysSince,
                'summary' => $previousSummary->summary,
                'key_points' => $previousSummary->key_points ?? [],
                'decisions' => $previousSummary->decisions ?? [],
            ];
        }

        $eventIds = CalendarEvent::query()
            ->where('title', $event->title)
            ->where('url', $event->url)
            ->where('starts_at', '<', $event->starts_at)
            ->pluck('id');

        $completedTasks = collect();
        $openTasks = collect();
        $overdueTasks = collect();
        $unresolvedDecisions = collect();

        if ($eventIds->isNotEmpty()) {
            $allIssues = Issue::query()
                ->where('sourceable_type', CalendarEvent::class)
                ->whereIn('sourceable_id', $eventIds)
                ->with('assignee')
                ->get();

            $now = Carbon::now();
            $mapTask = fn($i) => [
                'name' => $i->name,
                'assignee' => $i->assignee?->name ?? $i->assignee_name,
                'due_date' => $i->due_date ? Carbon::parse($i->due_date)->format('d.m.Y') : null,
            ];

            if ($previousEvent) {
                $completedTasks = $allIssues
                    ->where('status', 'done')
                    ->filter(fn($i) => Carbon::parse($i->updated_at)->gte(Carbon::parse($previousEvent->starts_at)))
                    ->map($mapTask)
                    ->values();
            }

            $openIssues = $allIssues->filter(fn($i) => !in_array($i->status, ['done', 'cancelled']));
            $overdueTasks = $openIssues
                ->filter(fn($i) => $i->due_date && Carbon::parse($i->due_date)->lt($now))
                ->map($mapTask)->values();
            $openTasks = $openIssues
                ->filter(fn($i) => !$i->due_date || Carbon::parse($i->due_date)->gte($now))
                ->map($mapTask)->values();

            if ($previousSummary && $previousEvent && !empty($previousSummary->decisions)) {
                $prevEventIssues = $allIssues->where('sourceable_id', $previousEvent->id);
                $activeTasks = $prevEventIssues
                    ->filter(fn($i) => !in_array($i->status, ['done', 'cancelled']))
                    ->count();
                $totalTasks = $prevEventIssues->count();

                if (!($totalTasks > 0 && $activeTasks === 0)) {
                    $unresolvedDecisions = collect($previousSummary->decisions);
                }
            }
        }

        return [
            'attendees' => $attendees,
            'attendee_emails' => $attendeeEmails,
            'previous_summary' => $previousSummaryData,
            'completed_tasks' => $completedTasks->toArray(),
            'open_tasks' => $openTasks->toArray(),
            'overdue_tasks' => $overdueTasks->toArray(),
            'unresolved_decisions' => $unresolvedDecisions->toArray(),
        ];
    }

    private function buildGeneralPrompt(
        CalendarEvent $event,
        ?MeetingSummary $previousSummary,
        Collection $issues,
    ): string {
        $eventStartsAt = $this->normalizeDateTime($event->starts_at);

        $parts = [];
        $parts[] = 'Ты — ассистент для подготовки к рабочим встречам. Сгенерируй общую агенду для предстоящего митинга.';
        $parts[] = '';
        $parts[] = "Название встречи: {$event->title}";
        $parts[] = "Описание: {$event->description}";
        $parts[] = "Дата и время: {$eventStartsAt->format('d.m.Y H:i')}";

        if ($previousSummary) {
            $parts[] = '';
            $parts[] = '--- ИТОГИ ПРЕДЫДУЩЕГО МИТИНГА ---';
            $parts[] = "Тема: {$previousSummary->title}";
            $parts[] = "Краткое содержание: {$previousSummary->summary}";

            if (!empty($previousSummary->key_points)) {
                $parts[] = 'Ключевые моменты:';
                foreach ($previousSummary->key_points as $point) {
                    $parts[] = "- {$point}";
                }
            }

            if (!empty($previousSummary->decisions)) {
                $parts[] = 'Решения:';
                foreach ($previousSummary->decisions as $decision) {
                    $parts[] = "- {$decision}";
                }
            }
        } else {
            $parts[] = '';
            $parts[] = 'Предыдущий митинг с таким названием не найден (возможно, это первая встреча).';
        }

        if ($issues->isNotEmpty()) {
            $parts[] = '';
            $parts[] = '--- ЗАДАЧИ КОМАНДЫ ---';

            $grouped = $issues->groupBy('status');
            foreach ($grouped as $status => $group) {
                $parts[] = mb_strtoupper($status) . " ({$group->count()}):";
                foreach ($group->take(10) as $issue) {
                    $parts[] = "- {$issue->name}" . ($issue->due_date ? " (срок: {$issue->due_date->format('d.m.Y')})" : '');
                }
                if ($group->count() > 10) {
                    $parts[] = "  ... и ещё " . ($group->count() - 10) . ' задач';
                }
            }
        }

        $parts[] = '';
        $parts[] = 'Сгенерируй JSON с полями:';
        $parts[] = '- "previous_meeting_recap": краткий пересказ предыдущего митинга (1-3 предложения), или null если первая встреча';
        $parts[] = '- "topics_to_discuss": массив тем для обсуждения на этом митинге (3-7 пунктов)';
        $parts[] = '- "team_tasks_overview": краткий обзор состояния задач команды (2-4 предложения)';

        return implode("\n", $parts);
    }

    private function buildPersonalPrompt(
        CalendarEvent $event,
        User $user,
        ?MeetingSummary $previousSummary,
        ?CalendarEvent $previousEvent,
        Collection $userIssues,
    ): string {
        $eventStartsAt = $this->normalizeDateTime($event->starts_at);
        $previousStartsAt = $previousEvent?->starts_at
            ? $this->normalizeDateTime($previousEvent->starts_at)
            : null;

        $parts = [];
        $parts[] = "Ты — ассистент для подготовки к рабочим встречам. Сгенерируй персональную агенду для участника {$user->name}.";
        $parts[] = '';
        $parts[] = "Название встречи: {$event->title}";
        $parts[] = "Дата и время: {$eventStartsAt->format('d.m.Y H:i')}";

        if ($previousSummary) {
            $parts[] = '';
            $parts[] = '--- ИТОГИ ПРЕДЫДУЩЕГО МИТИНГА ---';
            $parts[] = "Краткое содержание: {$previousSummary->summary}";

            if (!empty($previousSummary->key_points)) {
                $parts[] = 'Ключевые моменты:';
                foreach ($previousSummary->key_points as $point) {
                    $parts[] = "- {$point}";
                }
            }
        }

        $openTasks = $userIssues->whereIn('status', ['open', 'in_progress']);
        if ($openTasks->isNotEmpty()) {
            $parts[] = '';
            $parts[] = '--- ТЕКУЩИЕ ЗАДАЧИ (open / in_progress) ---';
            foreach ($openTasks as $issue) {
                $due = $issue->due_date ? " | срок: {$issue->due_date->format('d.m.Y')}" : '';
                $parts[] = "- [{$issue->status}] {$issue->name}{$due}";
                if ($issue->description) {
                    $parts[] = "  Описание: {$issue->description}";
                }
            }
        }

        $closedTasks = $userIssues
            ->where('status', 'done')
            ->when($previousStartsAt, fn ($c) => $c->where('close_date', '>=', $previousStartsAt));

        if ($closedTasks->isNotEmpty()) {
            $parts[] = '';
            $parts[] = '--- ЗАКРЫТЫЕ ЗАДАЧИ (с прошлого митинга) ---';
            foreach ($closedTasks as $issue) {
                $closed = $issue->close_date ? " | закрыта: {$issue->close_date->format('d.m.Y')}" : '';
                $parts[] = "- {$issue->name}{$closed}";
            }
        }

        $dueSoon = $userIssues
            ->whereIn('status', ['open', 'in_progress'])
            ->filter(fn ($i) => $i->due_date && $i->due_date->lte($eventStartsAt));

        if ($dueSoon->isNotEmpty()) {
            $parts[] = '';
            $parts[] = '--- ЗАДАЧИ С ИСТЕКАЮЩИМ СРОКОМ (до этого митинга) ---';
            foreach ($dueSoon as $issue) {
                $parts[] = "- {$issue->name} (срок: {$issue->due_date->format('d.m.Y')})";
            }
        }

        $parts[] = '';
        $parts[] = 'Сгенерируй JSON с полями:';
        $parts[] = '- "previous_meeting_recap": краткий пересказ предыдущего митинга для этого участника (1-3 предложения), или null';
        $parts[] = '- "assigned_tasks": массив объектов {name, status, due_date} — текущие задачи участника';
        $parts[] = '- "completed_since_last": массив объектов {name, close_date} — закрытые задачи с прошлого митинга';
        $parts[] = '- "due_by_this_meeting": массив объектов {name, description} — задачи с дедлайном до этого митинга';
        $parts[] = '- "discussion_points": массив тем для обсуждения конкретно для этого участника (2-5 пунктов)';

        return implode("\n", $parts);
    }

    private function normalizeDateTime(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::parse($value);
    }

    private function renderGeneralContent(array $data): string
    {
        $lines = [];
        $lines[] = '📋 Общая агенда митинга';
        $lines[] = '';

        if (!empty($data['previous_meeting_recap'])) {
            $lines[] = '🔙 Прошлый митинг:';
            $lines[] = $data['previous_meeting_recap'];
            $lines[] = '';
        }

        if (!empty($data['topics_to_discuss'])) {
            $lines[] = '📌 Темы для обсуждения:';
            foreach ($data['topics_to_discuss'] as $i => $topic) {
                $lines[] = ($i + 1) . '. ' . $topic;
            }
            $lines[] = '';
        }

        if (!empty($data['team_tasks_overview'])) {
            $lines[] = '📊 Обзор задач команды:';
            $lines[] = $data['team_tasks_overview'];
        }

        return implode("\n", $lines);
    }

    private function renderPersonalContent(array $data, User $user): string
    {
        $lines = [];
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
                $due = !empty($task['due_date']) ? " (срок: {$task['due_date']})" : '';
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
                $closed = !empty($task['close_date']) ? " ({$task['close_date']})" : '';
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
