<?php

namespace App\Services\Demo;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\Setting;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class DemoTranscriptGeneratorService
{
    /** Meeting type → human-readable context for the LLM prompt */
    private const MEETING_CONTEXTS = [
        'standup'           => 'Ежедневный стендап (15-20 минут). Каждый рассказывает что делал вчера, что планирует сегодня, есть ли блокеры.',
        'planning'          => 'Планирование спринта (60-90 минут). Разбор бэклога, оценка задач в стори-поинтах, распределение по спринту. Обсуждение приоритетов.',
        'retrospective'     => 'Ретроспектива (45-60 минут). Что прошло хорошо, что можно улучшить, экшен-айтемы на следующий спринт.',
        'one_on_one'        => '1:1 встреча тимлида с одним из сотрудников (30 минут). Обсуждение прогресса, карьеры, обратная связь, личные вопросы.',
        'architecture'      => 'Ревью архитектуры (60 минут). Обсуждение технических решений, компромиссов, выбор подходов. Детальное техническое обсуждение.',
        'incident_review'   => 'Разбор инцидента / post-mortem (45 минут). Анализ причин сбоя, таймлайн событий, план предотвращения в будущем.',
    ];

    /**
     * Generate a realistic meeting transcript via LLM.
     *
     * @param  array  $personas  Array of persona objects with name, role, speaking_style
     * @param  string $meetingType  One of the keys in MEETING_CONTEXTS
     * @return array  Array of {speaker_name, text, offset_seconds}
     */
    public function generate(array $personas, string $meetingType): array
    {
        $context = self::MEETING_CONTEXTS[$meetingType] ?? self::MEETING_CONTEXTS['standup'];
        $prompt  = $this->buildPrompt($personas, $context, $meetingType);

        try {
            $json = app(OpenRouterClient::class)->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.demo', config('ai.providers.openrouter.models.demo')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            // Some LLM models prepend conversational text before the JSON object.
            // Fall back to extracting the first {...} block from the raw response.
            if (!is_array($data) && preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                $data = json_decode($matches[0], true);
            }

            if (!isset($data['transcript']) || !is_array($data['transcript'])) {
                Log::warning('DemoTranscriptGeneratorService: unexpected response', ['type' => $meetingType]);
                return [];
            }

            return $data['transcript'];
        } catch (\Throwable $e) {
            Log::error('DemoTranscriptGeneratorService: LLM call failed', [
                'meeting_type' => $meetingType,
                'error'        => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function buildPrompt(array $personas, string $context, string $meetingType): string
    {
        $participantList = collect($personas)->map(function ($p) {
            return "- {$p['name']} ({$p['role']}): {$p['speaking_style']}";
        })->join("\n");

        return app(LlmPromptService::class)->renderView(
            slug: 'demo.transcript.user',
            organizationId: null,
            fallbackView: 'llm-prompts.demo.transcript-user',
            variables: [
                'meeting_context' => $context,
                'participants' => $participantList,
            ],
            name: 'Demo transcript generation prompt',
        );
    }

    public static function getMeetingTitle(string $meetingType): string
    {
        return match ($meetingType) {
            'standup'         => 'Ежедневный стендап',
            'planning'        => 'Планирование спринта',
            'retrospective'   => 'Ретроспектива спринта',
            'one_on_one'      => '1:1',
            'architecture'    => 'Ревью архитектуры',
            'incident_review' => 'Разбор инцидента',
            default           => 'Встреча команды',
        };
    }

    /**
     * Return meeting types for a given count of meetings per team.
     * Prioritize diverse meeting types.
     */
    public static function getMeetingTypes(int $count): array
    {
        $sequence = ['standup', 'planning', 'retrospective', 'one_on_one', 'architecture', 'incident_review'];
        return array_slice($sequence, 0, $count);
    }
}
