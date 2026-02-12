# Changelog - 12 февраля 2026

## Ветка: use-insight-system

**Коммит:** `48946ad` (16:43)
**Залито в:** github_main/use-insight-system

---

## Описание изменений

Выполнена интеграция системы Insight для хранения памяти Telegram-агента. Вместо отдельной таблицы `user_memories` теперь используется универсальная система Insight с типами контекста.

---

## Рефакторинг архитектуры памяти агента

### 1. Миграция на Insight систему

#### Удалена таблица `user_memories`
- **Файл:** `database/migrations/2026_02_12_152402_drop_user_memories_table.php`
- Таблица больше не нужна, т.к. память теперь хранится в `insight_short_term` и `insight_long_term`

#### Переход на Telegram ID как primary key
- **Файл:** `database/migrations/2026_02_12_155014_use_telegram_id_as_primary_key.php`
- Таблица `telegram_users` теперь использует `telegram_user_id` (из Telegram API) вместо автоинкремента `id`
- Миграция поддерживает SQLite (для тестов) и PostgreSQL (для продакшена)
- Обновлены foreign key в `telegram_chat_messages`

#### Обновление типов контекста
- **Файл:** `database/migrations/2026_02_12_163643_update_telegram_agent_memory_to_general_knowledge.php`
- Переименован тип контекста с `telegram_agent_memory` → `general_knowledge`

---

### 2. Обновлена модель TelegramUser

**Файл:** `app/Models/TelegramUser.php`

**Изменения:**
- Primary key изменен с `id` на `telegram_user_id`
- Удалено свойство `telegram_id` (теперь это primary key)
- Отключен автоинкремент (`$incrementing = false`)
- Обновлены отношения с `User` и `TelegramChatMessage`

---

### 3. Расширен enum InsightContextType

**Файл:** `app/Enums/InsightContextType.php`

**Добавлены новые типы:**
- `GENERAL_KNOWLEDGE` — общие знания о пользователе (имя, предпочтения, привычки)
- `USER_ANALYSES` — анализы пользователя, предоставленные им
- `CHAT_SUMMARIES` — краткие выжимки из чатов пользователя

Эти типы позволяют структурированно хранить различные аспекты памяти агента.

---

### 4. Рефакторинг MemoryService

**Файл:** `app/Services/Agent/MemoryService.php`

**Основные изменения:**
- Удален метод `loadMemoriesFromOldTable()`
- Добавлен метод `getMemoryForAgent()` — загружает память по типам контекста:
  - `GENERAL_KNOWLEDGE`
  - `USER_ANALYSES`
  - `CHAT_SUMMARIES`
- Память загружается из `insight_short_term` с фильтрацией по `telegram_user_id`
- Форматирование памяти для отправки в LLM улучшено (группировка по типам)

**Улучшения:**
- Более структурированная загрузка памяти
- Поддержка множественных типов контекста
- Автоматическая обработка вложенного JSON в инсайтах

---

### 5. Улучшен инструмент UpdateMemoryTool

**Файл:** `app/Services/Agent/Tools/UpdateMemoryTool.php`

**Изменения:**
- Обновлены параметры инструмента — теперь требуется указывать `context_type`
- Поддержка всех типов контекста из `InsightContextType`
- Улучшено описание для LLM: указаны конкретные примеры использования каждого типа
- При сохранении автоматически определяется `context_id` (telegram_user_id)

**Новый формат вызова инструмента:**
```json
{
  "memory_content": "Пользователь предпочитает краткие ответы",
  "context_type": "general_knowledge"
}
```

---

### 6. Обновлен AgentService

**Файл:** `app/Services/Agent/AgentService.php`

**Изменения:**
- Обновлен вызов `MemoryService::getMemoryForAgent()` (новая сигнатура)
- Контекст передается в инструменты через `telegram_user_id` вместо `telegram_chat_id`
- Улучшена консистентность работы с идентификаторами

---

### 7. Обновлен TelegramBotController

**Файл:** `app/Http/Controllers/API/v1/TelegramBotController.php`

**Изменения:**
- Метод `firstOrCreate()` для `TelegramUser` теперь использует `telegram_user_id` как primary key
- Обновлен вызов `MemoryService::createMemories()` — передается `telegram_user_id`
- Удалена зависимость от старого поля `telegram_id`

---

### 8. Обновлена миграция add_user_id_to_profiles_table

**Файл:** `database/migrations/2026_02_10_163830_add_user_id_to_profiles_table.php`

**Изменения:**
- Добавлена связь `user_id` в таблицу `profiles` (nullable)
- Foreign key constraint с `onDelete('set null')`

---

## Статистика изменений

```
10 файлов изменено
+454 строк добавлено
-79 строк удалено
```

### Модифицированные файлы:
- `app/Enums/InsightContextType.php` (+7/-1)
- `app/Http/Controllers/API/v1/TelegramBotController.php` (+117/-79)
- `app/Models/TelegramUser.php` (+35/-35)
- `app/Services/Agent/AgentService.php` (+26/-26)
- `app/Services/Agent/MemoryService.php` (+67/-20)
- `app/Services/Agent/Tools/UpdateMemoryTool.php` (+59/-18)
- `database/migrations/2026_02_10_163830_add_user_id_to_profiles_table.php` (+7/-1)

### Новые файлы:
- `database/migrations/2026_02_12_152402_drop_user_memories_table.php` (+24)
- `database/migrations/2026_02_12_155014_use_telegram_id_as_primary_key.php` (+162)
- `database/migrations/2026_02_12_163643_update_telegram_agent_memory_to_general_knowledge.php` (+29)

---

## Преимущества новой архитектуры

1. **Унификация хранения памяти** — все типы памяти (краткосрочная и долгосрочная) используют единую систему Insight
2. **Типизация контекста** — enum `InsightContextType` обеспечивает строгую типизацию и структурированность данных
3. **Масштабируемость** — легко добавлять новые типы контекста без изменения структуры БД
4. **Семантическая ясность** — каждый тип памяти имеет четкое назначение:
   - `general_knowledge` — факты о пользователе
   - `user_analyses` — медицинские данные
   - `chat_summaries` — история общения
5. **Использование Telegram ID** — устранена избыточность (больше не нужен автоинкрементный `id`)

---

## Следующие шаги

- [ ] Тестирование миграций на staging окружении
- [ ] Проверка работы агента с новой архитектурой памяти
- [ ] Документирование API для работы с Insight-системой
- [ ] Оптимизация запросов к `insight_short_term` (индексы)

---

**Автор:** onlineheaven
**Дата:** 12 февраля 2026, 16:43