<?php

namespace App\Services\Agenda;

use Carbon\Carbon;
use App\Domain\DTO\AI\MessageDTO;
use App\Enums\AgendaStatus;
use App\Models\AgendaTemplate;
use App\Models\AgentActivityLog;
use App\Models\CalendarEvent;
use App\Services\CalendarEventOrganizationResolver;
use App\Models\Issue;
use App\Models\MeetingAgenda;
use App\Models\MeetingSeriesState;
use App\Models\MeetingSummary;
use App\Models\Setting;
use App\Models\UpcomingAgenda;
use App\Models\User;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AgendaService
{
    public function __construct(
        private readonly PreviousMeetingResolver $previousMeetingResolver,
        private readonly CommitmentAnalyzer $commitmentAnalyzer,
        private readonly AgendaDataCollector $dataCollector,
        private readonly AgendaRenderer $renderer,
    ) {}

    private function resolveTemplate(CalendarEvent $event): ?AgendaTemplate
    {
        $teamId = app(CalendarEventOrganizationResolver::class)->resolveDefaultTeamId($event);
        if (! $teamId) {
            return null;
        }

        return AgendaTemplate::where('team_id', $teamId)->first();
    }

    public function generateForEvent(CalendarEvent $event): void
    {
        $event->load('source.user.teams', 'source.user.organizations', 'participants.profile.user.telegramUser');

        $previousEvents  = $this->previousMeetingResolver->resolveMany($event, 3);
        $previousEvent   = $previousEvents->first();
        $previousSummary = $previousEvent?->meetingSummary;

        if ($previousEvents->isEmpty()) {
            Log::info('Agenda generation skipped: no previous meetings with summaries', [
                'calendar_event_id' => $event->id,
            ]);
            return;
        }

        $seriesEventIds = CalendarEvent::query()
            ->inSameSeriesAs($event)
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

        $organization = $event->source?->user?->organizations?->first();
        $orgContext   = $organization?->context;

        $seriesState = MeetingSeriesState::where(
            'series_identifier',
            MeetingSeriesState::buildSeriesIdentifier($event),
        )->first();

        if (!$seriesState) {
            $seriesState = MeetingSeriesState::query()
                ->whereHas('sourceEvent', fn ($q) => $q->inSameSeriesAs($event))
                ->orderByDesc('version')
                ->first();
        }

        $participantUserIds = $event->participants
            ->filter(fn ($p) => $p->profile?->user_id)
            ->pluck('profile.user_id')
            ->unique();

        $upcomingAgendas = $participantUserIds->isNotEmpty()
            ? UpcomingAgenda::whereIn('user_id', $participantUserIds)
                ->where('series_key', $event->seriesKey())
                ->where('status', AgendaStatus::DONE->value)
                ->get()
            : collect();

        $previousAgenda = $previousEvent
            ? MeetingAgenda::where('calendar_event_id', $previousEvent->id)
                ->where('type', 'general')
                ->where('status', AgendaStatus::DONE)
                ->first()
            : null;

        $context = new AgendaContext(
            orgContext:      $orgContext,
            seriesState:     $seriesState,
            previousEvents:  $previousEvents,
            upcomingAgendas: $upcomingAgendas,
            previousAgenda:  $previousAgenda,
        );

        $this->generateGeneralAgenda($event, $previousEvent, $previousSummary, $issues, $seriesEventIds, $context);

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
        Collection $seriesEventIds,
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
                'user_id'           => null,
                'type'              => 'general',
                'status'            => AgendaStatus::IN_PROGRESS,
            ]);
        } else {
            $agenda->update([
                'status'  => AgendaStatus::IN_PROGRESS,
                'raw_json' => null,
                'content'  => null,
            ]);
        }

        try {
            $prompt = $this->buildGeneralPrompt($event, $issues, $context);
            $json   = app(OpenRouterClient::class)->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.agenda', config('ai.providers.openrouter.models.agenda')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            if (!preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                throw new \RuntimeException('No JSON in LLM response');
            }
            $llmData       = json_decode($matches[0], true) ?? [];
            $structuredData = $this->dataCollector->collectStructuredData($event, $previousEvent, $previousSummary, $issues, $seriesEventIds);

            $prevDate  = $previousEvent ? Carbon::parse($previousEvent->starts_at) : null;
            $questions = $llmData['questions'] ?? [];

            $commitmentsCheck     = [];
            $structuredCommitments = $previousSummary?->commitments;

            if (!empty($structuredCommitments) && is_array($structuredCommitments)) {
                foreach ($structuredCommitments as $i => $c) {
                    $person     = $c['who'] ?? '';
                    $commitment = $c['what'] ?? '';
                    $deadline   = isset($c['deadline']) && $c['deadline']
                        ? Carbon::parse($c['deadline'])->format('d.m.Y')
                        : null;
                    $match            = $this->commitmentAnalyzer->matchCommitmentToIssue($person, $commitment, $issues);
                    $commitmentsCheck[] = [
                        'person'     => $person,
                        'commitment' => $commitment,
                        'deadline'   => $deadline,
                        'status'     => $match['status'],
                        'issue_id'   => $match['issue_id'],
                        'question'   => $questions[$i] ?? 'статус?',
                    ];
                }
            } else {
                $commitments = $this->commitmentAnalyzer->extractCommitmentsFromSummary($previousSummary?->summary);
                foreach ($commitments as $i => $raw) {
                    $parsed           = $this->commitmentAnalyzer->parseCommitment($raw, $prevDate);
                    $match            = $this->commitmentAnalyzer->matchCommitmentToIssue($parsed['person'], $parsed['commitment'], $issues);
                    $commitmentsCheck[] = [
                        'person'     => $parsed['person'],
                        'commitment' => $parsed['commitment'],
                        'deadline'   => $parsed['deadline'],
                        'status'     => $match['status'],
                        'issue_id'   => $match['issue_id'],
                        'question'   => $questions[$i] ?? 'статус?',
                    ];
                }
            }

            $doneCount  = count(array_filter($commitmentsCheck, fn ($c) => $c['status'] === 'готово'));
            $totalCount = count($commitmentsCheck);

            $decisions      = $previousSummary?->decisions ?? null;
            $decisionsArray = is_string($decisions)
                ? json_decode($decisions, true) ?? []
                : ($decisions ?? []);

            $tasksBetween = $this->dataCollector->getTasksBetweenMeetings($event, $previousEvent, $issues);
            $backlogStats = $this->dataCollector->getBacklogStats($issues, $previousEvent);
            $prevTopics   = $this->dataCollector->extractTopicsFromSummary($previousSummary?->summary);
            $tgTopics     = $this->dataCollector->getTelegramTopics($event, $previousEvent);

            $renderData = [
                'event'             => $event,
                'meeting_goal'      => $llmData['meeting_goal'] ?? '',
                'discussion_topics' => $llmData['discussion_topics'] ?? [],
                'main_problem'      => $llmData['main_problem'] ?? '',
                'prev_topics'       => $prevTopics,
                'commitments_check' => $commitmentsCheck,
                'commitments_done'  => $doneCount,
                'commitments_total' => $totalCount,
                'decisions_recap'   => $decisionsArray,
                'tasks_between'     => $tasksBetween,
                'backlog_stats'     => $backlogStats,
                'tg_topics'         => $tgTopics,
            ];

            $template = $this->resolveTemplate($event);

            $agenda->update([
                'status'   => AgendaStatus::DONE,
                'raw_json' => array_merge($renderData, $structuredData, ['event' => null]),
                'content'  => $this->renderer->renderForWeb($renderData, $event, $template),
            ]);

            if ($event->source?->user) {
                AgentActivityLog::recordActivity(
                    user: $event->source->user,
                    toolName: 'agenda_generated_general',
                    toolResult: ['calendar_event_id' => $event->id],
                );
            }
        } catch (\Throwable $e) {
            $agenda->update(['status' => AgendaStatus::FAILED]);
            Log::error('General agenda generation failed', [
                'calendar_event_id' => $event->id,
                'error'             => $e->getMessage(),
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
                'user_id'           => $user->id,
                'type'              => 'personal',
                'status'            => AgendaStatus::IN_PROGRESS,
            ]);
        } else {
            $agenda->update([
                'status'  => AgendaStatus::IN_PROGRESS,
                'raw_json' => null,
                'content'  => null,
            ]);
        }

        try {
            $userIssues = $allIssues->where('assignee_id', $user->id);
            $prompt     = $this->buildPersonalPrompt($event, $user, $previousEvent, $userIssues, $context);
            $json       = app(OpenRouterClient::class)->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.agenda', config('ai.providers.openrouter.models.agenda')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            $agenda->update([
                'status'   => AgendaStatus::DONE,
                'raw_json' => json_decode($json, true),
                'content'  => $this->renderer->renderPersonalContent(json_decode($json, true), $user),
            ]);

            AgentActivityLog::recordActivity(
                user: $user,
                toolName: 'agenda_generated_personal',
                toolResult: [
                    'calendar_event_id' => $event->id,
                    'user_id'           => $user->id,
                ],
            );
        } catch (\Throwable $e) {
            $agenda->update(['status' => AgendaStatus::FAILED]);
            Log::error('Personal agenda generation failed', [
                'calendar_event_id' => $event->id,
                'user_id'           => $user->id,
                'error'             => $e->getMessage(),
            ]);
        }

        return $agenda;
    }

    private function buildGeneralPrompt(
        CalendarEvent $event,
        Collection $issues,
        AgendaContext $context,
    ): string {
        $eventStartsAt = Carbon::parse($event->starts_at);

        $parts   = [];
        $parts[] = 'Ты — ассистент для подготовки к рабочим встречам. Сгенерируй агенду для предстоящей планёрки.';
        $parts[] = '';
        $parts[] = "Название встречи: {$event->title}";
        $parts[] = "Дата и время: {$eventStartsAt->format('d.m.Y H:i')}";

        if ($context->orgContext) {
            $parts[] = '';
            $parts[] = '--- КОНТЕКСТ ПРОЕКТА ---';
            $parts[] = $context->orgContext;
        }

        if ($context->seriesState?->content) {
            $parts[] = '';
            $parts[] = '--- ТЕКУЩЕЕ СОСТОЯНИЕ ПРОЕКТА ---';
            $parts[] = $context->seriesState->content;
        }

        $allCommitments = [];
        $allDecisions   = [];

        if ($context->previousEvents->isNotEmpty()) {
            $prevEvent = $context->previousEvents->first();
            $summary   = $prevEvent?->meetingSummary;

            if ($summary) {
                $prevMeetingDate = Carbon::parse($prevEvent->starts_at);
                $allCommitments  = $this->commitmentAnalyzer->extractCommitmentsFromSummary($summary->summary);

                $decisions    = is_string($summary->decisions ?? null)
                    ? json_decode($summary->decisions, true) ?? []
                    : ($summary->decisions ?? []);
                $allDecisions = $decisions;

                $summaryText = mb_substr($summary->summary, 0, 500);
                $summaryText = preg_replace('/### Следующие шаги.*$/s', '', $summaryText);
                $summaryText = preg_replace('/=== COMMITMENTS ===.*$/s', '', $summaryText);

                $parts[] = '';
                $parts[] = '--- КОНТЕКСТ ПРЕДЫДУЩЕЙ ВСТРЕЧИ ---';
                $parts[] = "Дата: {$prevMeetingDate->format('d.m.Y')}";
                $parts[] = trim($summaryText);
            }
        }

        $prevRepeated = isset($summary) ? ($summary->repeated_discussions ?? []) : [];
        if (!empty($prevRepeated)) {
            $parts[] = '';
            $parts[] = '--- ПОВТОРЯЮЩИЕСЯ ОБСУЖДЕНИЯ ---';
            $parts[] = 'На прошлой встрече были выявлены темы, которые команда уже обсуждала ранее:';
            foreach ($prevRepeated as $repeat) {
                $prevDate = Carbon::parse($repeat['previous_date'])->format('d.m.Y');
                $parts[] = "• «{$repeat['new_decision']}» — похожее решение уже принималось {$prevDate}: «{$repeat['previous_decision']}»";
            }
            $parts[] = 'Если эти темы снова актуальны — включи их в discussion_topics с пометкой о предыдущем решении.';
        }

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

        return app(LlmPromptService::class)->renderView(
            slug: 'agenda.general.user',
            organizationId: $event->source?->organization_id,
            fallbackView: 'llm-prompts.agenda.general-user',
            variables: ['sections' => implode("\n", $parts)],
            name: 'General agenda prompt',
        );
    }

    private function buildPersonalPrompt(
        CalendarEvent $event,
        User $user,
        ?CalendarEvent $previousEvent,
        Collection $userIssues,
        AgendaContext $context,
    ): string {
        $eventStartsAt    = Carbon::parse($event->starts_at);
        $previousStartsAt = $previousEvent?->starts_at ? Carbon::parse($previousEvent->starts_at) : null;

        $parts   = [];
        $parts[] = "Ты — ассистент для подготовки к рабочим встречам. Сгенерируй персональную агенду для участника {$user->name}.";
        $parts[] = '';
        $parts[] = "Название встречи: {$event->title}";
        $parts[] = "Дата и время: {$eventStartsAt->format('d.m.Y H:i')}";

        if ($context->orgContext) {
            $parts[] = '';
            $parts[] = '--- КОНТЕКСТ ПРОЕКТА ---';
            $parts[] = $context->orgContext;
        }

        if ($context->seriesState?->content) {
            $parts[] = '';
            $parts[] = '--- ТЕКУЩЕЕ СОСТОЯНИЕ ПРОЕКТА ---';
            $parts[] = $context->seriesState->content;
        }

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

        $userUpcoming = $context->upcomingAgendas->firstWhere('user_id', $user->id);
        if ($userUpcoming?->raw_json) {
            $followUps    = $userUpcoming->raw_json['follow_up_items'] ?? [];
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

        $openTasks = $userIssues->whereIn('status', ['open', 'in_progress']);
        if ($openTasks->isNotEmpty()) {
            $parts[] = '';
            $parts[] = '--- ТЕКУЩИЕ ЗАДАЧИ (open / in_progress) ---';
            foreach ($openTasks as $issue) {
                $due     = $issue->due_date ? " | срок: {$issue->due_date->format('d.m.Y')}" : '';
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
                $closed  = $issue->close_date ? " | закрыта: {$issue->close_date->format('d.m.Y')}" : '';
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

        return app(LlmPromptService::class)->renderView(
            slug: 'agenda.personal.user',
            organizationId: $event->source?->organization_id,
            fallbackView: 'llm-prompts.agenda.personal-user',
            variables: ['sections' => implode("\n", $parts)],
            name: 'Personal agenda prompt',
        );
    }
}
