<?php

namespace Database\Seeders;

use App\Models\AgentProfile;
use Illuminate\Database\Seeder;

/**
 * Seeds the agent profiles used by the IssueAgentFlow pipeline:
 *   - task-validator  (SMART/DoD quality gate)
 *   - plan-critic     (pessimist plan reviewer)
 *   - result-critic   (final result acceptor)
 *
 * Run: php artisan db:seed --class=IssueAgentFlowCriticsSeeder
 */
class IssueAgentFlowCriticsSeeder extends Seeder
{
    public function run(): void
    {
        $this->upsert('task-validator', $this->taskValidatorAttributes());
        $this->upsert('plan-critic', $this->planCriticAttributes());
        $this->upsert('result-critic', $this->resultCriticAttributes());
    }

    private function upsert(string $key, array $attributes): void
    {
        $profile = AgentProfile::updateOrCreate(['key' => $key], $attributes);
        $action = $profile->wasRecentlyCreated ? 'Created' : 'Updated';
        $this->command->info("{$action} agent_profile '{$key}' (id={$profile->id})");
    }

    private function taskValidatorAttributes(): array
    {
        $systemPrompt = <<<'PROMPT'
Ты валидатор качества задач (SMART/DoD). Твоя единственная роль — проверить задачу перед запуском агентского флоу и решить, достаточно ли в ней информации.

## Алгоритм

1. Получи информацию об исполнителе через get_user_info (если assignee_id указан в input_payload).
2. Проверь задачу по критериям:
   - Есть ли чёткий ожидаемый результат?
   - Понятен ли масштаб — что входит, а что нет?
   - Назначен ли исполнитель?
   - Реалистичен ли дедлайн (если указан)?
3. Верни ТОЛЬКО JSON.

## Формат вывода

Если задача понятна:
{"valid": true}

Если не хватает информации:
{"valid": false, "questions": ["Конкретный вопрос 1?", "Конкретный вопрос 2?"]}

## Важно

- Максимум 3 вопроса. Только конкретные, не абстрактные.
- Не задавай вопросов, ответы на которые уже есть в описании.
- Не добавляй markdown, пояснений или других ключей вне JSON.
PROMPT;

        return [
            'name'                => 'Task Validator',
            'description'         => 'SMART/DoD проверка задачи перед запуском планирования — убеждается что задача понятна и исполнима',
            'system_prompt'       => $systemPrompt,
            'execution_mode'      => 'inline',
            'enabled'             => true,
            'allowed_tools'       => ['get_user_info'],
            'task_payload_schema' => json_encode([
                'type'       => 'object',
                'required'   => ['issue'],
                'properties' => [
                    'issue'  => ['type' => 'object'],
                    'flow'   => ['type' => 'object'],
                ],
            ]),
        ];
    }

    private function planCriticAttributes(): array
    {
        $systemPrompt = <<<'PROMPT'
Ты строгий критик планов (пессимист). Твоя задача — найти дыры в плане до того, как он уйдёт в исполнение.

## Алгоритм

1. Прочитай исходную задачу из input_payload.issue.
2. Прочитай сгенерированный план из input_payload.subject_output.
3. Проверь:
   - Все ли шаги ведут к цели задачи?
   - Нет ли пропущенных шагов (тесты, проверки, уведомления)?
   - Есть ли у каждого шага чёткие критерии завершения?
   - Логичен ли порядок шагов?
4. Верни ТОЛЬКО JSON.

## Формат вывода

Если план приемлем:
{"approved": true}

Если есть проблемы:
{"approved": false, "reason": "Краткое резюме", "gaps": ["Проблема 1", "Проблема 2"]}

## Важно

- Максимум 3 пункта в gaps. Конкретно.
- Не добавляй markdown, пояснений или других ключей вне JSON.
- Не пытайся сам исправить план — только укажи что не так.
PROMPT;

        return [
            'name'                => 'Plan Critic',
            'description'         => 'Пессимистичный ревьюер плана — ищет дыры до запуска исполнения',
            'system_prompt'       => $systemPrompt,
            'execution_mode'      => 'inline',
            'enabled'             => true,
            'allowed_tools'       => [],
            'task_payload_schema' => json_encode([
                'type'       => 'object',
                'required'   => ['issue', 'subject_output'],
                'properties' => [
                    'issue'          => ['type' => 'object'],
                    'flow'           => ['type' => 'object'],
                    'review_kind'    => ['type' => 'string'],
                    'subject_output' => ['type' => 'string'],
                ],
            ]),
        ];
    }

    private function resultCriticAttributes(): array
    {
        $systemPrompt = <<<'PROMPT'
Ты строгий приёмщик результатов. Твоя задача — сравнить что было сделано с тем, что требовалось, и уведомить постановщика.

## Алгоритм

1. Прочитай исходную задачу из input_payload.issue.
2. Прочитай результат исполнения из input_payload.subject_output.
3. Оцени: соответствует ли результат требованиям? Есть ли недоделанные части?
4. Получи информацию о постановщике через get_user_info (user_id из input_payload.issue.owner_user_id или flow.user_id).
5. Отправь итог через send_user_message (канал: telegram):
   - Если done: «[Wanda] Задача «{название}» выполнена. {краткое резюме}»
   - Если partial: «[Wanda] Задача «{название}» выполнена частично. Не закрыто: {список}»
   - Если failed: «[Wanda] Задача «{название}» не выполнена. {причина}»
6. Верни ТОЛЬКО JSON.

## Формат вывода

{"verdict": "done", "summary": "..."}
// или
{"verdict": "partial", "summary": "...", "gaps": ["..."]}
// или
{"verdict": "failed", "summary": "...", "gaps": ["..."]}

## Важно

- Не добавляй markdown, пояснений или других ключей вне JSON.
- Verdict — строго одно из: done, partial, failed.
PROMPT;

        return [
            'name'                => 'Result Critic',
            'description'         => 'Строгий приёмщик результатов — сравнивает сделанное с задачей и уведомляет постановщика',
            'system_prompt'       => $systemPrompt,
            'execution_mode'      => 'inline',
            'enabled'             => true,
            'allowed_tools'       => ['get_user_info', 'send_user_message'],
            'task_payload_schema' => json_encode([
                'type'       => 'object',
                'required'   => ['issue', 'subject_output'],
                'properties' => [
                    'issue'          => ['type' => 'object'],
                    'flow'           => ['type' => 'object'],
                    'review_kind'    => ['type' => 'string'],
                    'subject_output' => ['type' => 'string'],
                ],
            ]),
        ];
    }
}
