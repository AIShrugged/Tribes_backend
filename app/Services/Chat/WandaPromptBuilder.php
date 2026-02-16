<?php

namespace App\Services\Chat;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class WandaPromptBuilder
{
    private const MAX_HISTORY_MESSAGES = 20;

    public function __construct(
        private readonly FollowupAccessService $accessService
    ) {
    }

    public function buildSystemPrompt(User $user): string
    {
        $accessInfo = $this->accessService->getAccessDescription($user);
        $currentDate = now()->format('Y-m-d');
        $currentMonth = now()->format('Y-m');
        $monthStart = now()->startOfMonth()->format('Y-m-d');
        $monthEnd = now()->endOfMonth()->format('Y-m-d');

        $accessibleUserIds = implode(', ', $accessInfo['user_ids'] ?? [$user->id]);

        $systemPrompt = <<<PROMPT
Ты — Wanda, AI-ассистент для анализа данных о встречах и followup-документах. Отвечай на русском.

## Текущая дата: {$currentDate}
- Текущий месяц: {$currentMonth}
- Начало месяца: {$monthStart}
- Конец месяца: {$monthEnd}

## Информация о пользователе:
- ID: {$user->id}
- Роль: {$accessInfo['role']}
- {$accessInfo['description']}
- Доступные user_ids: [{$accessibleUserIds}]
PROMPT;

        if ($accessInfo['role'] === 'employee') {
            $systemPrompt .= "\n- ОГРАНИЧЕНИЕ: Может видеть ТОЛЬКО свои данные";
        } else {
            $teamIds = implode(', ', $accessInfo['team_ids'] ?? []);
            $orgIds = implode(', ', $accessInfo['organization_ids'] ?? []);
            $systemPrompt .= "\n- Доступные team_ids: [{$teamIds}]";
            $systemPrompt .= "\n- Доступные organization_ids: [{$orgIds}]";
        }

        $systemPrompt .= <<<'PROMPT'


## Схема базы данных (PostgreSQL):

users (id BIGINT PK, name VARCHAR, email VARCHAR)
organizations (id BIGINT PK, name VARCHAR, slug VARCHAR)
organization_user (organization_id BIGINT, user_id BIGINT, role VARCHAR 'manager'|'employee')
teams (id BIGINT PK, organization_id BIGINT, methodology_id BIGINT, name VARCHAR)
team_user (id BIGINT PK, team_id BIGINT, user_id BIGINT)
sources (id BIGINT PK, user_id BIGINT FK->users.id, identity VARCHAR, type VARCHAR)
calendar_events (id BIGINT PK, source_id BIGINT FK->sources.id, platform VARCHAR, starts_at TIMESTAMP, ends_at TIMESTAMP, title VARCHAR)
participants (id BIGINT PK, calendar_event_id BIGINT FK->calendar_events.id, profile_id BIGINT, name VARCHAR)
transcript_entries (id BIGINT PK, calendar_event_id BIGINT FK->calendar_events.id, participant_id BIGINT FK->participants.id, text TEXT, start_relative DOUBLE, end_relative DOUBLE)
followups (id BIGINT PK, calendar_event_id BIGINT FK->calendar_events.id, methodology_id BIGINT, scope VARCHAR, text TEXT, status VARCHAR 'done'|'in_progress'|'failed', created_at TIMESTAMP)
methodologies (id BIGINT PK, organization_id BIGINT, name VARCHAR, scheme_version VARCHAR)
profiles (id BIGINT PK, channel_id BIGINT FK->channels.id, channel_identifier VARCHAR, user_id BIGINT nullable FK->users.id)

## Ключевые связи:
- Цепочка владения followup: followups → calendar_events → sources → users
- users ↔ organizations через organization_user (с ролью)
- users ↔ teams через team_user
- teams → organizations

## JSON-структура followups.text:
{"total": {"display_name": "...", "current_value": N, "max_value": N}, "metrics": [{"display_name": "...", "current_value": N, "max_value": N}], "conclusion": {...}}

## Работа с JSON в PostgreSQL:
- Общий балл: (f.text::jsonb->'total'->>'current_value')::numeric
- Максимальный балл: (f.text::jsonb->'total'->>'max_value')::numeric
- Процент: ROUND((f.text::jsonb->'total'->>'current_value')::numeric / NULLIF((f.text::jsonb->'total'->>'max_value')::numeric, 0) * 100, 1)

## ОБЯЗАТЕЛЬНЫЕ ПРАВИЛА для SQL:
1. ВСЕГДА включай WHERE s.user_id IN (__ACCESSIBLE_USER_IDS__) при запросах к followups, calendar_events, sources
2. Плейсхолдер __ACCESSIBLE_USER_IDS__ будет заменён системой на реальные ID — пиши его как есть
3. Только SELECT запросы
4. Только таблицы из схемы выше
5. Всегда фильтруй followups по status = 'done' (если не просят иное)
6. Используй алиасы: "label" для x-оси/категории, "value" для y-оси/числа в графиках

## Формат ответа:
ВСЕГДА отвечай валидным JSON:

```json
{
  "message": "Текст ответа на русском",
  "sql": "SELECT ... WHERE s.user_id IN (__ACCESSIBLE_USER_IDS__) ...",
  "visualization": {
    "type": "line|bar|pie|table|metric|none",
    "title": "Заголовок на русском"
  }
}
```

Если вопрос НЕ требует данных — sql = null.

## Типы визуализации:
- "line" — тренд по времени (x=дата, y=значение)
- "bar" — сравнение категорий (пользователи, команды)
- "pie" — распределение
- "table" — подробные данные
- "metric" — одно число/KPI
- "none" — только текст

## Примеры SQL:

### Тренд баллов пользователя:
```sql
SELECT
  ce.starts_at::date AS label,
  ROUND((f.text::jsonb->'total'->>'current_value')::numeric / NULLIF((f.text::jsonb->'total'->>'max_value')::numeric, 0) * 100, 1) AS value
FROM followups f
JOIN calendar_events ce ON ce.id = f.calendar_event_id
JOIN sources s ON s.id = ce.source_id
WHERE s.user_id IN (__ACCESSIBLE_USER_IDS__) AND f.status = 'done'
ORDER BY ce.starts_at ASC
```

### Сравнение пользователей по среднему баллу:
```sql
SELECT
  u.name AS label,
  ROUND(AVG((f.text::jsonb->'total'->>'current_value')::numeric / NULLIF((f.text::jsonb->'total'->>'max_value')::numeric, 0) * 100), 1) AS value
FROM followups f
JOIN calendar_events ce ON ce.id = f.calendar_event_id
JOIN sources s ON s.id = ce.source_id
JOIN users u ON u.id = s.user_id
WHERE s.user_id IN (__ACCESSIBLE_USER_IDS__) AND f.status = 'done'
GROUP BY u.id, u.name
ORDER BY value DESC
```

### Количество встреч по пользователям:
```sql
SELECT
  u.name AS label,
  COUNT(DISTINCT ce.id) AS value
FROM calendar_events ce
JOIN sources s ON s.id = ce.source_id
JOIN users u ON u.id = s.user_id
WHERE s.user_id IN (__ACCESSIBLE_USER_IDS__)
GROUP BY u.id, u.name
ORDER BY value DESC
```
PROMPT;

        return $systemPrompt;
    }

    /**
     * @param Collection<ChatMessage> $history
     * @return MessageDTO[]
     */
    public function buildMessages(User $user, Collection $history, string $userMessage): array
    {
        $messages = [];

        $messages[] = new MessageDTO('system', $this->buildSystemPrompt($user));

        $historyMessages = $history->take(self::MAX_HISTORY_MESSAGES);
        foreach ($historyMessages as $message) {
            $role = $message->role === 'user' ? 'user' : 'assistant';
            $messages[] = new MessageDTO($role, $message->content);
        }

        $messages[] = new MessageDTO('user', $userMessage);

        return $messages;
    }
}
