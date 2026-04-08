# Insight System — Архитектура системы памяти и персонализации

**Версия:** 1.0
**Дата:** 12 февраля 2026
**Статус:** Production-ready

---

## Оглавление

1. [Executive Summary](#executive-summary)
2. [Архитектурные принципы](#архитектурные-принципы)
3. [Компоненты системы](#компоненты-системы)
4. [Структура данных](#структура-данных)
5. [Pipeline обработки](#pipeline-обработки)
6. [Интеграция с Telegram Agent](#интеграция-с-telegram-agent)
7. [API и использование](#api-и-использование)
8. [Технический стек](#технический-стек)
9. [Roadmap](#roadmap)

---

## Executive Summary

**Insight System** — это многоуровневая система памяти и персонализации для AI агентов, реализующая паттерн **hierarchical memory consolidation** на основе актуальных исследований (MemGPT, Mem0, OpenClaw).

### Ключевые возможности

- **Краткосрочная память** (Short-Term) — контекст текущих проектов, решений, эмоционального состояния (TTL: 3 месяца)
- **Долгосрочная память** (Profile) — стабильные характеристики пользователя (стиль коммуникации, цели, навыки)
- **Извлечение инсайтов** — автоматическое извлечение ключевых фактов из митингов через LLM
- **Эволюция профилей** — консолидация кратковременных инсайтов в долгосрочные профили
- **Контекстная память для агентов** — интеграция с Telegram Agent для персонализированного общения

### Архитектурная основа

Система основана на:
- **MemGPT OS Model** — иерархическая память с разделением working/long-term context
- **OpenClaw Simplicity** — JSONL audit trail + structured data (PostgreSQL)
- **Hybrid Retrieval** — метаданные + recency bias + контекстная фильтрация
- **Event-Driven Architecture** — асинхронная обработка через Laravel Events

---

## Архитектурные принципы

### 1. Иерархическая память (Multi-Tier)

```
┌─────────────────────────────────────────────────┐
│  Tier 1: Working Memory (Active Context)       │
│  └─ Telegram Agent buffer (20-30 последних)    │
└─────────────────────────────────────────────────┘
                      ↓
┌─────────────────────────────────────────────────┐
│  Tier 2: Short-Term Memory (3 месяца TTL)      │
│  └─ InsightShortTerm (context types)           │
│     • current_projects                          │
│     • recent_decisions                          │
│     • emotional_state                           │
│     • general_knowledge                         │
└─────────────────────────────────────────────────┘
                      ↓ (consolidation)
┌─────────────────────────────────────────────────┐
│  Tier 3: Long-Term Memory (Persistent)         │
│  └─ InsightProfile (categories)                │
│     • communication_style                       │
│     • goals_motivations                         │
│     • hard_skills                               │
│     • preferences                               │
│     • personality_traits                        │
└─────────────────────────────────────────────────┘
```

### 2. Event-Driven Pipeline

```
Transcript Ready (CalendarEvent)
    ↓
┌──────────────────────────────────────┐
│ ExtractInsightItemsListener          │
│ └─ InsightExtractionService          │
│    • Prompt construction             │
│    • LLM extraction (Claude)         │
│    • InsightItem creation            │
└──────────────────────────────────────┘
    ↓
┌──────────────────────────────────────┐
│ UpdateInsightProfilesListener        │
│ └─ InsightEvolutionService           │
│    • Group by category               │
│    • Merge with existing profiles    │
│    • Version history tracking        │
└──────────────────────────────────────┘
    ↓
┌──────────────────────────────────────┐
│ UpdateInsightRelationshipsListener   │
│ └─ InsightRelationshipService        │
│    • Extract participant dynamics    │
│    • Conflict/Support detection      │
└──────────────────────────────────────┘
```

### 3. Source Tracking

Все данные привязаны к `InsightSource`:
- **Провенанс** — откуда взята информация (meeting ID, дата, участники)
- **Confidence scoring** — готовность профиля зависит от количества источников (`source_count >= 3`)
- **Traceability** — возможность отследить происхождение любого инсайта

### 4. Type Safety & Structure

- **Enums** для типов контекста (`InsightContextType`), категорий (`InsightCategory`), отношений (`InsightRelationshipType`)
- **DTOs** для передачи данных между слоями
- **JSON Schema validation** в промптах для LLM
- **Database constraints** для целостности данных

---

## Компоненты системы

### Модели (Models)

#### 1. InsightSource
Источник информации (митинг, документ, чат).

**Поля:**
- `email` — владелец данных
- `source_type` — тип источника (meeting, document, chat)
- `source_id` — ID исходного объекта
- `metadata` — JSON с доп. информацией (участники, дата, название)

**Связи:**
- `hasMany(InsightItem)`
- `hasMany(InsightShortTerm)`

#### 2. InsightItem
Атомарный инсайт, извлеченный из источника.

**Поля:**
- `email` — кому относится
- `insight_source_id` — откуда извлечен
- `category` — категория (`InsightCategory`)
- `content` — JSON с инсайтом
- `confidence` — уверенность (0.0 - 1.0)

**Использование:**
- Raw data для консолидации в профили
- Хранение исторических фактов
- Основа для эволюции профилей

#### 3. InsightShortTerm
Краткосрочная память с TTL (3 месяца).

**Поля:**
- `email` — владелец
- `context_id` — идентификатор контекста (например, telegram_user_id)
- `context_type` — тип контекста (`InsightContextType`)
- `insight_source_id` — источник (nullable)
- `content` — JSON с данными
- `expires_at` — дата истечения

**Context Types:**
- `current_projects` — текущие проекты
- `recent_decisions` — недавние решения
- `emotional_state` — эмоциональное состояние
- `general_knowledge` — общие знания о пользователе

**Scopes:**
- `active()` — только не истекшие

#### 4. InsightProfile
Долгосрочный профиль пользователя (постоянный).

**Поля:**
- `email` — владелец
- `category` — категория (`InsightCategory`)
- `content` — JSON с профилем
- `source_count` — количество источников
- `last_updated_at` — дата последнего обновления

**Categories:**
- `communication_style` — стиль общения
- `goals_motivations` — цели и мотивации
- `hard_skills` — технические навыки
- `preferences` — предпочтения
- `personality_traits` — черты личности

**Methods:**
- `isReady()` — профиль готов, если `source_count >= 3`

#### 5. InsightProfileHistory
История изменений профиля (версионирование).

**Поля:**
- `insight_profile_id` — ID профиля
- `old_content` — предыдущая версия
- `new_content` — новая версия
- `changed_by` — источник изменения

#### 6. InsightRelationship
Динамика отношений между пользователями.

**Поля:**
- `email_a`, `email_b` — участники
- `relationship_type` — тип отношения
- `content` — JSON с динамикой
- `insight_source_id` — источник

**Relationship Types:**
- `collaborative` — сотрудничество
- `conflicting` — конфликт
- `supportive` — поддержка
- `mentoring` — менторство

---

### Сервисы (Services)

#### InsightService (Facade)
Главный входной точка для работы с системой.

**Методы:**
- `processTranscript(CalendarEvent)` — обработка транскрипта митинга
- `getContextForQuery(email, query)` — контекст для Tribes Bot
- `getFullProfile(email)` — полный профиль пользователя
- `getShortTermContext(email)` — краткосрочный контекст
- `getRelationship(emailA, emailB)` — отношения между людьми
- `rebuildProfile(email)` — пересборка профиля из items
- `forget(email)` — удаление всех данных (GDPR)

#### InsightExtractionService
Извлечение инсайтов из транскриптов через LLM.

**Pipeline:**
1. `InsightPromptBuilder` строит промпт с JSON schema
2. Вызов OpenRouter (Claude Sonnet 3.5)
3. Парсинг JSON ответа в `InsightExtractedDataDTO`
4. Создание `InsightSource` + `InsightItem[]`

**Prompts:**
- Structured JSON schema для категорий
- Examples для zero-shot learning
- Confidence scoring instructions

#### InsightEvolutionService
Консолидация InsightItem → InsightProfile.

**Логика:**
1. Группировка items по категориям
2. Merge новых данных с существующим профилем через LLM
3. Version history tracking
4. Source count increment

**Merge Strategy:**
- LLM получает старый профиль + новые items
- Инструкция: "Обновить, разрешить конфликты, сохранить структуру"
- Результат: обновленный профиль с консолидированными данными

#### InsightRetrievalService
Получение контекста для AI агентов.

**Методы:**
- `getContextForQuery(email, query)` — формирует контекст из ShortTerm + Profile
- `getFullProfile(email)` — все категории профиля
- `getShortTermContext(email)` — активные ShortTerm записи
- `getRelationship(emailA, emailB)` — динамика отношений

**Retrieval Strategy:**
- Metadata filtering (email, context_type, category)
- Recency bias (сортировка по `updated_at`)
- Active filtering (TTL для ShortTerm)

#### InsightRelationshipService
Управление отношениями между пользователями.

**Функции:**
- Извлечение participant dynamics из митингов
- Определение типа отношений (collaborative/conflicting/supportive/mentoring)
- Хранение истории взаимодействий

#### InsightMaintenanceService
Фоновые задачи обслуживания.

**Задачи:**
- Удаление истекших ShortTerm записей
- Пересборка профилей (scheduled jobs)
- Очистка orphaned sources
- Консолидация старых items

#### MemoryService (Agent Integration)
Интеграция Insight System с Telegram Agent.

**Методы:**
- `composeMemoryContext(TelegramUser)` — формирует контекст для LLM
  - Читает ShortTerm (все context types)
  - Читает Profile (COMMUNICATION_STYLE, GOALS_MOTIVATIONS)
  - Форматирует в markdown для промпта

**Формат контекста:**
```markdown
## Previous Context

### Current Projects
[текст из ShortTerm]

### Recent Decisions
[текст из ShortTerm]

### What you know about this user:

[communication_style]
{ JSON профиля }

[goals_motivations]
{ JSON профиля }
```

---

## Структура данных

### Database Schema

```sql
-- Источники информации
insight_sources
├─ id
├─ email (index)
├─ source_type (meeting/document/chat)
├─ source_id
├─ metadata (JSON)
└─ timestamps

-- Атомарные инсайты
insight_items
├─ id
├─ email (index)
├─ insight_source_id (FK)
├─ category (enum)
├─ content (JSON)
├─ confidence (decimal)
└─ timestamps

-- Краткосрочная память (TTL: 3 месяца)
insight_short_term
├─ id
├─ email (index)
├─ context_id (nullable, для привязки к контексту)
├─ context_type (enum: current_projects, recent_decisions, etc.)
├─ insight_source_id (FK, nullable)
├─ content (JSON)
├─ expires_at (datetime, index)
└─ timestamps

-- Долгосрочные профили
insight_profiles
├─ id
├─ email (index)
├─ category (enum: communication_style, goals_motivations, etc.)
├─ content (JSON)
├─ source_count (integer)
├─ last_updated_at
└─ timestamps
└─ UNIQUE(email, category)

-- История профилей (версионирование)
insight_profile_history
├─ id
├─ insight_profile_id (FK)
├─ old_content (JSON)
├─ new_content (JSON)
├─ changed_by
└─ created_at

-- Отношения между людьми
insight_relationships
├─ id
├─ email_a (index)
├─ email_b (index)
├─ relationship_type (enum)
├─ content (JSON)
├─ insight_source_id (FK)
└─ timestamps
└─ UNIQUE(email_a, email_b)
```

### JSON Schema Examples

#### InsightItem Content
```json
{
  "category": "hard_skills",
  "text": "Пользователь имеет опыт работы с Laravel и PostgreSQL",
  "evidence": "Упомянул разработку API на Laravel в митинге",
  "confidence": 0.9
}
```

#### InsightShortTerm Content
```json
{
  "text": "Работает над проектом HR системы, фокус на Insight модуле",
  "metadata": {
    "mentioned_at": "2026-02-12",
    "priority": "high"
  }
}
```

#### InsightProfile Content (communication_style)
```json
{
  "style": "technical",
  "tone": "direct",
  "preferences": {
    "format": "structured",
    "detail_level": "comprehensive",
    "emoji_usage": "minimal"
  },
  "patterns": [
    "Предпочитает markdown форматирование",
    "Ценит четкую структуру документов",
    "Запрашивает примеры кода"
  ]
}
```

---

## Pipeline обработки

### 1. Извлечение инсайтов из митинга

**Trigger:** `CalendarEvent` получает транскрипт

**Flow:**
```
CalendarEvent (transcript ready)
    ↓
Event: InsightItemsExtracted
    ↓
ExtractInsightItemsListener
    ↓
InsightExtractionService::extract()
    ├─ Строит промпт через InsightPromptBuilder
    ├─ Вызывает OpenRouter (Claude Sonnet 3.5)
    ├─ Парсит JSON ответ → InsightExtractedDataDTO
    └─ Создает InsightSource + InsightItem[]
    ↓
Events:
    ├─ InsightProfilesNeedUpdate
    └─ InsightRelationshipsNeedUpdate
```

**Промпт для LLM:**
```markdown
# Task
Extract key insights from the meeting transcript.

# Output Format
JSON with structure:
{
  "items": [
    {
      "category": "communication_style",
      "content": {...},
      "confidence": 0.9,
      "email": "user@example.com"
    }
  ],
  "participants": [...],
  "short_term": [...]
}

# Categories
- communication_style
- goals_motivations
- hard_skills
- preferences
- personality_traits

# Transcript
[транскрипт митинга]
```

### 2. Эволюция профилей

**Trigger:** `InsightProfilesNeedUpdate` event

**Flow:**
```
UpdateInsightProfilesListener
    ↓
InsightEvolutionService::evolveProfiles()
    ├─ Группировка InsightItem по category + email
    ├─ Для каждой группы:
    │   ├─ Загрузка существующего профиля
    │   ├─ Merge через LLM (старый + новые items)
    │   ├─ Сохранение истории (InsightProfileHistory)
    │   └─ Обновление InsightProfile
    └─ Increment source_count
```

**Промпт для merge:**
```markdown
# Task
Merge existing profile with new insights.

# Existing Profile
{ JSON старого профиля }

# New Insights
[ массив новых items ]

# Instructions
- Update profile with new information
- Resolve conflicts (prefer newer data if conflicting)
- Maintain structure
- Preserve valuable old data

# Output
{ обновленный профиль JSON }
```

### 3. Обновление отношений

**Trigger:** `InsightRelationshipsNeedUpdate` event

**Flow:**
```
UpdateInsightRelationshipsListener
    ↓
InsightRelationshipService::updateRelationships()
    ├─ Извлечение participant dynamics из транскрипта
    ├─ Определение relationship_type
    └─ Создание/обновление InsightRelationship
```

---

## Интеграция с Telegram Agent

### UpdateMemoryTool

**Описание:** Инструмент для агента, позволяющий сохранять информацию в память.

**Параметры:**
```json
{
  "memory_content": "Текст для сохранения",
  "context_type": "general_knowledge" // или current_projects, recent_decisions, emotional_state
}
```

**Реализация:**
```php
class UpdateMemoryTool implements ToolInterface
{
    public function execute(array $parameters): string
    {
        InsightShortTerm::create([
            'email' => $this->getEmailByTelegramId(),
            'context_id' => $this->telegramUserId,
            'context_type' => $parameters['context_type'],
            'content' => ['text' => $parameters['memory_content']],
            'expires_at' => now()->addMonths(3),
        ]);

        return "Memory saved successfully";
    }
}
```

### MemoryService::composeMemoryContext()

**Назначение:** Формирует контекст для LLM промпта агента.

**Алгоритм:**
1. Получить email через `TelegramUser->user->email`
2. Загрузить активные `InsightShortTerm` (все context types)
3. Загрузить профили `COMMUNICATION_STYLE`, `GOALS_MOTIVATIONS`
4. Сформировать markdown контекст:
   ```markdown
   ## Previous Context

   ### Current Projects
   [ShortTerm с context_type = current_projects]

   ### General Knowledge
   [ShortTerm с context_type = general_knowledge]

   ### What you know about this user:
   [communication_style]
   { JSON }
   ```

**Использование в AgentService:**
```php
public function processMessage(TelegramUser $telegramUser, string $message): string
{
    // 1. Загрузка памяти
    $memoryContext = $this->memoryService->composeMemoryContext($telegramUser);

    // 2. Формирование промпта
    $systemPrompt = "You are an AI assistant.\n\n" . $memoryContext;

    // 3. Отправка в LLM
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $message],
    ];

    return $this->openRouter->chat($messages, $tools);
}
```

---

## API и использование

### InsightService API

#### Обработка митинга
```php
$insightService->processTranscript($calendarEvent);
// → Извлекает инсайты, обновляет профили, создает relationships
```

#### Получение контекста для Tribes Bot
```php
$context = $insightService->getContextForQuery('user@example.com', 'Tell me about my goals');
// → Возвращает релевантный контекст из ShortTerm + Profile
```

#### Полный профиль пользователя
```php
$profile = $insightService->getFullProfile('user@example.com');
// → Массив всех категорий профиля
```

#### Краткосрочный контекст
```php
$shortTerm = $insightService->getShortTermContext('user@example.com');
// → Активные ShortTerm записи
```

#### Отношения между людьми
```php
$relationship = $insightService->getRelationship('alice@example.com', 'bob@example.com');
// → Динамика отношений (collaborative/conflicting/etc.)
```

#### GDPR Forget
```php
$insightService->forget('user@example.com');
// → Удаляет ВСЕ данные пользователя из системы
```

### InsightController (REST API)

**Endpoints:**

```http
GET /api/v1/insights/profile
Authorization: Bearer {token}
→ Возвращает полный профиль текущего пользователя

GET /api/v1/insights/short-term
Authorization: Bearer {token}
→ Возвращает краткосрочный контекст

GET /api/v1/insights/relationship?email={email}
Authorization: Bearer {token}
→ Возвращает отношения с указанным пользователем
```

---

## Технический стек

### Backend
- **Laravel 12** — PHP framework
- **PostgreSQL** — основное хранилище (production)
- **SQLite** — in-memory для тестов
- **Laravel Events** — асинхронная обработка

### AI Integration
- **OpenRouter** — LLM API gateway
- **Claude Sonnet 3.5** — основная модель для extraction/merge
- **JSON Schema** — structured output от LLM

### Data Layer
- **Eloquent ORM** — работа с моделями
- **Migrations** — версионирование схемы БД
- **Type Safety** — Enums для категорий, контекстов, типов

### Architecture Patterns
- **Event-Driven** — обработка через Listeners
- **Service Layer** — бизнес-логика в Services
- **DTO** — передача данных между слоями
- **Repository** — Eloquent models как repositories

---

## Roadmap

### ✅ Phase 1: MVP (Завершено — 12.02.2026)
- [x] Базовые модели (InsightSource, InsightItem, InsightShortTerm, InsightProfile)
- [x] Извлечение инсайтов из митингов
- [x] Эволюция профилей через LLM merge
- [x] Интеграция с Telegram Agent (MemoryService, UpdateMemoryTool)
- [x] TTL для краткосрочной памяти (3 месяца)
- [x] Context types (current_projects, recent_decisions, emotional_state, general_knowledge)
- [x] Version history (InsightProfileHistory)

### 🔄 Phase 2: Advanced Retrieval (В планах)
- [ ] Vector search (ChromaDB) для semantic retrieval
- [ ] Hybrid search: 70% vector + 30% keyword (SQLite FTS5)
- [ ] Recency bias weighting
- [ ] Query-based context filtering

### 📋 Phase 3: Consolidation & Automation (В планах)
- [ ] Недельная консолидация ShortTerm → Profile
- [ ] Автоматическое извлечение инсайтов из чатов
- [ ] Conflict resolution mechanism
- [ ] Forgetting mechanism (автоудаление устаревших данных)

### 🎯 Phase 4: Personalization & Analytics (В планах)
- [ ] BigFive personality профили
- [ ] Адаптация стиля коммуникации агента
- [ ] Аналитика команды (team insights)
- [ ] Relationship network visualization
- [ ] Confidence-based filtering (порог уверенности)

### 🔐 Phase 5: Security & Compliance (В планах)
- [ ] Encryption at rest для sensitive data
- [ ] Granular access control (RBAC)
- [ ] Audit logs для всех изменений
- [ ] GDPR compliance tools (export, forget)
- [ ] Data retention policies

---

## Выводы

**Insight System** реализует production-ready архитектуру памяти для AI агентов, основанную на:
- **Научных подходах** (MemGPT, Mem0, OpenClaw)
- **Event-driven architecture** для масштабируемости
- **Type safety** через Enums и DTOs
- **Hybrid memory tiers** (short-term + long-term)
- **Source tracking** для provenance и confidence

Система **уже интегрирована** с Telegram Agent и успешно используется для персонализации общения, сохраняя контекст проектов, решений и характеристик пользователя.

**Следующий шаг:** Внедрение vector search для semantic retrieval и автоматическая консолидация недельных данных.

---

**Документ подготовлен:** 12.02.2026
**Автор:** Development Team
**Статус:** Production
**Версия системы:** v1.0