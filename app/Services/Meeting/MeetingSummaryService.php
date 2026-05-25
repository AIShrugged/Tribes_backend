<?php

namespace App\Services\Meeting;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\FollowupStatus;
use App\Events\MeetingSummaryGenerated;
use App\Models\AgentActivityLog;
use App\Models\CalendarEvent;
use App\Models\MeetingSummary;
use App\Models\MeetingSummaryTemplate;
use App\Services\Followup\TranscriptBuilderService;
use App\Models\Setting;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class MeetingSummaryService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
    ) {
    }

    public function generate(CalendarEvent $event): MeetingSummary
    {
        $summary = $event->meetingSummary()->updateOrCreate([], [
            'status'     => FollowupStatus::IN_PROGRESS->value,
            'title'      => null,
            'summary'    => null,
            'key_points' => null,
            'decisions'  => null,
        ]);

        try {
            $transcript = $this->transcriptBuilder->build($event);

            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $this->buildPrompt($transcript, $event))],
                model: Setting::get('model.meeting_summary', config('ai.providers.openrouter.models.meeting_summary')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            if (!preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                throw new \RuntimeException('No JSON in LLM response');
            }
            $data = json_decode($matches[0], true);
            if (!$data) {
                throw new \RuntimeException('Invalid JSON in LLM response');
            }

            $summary->update([
                'status'      => FollowupStatus::DONE->value,
                'title'       => $data['title'] ?? null,
                'summary'     => $data['summary'] ?? null,
                'key_points'  => $data['key_points'] ?? [],
                'decisions'   => $data['decisions'] ?? [],
                'commitments' => $data['commitments'] ?? [],
            ]);

            MeetingSummaryGenerated::dispatch($summary->fresh());

            if ($event->source?->user) {
                AgentActivityLog::recordActivity(
                    user: $event->source->user,
                    toolName: 'meeting_summary_generated',
                    toolResult: [
                        'title' => $summary->title,
                        'event_id' => $event->id,
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::error('MeetingSummaryService: generation failed', ['error' => $e->getMessage()]);
            $summary->update(['status' => FollowupStatus::FAILED->value]);
        }

        return $summary->fresh();
    }

    private function buildPrompt(string $transcript, CalendarEvent $event): string
    {
        $example = $this->getProtocolExample();
        $meetingDate = \Carbon\Carbon::parse($event->starts_at)->format('d.m.Y');
        $nextDay = \Carbon\Carbon::parse($event->starts_at)->addDay()->format('d.m.Y');

        $substitutions = [
            '{transcript}'   => $transcript,
            '{meeting_date}' => $meetingDate,
            '{next_day}'     => $nextDay,
            '{example}'      => $example,
        ];

        $override = $this->resolvePromptOverride($event);
        if ($override !== null) {
            return strtr($override, $substitutions);
        }

        return app(LlmPromptService::class)->renderView(
            slug: 'meeting.summary.user',
            organizationId: $event->source?->organization_id,
            fallbackView: 'llm-prompts.meeting.summary-user',
            variables: [
                'transcript' => $transcript,
                'meeting_date' => $meetingDate,
                'next_day' => $nextDay,
                'example' => $example,
            ],
            name: 'Meeting summary prompt',
        );
    }

    /**
     * Resolve a team-configured prompt override for this event, if any.
     *
     * Looks up the event owner's first team (same pattern used in agenda/followup pipelines)
     * and returns its {@see MeetingSummaryTemplate::$prompt_override} when non-empty.
     */
    private function resolvePromptOverride(CalendarEvent $event): ?string
    {
        $teamId = $event->source?->user?->teams()->first()?->id;
        if (! $teamId) {
            return null;
        }

        $template = MeetingSummaryTemplate::where('team_id', $teamId)->first();
        $override = trim((string) ($template?->prompt_override ?? ''));

        return $override === '' ? null : $override;
    }

    /**
     * Default prompt template with {@see MeetingSummaryTemplate::PROMPT_PLACEHOLDERS}.
     * Used both as runtime fallback and as the seed text shown to admins in the editor.
     */
    public static function defaultPromptTemplate(): string
    {
        return view('llm-prompts.meeting.summary-user')->render();
    }

    private function getProtocolExample(): string
    {
        return <<<'EXAMPLE'
        Ключевые слова
        Генерация агенды, Paperclip AI, Протокол встречи, Follow-up, Интеграция API

        Краткое содержание
        - Отчёт по генерации агенды: Борис и Иван протестировали два контекста (общий knowledge base + статус проекта) — результат получился слишком абстрактным. Приняли решение перейти к митинго-ориентированным контекстам.
        - Константин доложил по Paperclip AI: инструмент развёрнут локально, проведено тестирование передачи задач между агентами, сгенерированы три плана интеграции с бэкендом.
        - Фёдор поделился опытом: запустил команду агентов без ограничений, за 4 часа они выполнили 110 issue, сожгли ~200 USD. Вывод: агентов необходимо воспринимать как цифровых сотрудников с онбордингом, лимитами и точками проверки.
        - Обсудили архитектуру интеграции: гибридный подход — агенты планирования через Paperclip API, агенты работы с данными остаются на нашей стороне. Доступ к БД — только через API.
        - Фёдор сформулировал требования к формату протокола: по итогу встречи должно быть чётко видно, о чём договорились и кто взял какие обязательства.
        - Согласована последовательность: summary → follow-up (summary + задачи) → агенда следующей встречи.
        EXAMPLE;
    }
}
