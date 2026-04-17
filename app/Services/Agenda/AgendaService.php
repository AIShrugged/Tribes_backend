<?php

namespace App\Services\Agenda;

use Carbon\Carbon;
use App\Domain\DTO\AI\MessageDTO;
use App\Enums\AgendaStatus;
use App\Models\AgentActivityLog;
use App\Models\CalendarEvent;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Builder;
use App\Models\MeetingAgenda;
use App\Models\MeetingSeriesState;
use App\Models\MeetingSummary;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\UpcomingAgenda;
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

        $previousEvents = $this->previousMeetingResolver->resolveMany($event, 3);
        $previousEvent = $previousEvents->first();
        $previousSummary = $previousEvent?->meetingSummary;

        if ($previousEvents->isEmpty()) {
            Log::info('Agenda generation skipped: no previous meetings with summaries', [
                'calendar_event_id' => $event->id,
            ]);
            return;
        }

        // Issues linked to this meeting series (by sourceable CalendarEvent)
        $seriesEventIds = CalendarEvent::query()
            ->where(fn ($q) => $this->scopeSeries($q, $event))
            ->where('starts_at', '<', $event->starts_at)
            ->pluck('id');

        $issues = $seriesEventIds->isNotEmpty()
            ? Issue::query()
                ->withoutTrashed()
                ->where('sourceable_type', CalendarEvent::class)
                ->whereIn('sourceable_id', $seriesEventIds)
                ->with('assignee')
                ->get()
            : collect();

        // Organization context
        $organization = $event->source?->user?->organizations?->first();
        $orgContext = $organization?->context;

        // Meeting series state (rolling aggregation)
        // Try exact identifier first, fall back to title-based match
        $seriesState = MeetingSeriesState::where(
            'series_identifier',
            MeetingSeriesState::buildSeriesIdentifier($event),
        )->first();

        if (!$seriesState) {
            $seriesState = MeetingSeriesState::query()
                ->whereHas('sourceEvent', fn ($q) => $this->scopeSeries($q, $event))
                ->orderByDesc('version')
                ->first();
        }

        // Upcoming agendas from participants (follow-ups from last meeting)
        $participantUserIds = $event->participants
            ->filter(fn ($p) => $p->profile?->user_id)
            ->pluck('profile.user_id')
            ->unique();

        $upcomingAgendas = $participantUserIds->isNotEmpty()
            ? UpcomingAgenda::whereIn('user_id', $participantUserIds)
                ->where('status', AgendaStatus::DONE->value)
                ->get()
            : collect();

        // Previous agenda topics
        $previousAgenda = $previousEvent
            ? MeetingAgenda::where('calendar_event_id', $previousEvent->id)
                ->where('type', 'general')
                ->where('status', AgendaStatus::DONE)
                ->first()
            : null;

        $context = new AgendaContext(
            orgContext: $orgContext,
            seriesState: $seriesState,
            previousEvents: $previousEvents,
            upcomingAgendas: $upcomingAgendas,
            previousAgenda: $previousAgenda,
        );

        $this->generateGeneralAgenda($event, $previousEvent, $previousSummary, $issues, $context);

        $resolvedUsers = $event->participants
            ->filter(fn ($p) => $p->profile?->user_id)
            ->map(fn ($p) => $p->profile->user)
            ->unique('id');

        foreach ($resolvedUsers as $user) {
            $this->generatePersonalAgenda($event, $user, $previousSummary, $previousEvent, $issues, $context);
        }
    }

    private function generateGeneralAgenda(
        CalendarEvent $event,
        ?CalendarEvent $previousEvent,
        ?MeetingSummary $previousSummary,
        Collection $issues,
        AgendaContext $context,
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
            $prompt = $this->buildGeneralPrompt($event, $issues, $context);
            $json = OpenRouterClient::chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.agenda', config('ai.providers.openrouter.models.agenda')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            if (!preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                throw new \RuntimeException('No JSON in LLM response');
            }
            $llmData = json_decode($matches[0], true) ?? [];
            $structuredData = $this->collectStructuredData($event, $previousEvent, $previousSummary);

            // --- PHP-computed data ---
            $prevDate = $previousEvent
                ? $this->normalizeDateTime($previousEvent->starts_at)
                : null;

            // 1. Commitments with statuses from issues
            // Prefer structured JSON column (new format), fall back to text parsing (old format)
            $questions = $llmData['questions'] ?? [];
            $commitmentsCheck = [];
            $structuredCommitments = $previousSummary?->commitments;

            if (!empty($structuredCommitments) && is_array($structuredCommitments)) {
                foreach ($structuredCommitments as $i => $c) {
                    $person = $c['who'] ?? '';
                    $commitment = $c['what'] ?? '';
                    $deadline = isset($c['deadline']) && $c['deadline']
                        ? Carbon::parse($c['deadline'])->format('d.m.Y')
                        : null;
                    $status = $this->matchCommitmentStatus($person, $commitment, $issues);
                    $commitmentsCheck[] = [
                        'person' => $person,
                        'commitment' => $commitment,
                        'deadline' => $deadline,
                        'status' => $status,
                        'question' => $questions[$i] ?? 'статус?',
                    ];
                }
            } else {
                $commitments = $this->extractCommitmentsFromSummary($previousSummary?->summary);
                foreach ($commitments as $i => $raw) {
                    $parsed = $this->parseCommitment($raw, $prevDate);
                    $status = $this->matchCommitmentStatus($parsed['person'], $parsed['commitment'], $issues);
                    $commitmentsCheck[] = [
                        'person' => $parsed['person'],
                        'commitment' => $parsed['commitment'],
                        'deadline' => $parsed['deadline'],
                        'status' => $status,
                        'question' => $questions[$i] ?? 'статус?',
                    ];
                }
            }
            $doneCount = count(array_filter($commitmentsCheck, fn ($c) => $c['status'] === 'готово'));
            $totalCount = count($commitmentsCheck);

            // 2. Decisions from previous meeting
            $decisions = $previousSummary?->decisions ?? null;
            $decisionsArray = is_string($decisions)
                ? json_decode($decisions, true) ?? []
                : ($decisions ?? []);

            // 3. Tasks created between meetings
            $tasksBetween = $this->getTasksBetweenMeetings($event, $previousEvent, $issues);

            // 4. Backlog stats with deltas
            $backlogStats = $this->getBacklogStats($issues, $previousEvent);

            // 5. Previous meeting topics from summary
            $prevTopics = $this->extractTopicsFromSummary($previousSummary?->summary);

            $renderData = [
                'event' => $event,
                'meeting_goal' => $llmData['meeting_goal'] ?? '',
                'discussion_topics' => $llmData['discussion_topics'] ?? [],
                'main_problem' => $llmData['main_problem'] ?? '',
                'prev_topics' => $prevTopics,
                'commitments_check' => $commitmentsCheck,
                'commitments_done' => $doneCount,
                'commitments_total' => $totalCount,
                'decisions_recap' => $decisionsArray,
                'tasks_between' => $tasksBetween,
                'backlog_stats' => $backlogStats,
            ];

            $agenda->update([
                'status' => AgendaStatus::DONE,
                'raw_json' => array_merge($renderData, $structuredData, ['event' => null]),
                'content' => $this->renderGeneralContent($renderData),
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
        AgendaContext $context,
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
            $prompt = $this->buildPersonalPrompt($event, $user, $previousEvent, $userIssues, $context);
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
            ->where(fn ($q) => $this->scopeSeries($q, $event))
            ->where('starts_at', '<', $event->starts_at)
            ->pluck('id');

        $completedTasks = collect();
        $openTasks = collect();
        $overdueTasks = collect();
        $unresolvedDecisions = collect();

        if ($eventIds->isNotEmpty()) {
            $allIssues = Issue::query()
                ->withoutTrashed()
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
        Collection $issues,
        AgendaContext $context,
    ): string {
        $eventStartsAt = $this->normalizeDateTime($event->starts_at);

        $parts = [];
        $parts[] = 'Ты — ассистент для подготовки к рабочим встречам. Сгенерируй агенду для предстоящей планёрки.';
        $parts[] = '';
        $parts[] = "Название встречи: {$event->title}";
        $parts[] = "Дата и время: {$eventStartsAt->format('d.m.Y H:i')}";

        // [1] Organization context
        if ($context->orgContext) {
            $parts[] = '';
            $parts[] = '--- КОНТЕКСТ ПРОЕКТА ---';
            $parts[] = $context->orgContext;
        }

        // [2] Meeting series state (rolling aggregation) — strategic context only
        if ($context->seriesState?->content) {
            $parts[] = '';
            $parts[] = '--- ТЕКУЩЕЕ СОСТОЯНИЕ ПРОЕКТА ---';
            $parts[] = $context->seriesState->content;
        }

        // [3] PRIMARY SOURCE: Commitments from previous meeting (extracted in PHP, not LLM)
        $allCommitments = [];
        $allDecisions = [];
        $prevMeetingDate = null;

        if ($context->previousEvents->isNotEmpty()) {
            $prevEvent = $context->previousEvents->first();
            $summary = $prevEvent?->meetingSummary;

            if ($summary) {
                $prevMeetingDate = $this->normalizeDateTime($prevEvent->starts_at);
                $allCommitments = $this->extractCommitmentsFromSummary($summary->summary);

                $decisions = is_string($summary->decisions ?? null)
                    ? json_decode($summary->decisions, true) ?? []
                    : ($summary->decisions ?? []);
                $allDecisions = $decisions;

                // Brief summary for LLM context
                $summaryText = mb_substr($summary->summary, 0, 500);
                $summaryText = preg_replace('/### Следующие шаги.*$/s', '', $summaryText);
                $summaryText = preg_replace('/=== COMMITMENTS ===.*$/s', '', $summaryText);

                $parts[] = '';
                $parts[] = '--- КОНТЕКСТ ПРЕДЫДУЩЕЙ ВСТРЕЧИ ---';
                $parts[] = "Дата: {$prevMeetingDate->format('d.m.Y')}";
                $parts[] = trim($summaryText);
            }
        }

        // Pass numbered commitments to LLM for question generation
        if (!empty($allCommitments)) {
            $parts[] = '';
            $parts[] = '--- ОБЯЗАТЕЛЬСТВА С ПРОШЛОЙ ВСТРЕЧИ (нужно сгенерировать вопрос к каждому) ---';
            foreach ($allCommitments as $i => $c) {
                $parts[] = ($i + 1) . ". {$c}";
            }
        }

        $parts[] = '';
        $parts[] = 'Сгенерируй JSON со следующими полями:';
        $parts[] = '';
        $parts[] = '- "meeting_goal": ОДНО короткое предложение (максимум 12 слов) — главное, что должно быть решено.';
        $parts[] = '- "discussion_topics": массив объектов {title, description} — 3-5 тем для обсуждения на встрече. Каждая тема — конкретный вопрос с коротким описанием (1 предложение). Строй от обязательств и решений, не от абстрактных тем.';
        $parts[] = '- "main_problem": строка — одна главная проблематика за прошедший период (2-3 предложения). Что блокирует прогресс или требует стратегического решения. Если нет явной проблемы — null.';
        $parts[] = '- "questions": массив строк — ровно ' . count($allCommitments) . ' вопросов, по одному на каждое обязательство выше (в том же порядке). Каждый вопрос — конкретный. Не пропускай ни одного.';
        $parts[] = '';
        $parts[] = 'ВАЖНО: в "questions" должно быть ровно ' . count($allCommitments) . ' элементов.';

        return implode("\n", $parts);
    }

    private function parseCommitment(string $raw, ?Carbon $meetingDate): array
    {
        // Parse "[Person] commitment (deadline)" format
        $person = '?';
        $commitment = $raw;
        $deadline = null;

        // Extract person: "[Иван и Борис]" or "Иван и Борис:" at start
        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $raw, $m)) {
            $person = $m[1];
            $commitment = $m[2];
        } elseif (preg_match('/^([^:]+?):\s*(.*)$/s', $raw, $m)) {
            // "Иван и Борис: commitment" format
            $person = trim($m[1]);
            $commitment = $m[2];
        }

        // Extract deadline from parentheses
        if (preg_match('/\(([^)]+)\)\s*$/', $commitment, $m)) {
            $deadlineText = mb_strtolower(trim($m[1]));
            $commitment = trim(preg_replace('/\([^)]+\)\s*$/', '', $commitment));

            if ($meetingDate && str_contains($deadlineText, 'завтра')) {
                $deadline = $meetingDate->copy()->addDay()->format('d.m.Y');
            } elseif ($deadlineText !== 'без срока') {
                $deadline = $m[1];
            }
        }

        return [
            'person' => $person,
            'commitment' => trim($commitment),
            'deadline' => $deadline,
        ];
    }

    private function extractCommitmentsFromSummary(?string $summary): array
    {
        if (!$summary) {
            return [];
        }

        $commitments = [];

        // Format 1: "=== COMMITMENTS ===" section
        if (preg_match('/=== COMMITMENTS ===(.*?)(?:===|\z)/s', $summary, $m)) {
            foreach (explode("\n", trim($m[1])) as $line) {
                $line = trim(ltrim(trim($line), '-'));
                if ($line !== '') {
                    $commitments[] = $line;
                }
            }
        }

        // Format 2: "### Следующие шаги" section
        if (empty($commitments) && preg_match('/### Следующие шаги\n(.*?)(?:###|===|\z)/s', $summary, $m)) {
            foreach (explode("\n", trim($m[1])) as $line) {
                $line = trim(ltrim(trim($line), '-'));
                if ($line !== '') {
                    $commitments[] = $line;
                }
            }
        }

        // Format 3: Markdown table "| Кто | Что делает | Дедлайн |"
        if (empty($commitments) && preg_match_all('/^\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]*?)\s*\|/m', $summary, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $row) {
                $who = trim($row[1]);
                $what = trim($row[2]);
                $deadline = trim($row[3]);

                // Skip header and separator rows
                if ($who === 'Кто' || str_starts_with($who, '-')) {
                    continue;
                }

                $deadlineStr = ($deadline && $deadline !== '-')
                    ? " ({$deadline})"
                    : ' (без срока)';

                $commitments[] = "[{$who}] {$what}{$deadlineStr}";
            }
        }

        return $commitments;
    }

    private function matchCommitmentStatus(string $person, string $commitment, Collection $issues): string
    {
        // Name mapping: Russian/short names → names in issues table
        $nameVariants = $this->getNameVariants($person);

        // Find issues matching this person
        $personIssues = $issues->filter(function ($issue) use ($nameVariants) {
            $assignee = mb_strtolower($issue->assignee?->name ?? $issue->assignee_name ?? '');
            foreach ($nameVariants as $variant) {
                if (str_contains($assignee, $variant)) {
                    return true;
                }
            }
            return false;
        });

        if ($personIssues->isEmpty()) {
            return 'ожидание';
        }

        // Check if any matching issue is done
        $commitmentWords = array_filter(
            preg_split('/[\s,.:;]+/u', mb_strtolower($commitment)),
            fn ($w) => mb_strlen($w) > 3,
        );

        foreach ($personIssues as $issue) {
            $issueName = mb_strtolower($issue->name);
            $matchCount = 0;
            foreach ($commitmentWords as $word) {
                if (str_contains($issueName, $word)) {
                    $matchCount++;
                }
            }
            // If at least 2 words match, consider it related
            if ($matchCount >= 2 || ($matchCount >= 1 && count($commitmentWords) <= 2)) {
                return match ($issue->status) {
                    'done' => 'готово',
                    'in_progress' => 'в работе',
                    'cancelled' => 'отменено',
                    default => 'ожидание',
                };
            }
        }

        // No matching issue found — check if person has any in_progress tasks
        $hasInProgress = $personIssues->contains('status', 'in_progress');
        return $hasInProgress ? 'в работе' : 'ожидание';
    }

    private function getNameVariants(string $person): array
    {
        $person = mb_strtolower(trim($person));
        $map = [
            'борис' => ['boris', 'борис'],
            'борь' => ['boris', 'борис'],
            'boris' => ['boris', 'борис'],
            'иван' => ['ivan', 'иван', 'zakharov', 'захаров'],
            'ivan' => ['ivan', 'иван', 'zakharov'],
            'константин' => ['konstantin', 'константин', 'kupreychenko', 'купрейченко', 'костя'],
            'костя' => ['konstantin', 'константин', 'kupreychenko', 'костя'],
            'konstantin' => ['konstantin', 'константин', 'kupreychenko'],
            'слава' => ['slava', 'слава'],
            'slava' => ['slava', 'слава'],
            'фёдор' => ['fedor', 'фёдор', 'федор', 'zhernovoy', 'жерновой'],
            'федор' => ['fedor', 'фёдор', 'федор', 'zhernovoy'],
            'fedor' => ['fedor', 'фёдор', 'федор', 'zhernovoy'],
            'никита' => ['nikita', 'никита'],
        ];

        // Try exact match first
        if (isset($map[$person])) {
            return $map[$person];
        }

        // Try partial match (for compound names like "Иван и Борис")
        $variants = [];
        foreach ($map as $key => $values) {
            if (str_contains($person, $key)) {
                $variants = array_merge($variants, $values);
            }
        }

        return !empty($variants) ? array_unique($variants) : [$person];
    }

    private function getTasksBetweenMeetings(
        CalendarEvent $event,
        ?CalendarEvent $previousEvent,
        Collection $issues,
    ): array {
        if (!$previousEvent) {
            return [];
        }

        return $issues
            ->filter(fn ($i) => $i->created_at >= $previousEvent->starts_at
                && $i->created_at < $event->starts_at)
            ->map(fn ($i) => [
                'name' => $i->name,
                'assignee' => $i->assignee?->name ?? $i->assignee_name,
                'status' => match ($i->status) {
                    'done' => 'готово',
                    'in_progress' => 'в работе',
                    'cancelled' => 'отменено',
                    default => 'открыта',
                },
            ])
            ->values()
            ->toArray();
    }

    private function getBacklogStats(Collection $issues, ?CalendarEvent $previousEvent): array
    {
        $open = $issues->where('status', 'open')->count();
        $inProgress = $issues->where('status', 'in_progress')->count();
        $done = $issues->where('status', 'done')->count();
        $cancelled = $issues->where('status', 'cancelled')->count();
        $paused = $issues->whereNotIn('status', ['open', 'in_progress', 'done', 'cancelled'])->count();
        $total = $issues->count();

        // Calculate deltas based on issues created/updated since previous meeting
        $deltaOpen = 0;
        $deltaInProgress = 0;
        $deltaDone = 0;

        if ($previousEvent) {
            $since = $previousEvent->starts_at;
            $deltaOpen = $issues->where('status', 'open')
                ->filter(fn ($i) => $i->created_at >= $since)->count();
            $deltaInProgress = $issues->where('status', 'in_progress')
                ->filter(fn ($i) => $i->updated_at >= $since)->count();
            $deltaDone = $issues->where('status', 'done')
                ->filter(fn ($i) => $i->updated_at >= $since)->count();
        }

        return [
            'total' => $total,
            'open' => $open,
            'in_progress' => $inProgress,
            'done' => $done,
            'cancelled' => $cancelled,
            'paused' => $paused,
            'delta_open' => $deltaOpen,
            'delta_in_progress' => $deltaInProgress,
            'delta_done' => $deltaDone,
        ];
    }

    private function extractTopicsFromSummary(?string $summary): array
    {
        if (!$summary) {
            return [];
        }

        $topics = [];

        // Format 1: "### Тема N: Title" sections
        if (preg_match_all('/### (?:Тема \d+[:.]\s*)?(.+?)(?=\n###|\n===|\z)/s', $summary, $matches)) {
            foreach ($matches[1] as $topic) {
                $title = trim(explode("\n", trim($topic))[0]);
                if (!str_contains($title, 'Следующие шаги') && $title !== '') {
                    $topics[] = $title;
                }
            }
        }

        // Format 2: "Краткое содержание" with bullet points (bold or plain header)
        if (empty($topics) && preg_match('/\*{0,2}Краткое содержание\*{0,2}\n(.*?)(?:\n\n|\n\*\*|\n\||\z)/s', $summary, $m)) {
            foreach (explode("\n", trim($m[1])) as $line) {
                $line = trim(ltrim(trim($line), '-'));
                if ($line !== '') {
                    // Take text before first colon or first 80 chars
                    $colonPos = mb_strpos($line, ':');
                    $topics[] = $colonPos && $colonPos < 80
                        ? mb_substr($line, 0, $colonPos)
                        : mb_substr($line, 0, 80);
                }
            }
        }

        return array_slice($topics, 0, 6);
    }

    private function buildPersonalPrompt(
        CalendarEvent $event,
        User $user,
        ?CalendarEvent $previousEvent,
        Collection $userIssues,
        AgendaContext $context,
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

        // Organization context
        if ($context->orgContext) {
            $parts[] = '';
            $parts[] = '--- КОНТЕКСТ ПРОЕКТА ---';
            $parts[] = $context->orgContext;
        }

        // Meeting series state
        if ($context->seriesState?->content) {
            $parts[] = '';
            $parts[] = '--- ТЕКУЩЕЕ СОСТОЯНИЕ ПРОЕКТА ---';
            $parts[] = $context->seriesState->content;
        }

        // Previous summaries
        if ($context->previousEvents->isNotEmpty()) {
            $parts[] = '';
            $parts[] = '--- ИТОГИ ПРЕДЫДУЩИХ МИТИНГОВ ---';

            foreach ($context->previousEvents->take(2) as $prevEvent) {
                $summary = $prevEvent->meetingSummary;
                if (! $summary) {
                    continue;
                }

                $parts[] = ">> {$prevEvent->starts_at->format('d.m.Y')}: {$summary->summary}";

                if (! empty($summary->key_points)) {
                    foreach ($summary->key_points as $point) {
                        $parts[] = "- {$point}";
                    }
                }
            }
        }

        // User's upcoming agenda follow-ups
        $userUpcoming = $context->upcomingAgendas->firstWhere('user_id', $user->id);
        if ($userUpcoming?->raw_json) {
            $followUps = $userUpcoming->raw_json['follow_up_items'] ?? [];
            $openQuestions = $userUpcoming->raw_json['open_questions'] ?? [];

            if (! empty($followUps) || ! empty($openQuestions)) {
                $parts[] = '';
                $parts[] = '--- НЕЗАКРЫТЫЕ ВОПРОСЫ И FOLLOW-UPS ---';
                foreach ($followUps as $item) {
                    $parts[] = "- [follow-up] {$item}";
                }
                foreach ($openQuestions as $item) {
                    $parts[] = "- [вопрос] {$item}";
                }
            }
        }

        // User's issues
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

    private function collectFollowUps(Collection $upcomingAgendas): Collection
    {
        $items = collect();

        foreach ($upcomingAgendas as $agenda) {
            $raw = $agenda->raw_json ?? [];
            foreach ($raw['follow_up_items'] ?? [] as $item) {
                $items->push($item);
            }
            foreach ($raw['open_questions'] ?? [] as $item) {
                $items->push($item);
            }
        }

        return $items->unique()->values();
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
        $event = $data['event'] ?? null;
        $lines = [];

        // Header
        $title = $event?->title ?? 'Встреча';
        $date = $event ? $this->normalizeDateTime($event->starts_at)->format('d.m.Y') : '';
        $time = $event ? $this->normalizeDateTime($event->starts_at)->format('H:i') : '';
        $lines[] = "{$title}";
        $lines[] = "{$date}  ·  {$time}";

        // 1. Discussion topics
        if (!empty($data['discussion_topics'])) {
            $lines[] = '';
            $lines[] = '1. Темы для обсуждения';
            foreach ($data['discussion_topics'] as $topic) {
                $title = $topic['title'] ?? $topic;
                $desc = $topic['description'] ?? '';
                $lines[] = "● {$title}";
                if ($desc) {
                    $lines[] = "  {$desc}";
                }
            }
        }

        // 2. Main problem
        if (!empty($data['main_problem'])) {
            $lines[] = '';
            $lines[] = '2. Главная проблематика';
            $lines[] = $data['main_problem'];
        }

        // 3. Previous meeting topics
        if (!empty($data['prev_topics'])) {
            $lines[] = '';
            $lines[] = '3. Темы прошлого митинга';
            foreach ($data['prev_topics'] as $topic) {
                $lines[] = "● {$topic}";
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

        // 1. Discussion topics
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

        // 2. Main problem
        if (!empty($data['main_problem'])) {
            $lines[] = '';
            $lines[] = '<b>2. Главная проблематика</b>';
            $lines[] = e($data['main_problem']);
        }

        // 3. Previous meeting topics
        if (!empty($data['prev_topics'])) {
            $lines[] = '';
            $lines[] = '<b>3. Темы прошлого митинга</b>';
            foreach ($data['prev_topics'] as $topic) {
                // Strip bold markers from topic text
                $topic = trim(str_replace(['**', '*'], '', $topic));
                $lines[] = '● ' . e($topic);
            }
        }

        // 4. Tasks from previous meeting
        if (!empty($data['commitments_check'])) {
            $done  = $data['commitments_done'] ?? 0;
            $total = $data['commitments_total'] ?? count($data['commitments_check']);
            $pct   = $total > 0 ? round($done / $total * 100) : 0;

            $lines[] = '';
            $lines[] = "<b>4. Задачи с прошлого митинга</b>";
            $lines[] = "Выполнено — {$done} из {$total} ({$pct}%)";
            foreach ($data['commitments_check'] as $c) {
                $statusIcon = match ($c['status']) {
                    'готово'   => '✅',
                    'в работе' => '🔄',
                    'отменено' => '❌',
                    default    => '⏳',
                };
                $deadline = $c['deadline'] ? " <i>({$c['deadline']})</i>" : '';
                $lines[]  = "{$statusIcon} <b>" . e($c['person']) . "</b> — " . e($c['commitment']) . $deadline;
            }
        }

        // 5. Tasks between meetings
        if (!empty($data['tasks_between'])) {
            $btTotal = count($data['tasks_between']);
            $btDone  = count(array_filter($data['tasks_between'], fn ($t) => $t['status'] === 'done'));

            $lines[] = '';
            $lines[] = '<b>5. Задачи между митингами</b>';
            $lines[] = "Выполнено — {$btDone} из {$btTotal}";
            foreach ($data['tasks_between'] as $t) {
                $icon    = $t['status'] === 'done' ? '✅' : '🔵';
                $lines[] = "{$icon} <b>" . e($t['assignee']) . "</b> — " . e($t['name']);
            }
        }

        // 6. Backlog
        if (!empty($data['backlog_stats'])) {
            $bs    = $data['backlog_stats'];
            $lines[] = '';
            $lines[] = '<b>6. Прогресс по бэклогу</b>';
            $openDelta = $bs['delta_open'] ? " (+{$bs['delta_open']})" : '';
            $doneDelta = $bs['delta_done'] ? " (+{$bs['delta_done']})" : '';
            $lines[]   = "Всего: {$bs['total']} | Открыто: {$bs['open']}{$openDelta} | В работе: {$bs['in_progress']} | Закрыто: {$bs['done']}{$doneDelta}";
            if ($bs['total'] > 0) {
                $pct     = round($bs['done'] / $bs['total'] * 100);
                $lines[] = "Прогресс — {$bs['done']} из {$bs['total']} ({$pct}%)";
            }
        }

        return implode("\n", $lines);
    }

    private function detectStuckTasks(Collection $issues, CalendarEvent $event): Collection
    {
        $previousEventIds = CalendarEvent::query()
            ->where(fn ($q) => $this->scopeSeries($q, $event))
            ->where('starts_at', '<', $event->starts_at)
            ->orderByDesc('starts_at')
            ->pluck('id');

        // Stuck = open tasks created 3-6 meetings ago (skipping 2 most recent)
        // Tasks older than 6 meetings are just backlog — not red flags
        $stuckWindowIds = $previousEventIds->skip(2)->take(4)->values();

        if ($stuckWindowIds->isEmpty()) {
            return collect();
        }

        return $issues
            ->whereIn('status', ['open', 'in_progress'])
            ->filter(fn ($i) => $stuckWindowIds->contains($i->sourceable_id))
            ->take(5)
            ->values();
    }

    private function scopeSeries(Builder $query, CalendarEvent $event): void
    {
        if ($event->url) {
            $query->where('url', $event->url);
        } else {
            $query->where('title', $event->title)->whereNull('url');
        }
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
