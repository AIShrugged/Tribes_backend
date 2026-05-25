<?php

namespace App\Services\Agenda;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\AgendaStatus;
use App\Models\AgendaTemplate;
use App\Models\AgentActivityLog;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Setting;
use App\Models\UpcomingAgenda;
use App\Models\User;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class UpcomingAgendaService
{
    public function __construct(
        private readonly AgendaRenderer $renderer,
    ) {}


    public function generateForEvent(CalendarEvent $event): void
    {
        $event->loadMissing(
            'participants.profile.user',
            'profiles.user',
            'sources.user',
            'creator',
            'meetingSummary',
            'issues',
        );

        $resolvedUsers = collect();

        // From participants with linked profiles
        $resolvedUsers = $resolvedUsers->merge(
            $event->participants
                ->filter(fn ($p) => $p->profile?->user_id)
                ->map(fn ($p) => $p->profile->user)
        );

        // From calendar_event_profile (Google Calendar attendees with profiles)
        $resolvedUsers = $resolvedUsers->merge(
            $event->profiles
                ->filter(fn ($p) => $p->user_id)
                ->map(fn ($p) => $p->user)
        );

        // From calendar sources
        $resolvedUsers = $resolvedUsers->merge(
            $event->sources
                ->filter(fn ($s) => $s->user_id)
                ->map(fn ($s) => $s->user)
        );

        // From event creator
        if ($event->creator_user_id && $event->creator) {
            $resolvedUsers->push($event->creator);
        }

        $resolvedUsers = $resolvedUsers->filter()->unique('id');

        foreach ($resolvedUsers as $user) {
            $this->generateForUser($event, $user);
        }
    }

    private function generateForUser(CalendarEvent $event, User $user): void
    {
        $agenda = UpcomingAgenda::updateOrCreate(
            ['user_id' => $user->id, 'series_key' => $event->seriesKey()],
            [
                'source_calendar_event_id' => $event->id,
                'status' => AgendaStatus::IN_PROGRESS->value,
                'content' => null,
                'raw_json' => null,
            ],
        );

        try {
            $userIssues = Issue::query()
                ->withoutTrashed()
                ->where('assignee_id', $user->id)
                ->whereIn('status', ['open', 'in_progress'])
                ->orderBy('due_date')
                ->get();

            $prompt = $this->buildPrompt($event, $user, $userIssues->all());

            $json = app(OpenRouterClient::class)->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.agenda', config('ai.providers.openrouter.models.agenda')),
                maxTokens: 8192,
                forceJsonResponse: true,
            );

            if (! preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                throw new \RuntimeException('No JSON found in LLM response');
            }

            $data = json_decode($matches[0], true);

            if (! is_array($data)) {
                throw new \RuntimeException('LLM returned invalid JSON: ' . substr($matches[0], 0, 200));
            }

            $template = $this->resolveTemplate($user);

            $agenda->update([
                'status' => AgendaStatus::DONE->value,
                'raw_json' => $data,
                'content' => $this->renderer->renderForWeb($data, $event, $template),
            ]);

            AgentActivityLog::recordActivity(
                user: $user,
                toolName: 'upcoming_agenda_generated',
                toolResult: [
                    'calendar_event_id' => $event->id,
                    'user_id' => $user->id,
                ],
            );
        } catch (\Throwable $e) {
            $agenda->update([
                'status' => AgendaStatus::FAILED->value,
                'raw_json' => ['error' => $e->getMessage()],
            ]);
            Log::error('Upcoming agenda generation failed', [
                'user_id' => $user->id,
                'calendar_event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildPrompt(CalendarEvent $event, User $user, array $userIssues): string
    {
        $summary = $event->meetingSummary;
        $participants = $event->participants->pluck('name')->implode(', ');

        $parts = [];
        $parts[] = "Ты — ассистент для подготовки к рабочим встречам. Участник: {$user->name}.";
        $parts[] = '';
        $parts[] = "Только что завершилась встреча: {$event->title}";
        $parts[] = "Дата: {$event->starts_at->format('d.m.Y H:i')}";
        $parts[] = "Участники: {$participants}";

        if ($summary) {
            $parts[] = '';
            $parts[] = '--- ИТОГИ ПРОШЕДШЕЙ ВСТРЕЧИ ---';
            $parts[] = "Краткое содержание: {$summary->summary}";

            if (! empty($summary->key_points)) {
                $parts[] = 'Ключевые моменты:';
                foreach ($summary->key_points as $point) {
                    $parts[] = "- {$point}";
                }
            }

            if (! empty($summary->decisions)) {
                $parts[] = 'Принятые решения:';
                foreach ($summary->decisions as $decision) {
                    $parts[] = "- {$decision}";
                }
            }
        }

        if (! empty($userIssues)) {
            $parts[] = '';
            $parts[] = '--- ОТКРЫТЫЕ ЗАДАЧИ УЧАСТНИКА ---';
            foreach ($userIssues as $issue) {
                $due = $issue->due_date ? " (срок: {$issue->due_date->format('d.m.Y')})" : '';
                $parts[] = "- [{$issue->status}] {$issue->name}{$due}";
            }
        }

        $parts[] = '';
        $parts[] = 'Сгенерируй JSON с полями для подготовки к следующей встрече с этими людьми:';
        $parts[] = '- "next_meeting_context": краткий контекст — о чём эти встречи и что важно учесть (2-3 предложения)';
        $parts[] = '- "follow_up_items": массив строк — что нужно сделать или проверить после этой встречи (3-5 пунктов)';
        $parts[] = '- "open_questions": массив строк — вопросы которые остались открытыми или требуют ответа (2-4 пункта)';
        $parts[] = '- "focus_areas": массив строк — на что сделать акцент на следующей встрече (2-4 пункта)';

        return app(LlmPromptService::class)->renderView(
            slug: 'agenda.upcoming.user',
            organizationId: $event->source?->organization_id,
            fallbackView: 'llm-prompts.shared.prompt-body',
            variables: ['prompt_body' => implode("\n", $parts)],
            name: 'Upcoming agenda prompt',
        );
    }

    private function resolveTemplate(User $user): ?AgendaTemplate
    {
        $teamId = $user->teams()->first()?->id;
        if (! $teamId) {
            return null;
        }

        return AgendaTemplate::where('team_id', $teamId)->first();
    }
}
