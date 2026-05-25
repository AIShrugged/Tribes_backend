<?php

namespace App\Services\Demo;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\Setting;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class DemoPersonaGeneratorService
{
    /**
     * Generate N unique personas via LLM.
     *
     * Returns array of persona objects:
     * [{ name, role, specialization, personality_traits, speaking_style }]
     */
    public function generate(int $count, string $teamContext = ''): array
    {
        $prompt = $this->buildPrompt($count, $teamContext);

        try {
            $json = app(OpenRouterClient::class)->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.demo', config('ai.providers.openrouter.models.demo')),
                maxTokens: 2048,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            if (!is_array($data) && preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                $data = json_decode($matches[0], true);
            }

            if (!isset($data['personas']) || !is_array($data['personas'])) {
                Log::warning('DemoPersonaGeneratorService: unexpected response', ['response' => $json]);
                return $this->fallbackPersonas($count);
            }

            return array_slice($data['personas'], 0, $count);
        } catch (\Throwable $e) {
            Log::error('DemoPersonaGeneratorService: LLM call failed', ['error' => $e->getMessage()]);
            return $this->fallbackPersonas($count);
        }
    }

    private function buildPrompt(int $count, string $teamContext): string
    {
        $contextHint = $teamContext ? "Контекст команды: {$teamContext}." : '';

        return app(LlmPromptService::class)->renderView(
            slug: 'demo.personas.user',
            organizationId: null,
            fallbackView: 'llm-prompts.demo.personas-user',
            variables: [
                'count' => $count,
                'context_hint' => $contextHint,
            ],
            name: 'Demo persona generation prompt',
        );
    }

    private function fallbackPersonas(int $count): array
    {
        $pool = [
            ['name' => 'Алексей Громов', 'role' => 'Backend Developer', 'specialization' => 'Микросервисы, PHP, PostgreSQL', 'personality_traits' => 'Методичный, вдумчивый, любит документацию', 'speaking_style' => 'Говорит по делу, приводит конкретные цифры'],
            ['name' => 'Мария Соколова', 'role' => 'Frontend Developer', 'specialization' => 'React, TypeScript, UX', 'personality_traits' => 'Инициативная, ориентирована на пользователя, творческая', 'speaking_style' => 'Энергичная, много примеров, думает о пользователях'],
            ['name' => 'Дмитрий Орлов', 'role' => 'DevOps Engineer', 'specialization' => 'Kubernetes, CI/CD, мониторинг', 'personality_traits' => 'Практичный, осторожный, системный', 'speaking_style' => 'Краткий, технический, говорит о рисках'],
            ['name' => 'Анна Белова', 'role' => 'QA Engineer', 'specialization' => 'Автотесты, регрессия, нагрузочное тестирование', 'personality_traits' => 'Внимательная к деталям, настойчивая, ответственная', 'speaking_style' => 'Задаёт уточняющие вопросы, говорит о edge cases'],
            ['name' => 'Сергей Новиков', 'role' => 'Product Manager', 'specialization' => 'Роадмап, метрики, коммуникация со стейкхолдерами', 'personality_traits' => 'Стратегический, коммуникабельный, ориентирован на результат', 'speaking_style' => 'Говорит о бизнес-ценности, ставит приоритеты'],
            ['name' => 'Екатерина Ли', 'role' => 'Data Analyst', 'specialization' => 'SQL, Python, аналитика продукта', 'personality_traits' => 'Аналитическая, дотошная, основывается на данных', 'speaking_style' => 'Ссылается на данные, задаёт вопросы о метриках'],
            ['name' => 'Павел Кузнецов', 'role' => 'Tech Lead', 'specialization' => 'Архитектура, код-ревью, менторинг', 'personality_traits' => 'Опытный, требовательный, справедливый', 'speaking_style' => 'Думает вслух, объясняет решения, задаёт сложные вопросы'],
            ['name' => 'Ольга Степанова', 'role' => 'UX Designer', 'specialization' => 'Figma, исследования, прототипирование', 'personality_traits' => 'Креативная, эмпатичная, ориентирована на пользователя', 'speaking_style' => 'Рассказывает о пользовательских сценариях, показывает макеты'],
            ['name' => 'Артём Васильев', 'role' => 'Backend Developer', 'specialization' => 'API, интеграции, безопасность', 'personality_traits' => 'Инициативный, перфекционист, разбирается в деталях', 'speaking_style' => 'Предлагает альтернативы, думает о безопасности'],
            ['name' => 'Наталья Попова', 'role' => 'Scrum Master', 'specialization' => 'Agile, ретроспективы, командная динамика', 'personality_traits' => 'Организованная, дипломатичная, поддерживающая', 'speaking_style' => 'Фасилитирует дискуссию, резюмирует, задаёт открытые вопросы'],
        ];

        return array_slice($pool, 0, $count);
    }
}
