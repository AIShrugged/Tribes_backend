# Agenda Constructor — целевая архитектура

**Статус:** черновик архитектурного решения, локальный.
**Дата:** 2026-05-08.
**Связан с:** [transcript-pipeline-refactor.md](transcript-pipeline-refactor.md) — общий рефакторинг пост-транскриптного пайплайна.
**Скоп:** только модуль агенды (`AgendaService`, `MeetingAgenda`, `UpcomingAgenda`, всё что генерирует/рендерит повестку встречи). Готовится почва для будущего «конструктора агенды».

---

## Оглавление

1. [Цель документа](#1-цель-документа)
2. [Что сейчас и почему это блокирует новые фичи](#2-что-сейчас-и-почему-это-блокирует-новые-фичи)
3. [Предположения о конструкторе (нужна валидация)](#3-предположения-о-конструкторе-нужна-валидация)
4. [Целевая архитектура](#4-целевая-архитектура)
5. [Распил AgendaService — карта переноса](#5-распил-agendaservice--карта-переноса)
6. [Prompt-слой для агенды](#6-prompt-слой-для-агенды)
7. [Migration plan — как перейти не сломав](#7-migration-plan--как-перейти-не-сломав)
8. [Как добавляются новые фичи (примеры)](#8-как-добавляются-новые-фичи-примеры)
9. [Feature flags и schema versioning](#9-feature-flags-и-schema-versioning)
10. [Связь с основным планом](#10-связь-с-основным-планом)
11. [Открытые вопросы](#11-открытые-вопросы)

---

## 1. Цель документа

Сделать модуль агенды таким, чтобы:
- **Добавление новой фичи** (например, нового правила извлечения тем, нового типа агенды, новой секции, нового рендерера) **не требовало правки существующих файлов**, а делалось **через добавление нового класса и регистрацию в реестре**.
- **Существующее поведение не ломалось** при таких добавлениях — гарантируется регрессионными тестами + изоляцией через интерфейсы.
- **Новая фича могла быть включена/выключена** через feature flag без удаления старого кода.

Это документ **архитектуры**, не план реализации. План — в основном [refactor-doc разделе 7](transcript-pipeline-refactor.md#7-план-фаз) (Стадия 3 «подготовка под конструктор агенды»).

---

## 2. Что сейчас и почему это блокирует новые фичи

### 2.1 Текущий код агенды

Два независимых сервиса:

| Сервис | Файл | Размер | Назначение |
|---|---|---:|---|
| `AgendaService` | [`app/Services/Agenda/AgendaService.php`](../app/Services/Agenda/AgendaService.php) | 1255 строк | Большая повестка ПЕРЕД встречей (manual + cron) |
| `UpcomingAgendaService` | [`app/Services/Agenda/UpcomingAgendaService.php`](../app/Services/Agenda/UpcomingAgendaService.php) | 128 строк | Personal follow-up prep после встречи (auto) |

### 2.2 Что внутри AgendaService — 18 методов в одном файле

| # | Метод | Строки | Что делает |
|---|---|---|---|
| 1 | `generateForEvent` | 28-115 | Главный метод: собирает контекст, дёргает остальное |
| 2 | `generateGeneralAgenda` | 117-271 | LLM-вызов общей повестки + persist |
| 3 | `generatePersonalAgenda` | 273-350 | LLM-вызов персональной + persist |
| 4 | `collectStructuredData` | 352-435 | Сборка `raw_json` (events, issues, commitments) |
| 5 | `buildGeneralPrompt` | 437-527 | Большой inline промпт |
| 6 | `parseCommitment` | 529-563 | Парсинг строки commitment в `{who, what, deadline}` |
| 7 | `extractCommitmentsFromSummary` | 565-614 | Парсинг markdown-таблицы commitments |
| 8 | `matchCommitmentStatus` | 616-664 | Сматчивание commitment ↔ issue |
| 9 | `getNameVariants` | 666-700 | **Хардкод-словарь имён сотрудников Vanda** |
| 10 | `getTasksBetweenMeetings` | 702-726 | Issues, созданные между двумя митингами |
| 11 | `getBacklogStats` | 728-763 | Статистика бэклога команды |
| 12 | `extractTopicsFromSummary` | 765-798 | Парсинг тем из summary |
| 13 | `buildPersonalPrompt` | 800-919 | Inline промпт для персональной |
| 14 | `collectFollowUps` | 921-936 | Объединение upcoming agendas |
| 15 | `normalizeDateTime` | 938-945 | Carbon-нормализация |
| 16 | `renderGeneralContent` | 947-1183 | **236 строк** Markdown-рендеринга |
| 17 | `detectStuckTasks` | 1185-1206 | Issues, висящие давно |
| 18 | `renderPersonalContent` | 1208-1255 | Markdown-рендеринг персональный |

Плюс две static-функции, которые лежат в этом же файле, но используются снаружи:
- `renderForWeb($data): string` (called from `SendAgendaNotificationsJob`)
- `renderForTelegram($data): string` (то же)

### 2.3 Где это блокирует новые фичи

#### Блокер №1 — нельзя добавить новое правило, не правя 1255-строчный файл

Любая новая фича («добавить секцию X», «новое правило приоритезации тем», «другой способ матчинга commitment») требует правки `AgendaService.php`. PR на 30 строк живёт в 1255-строчном файле, ревью невозможно, регрессии в смежных методах ловятся только в проде.

#### Блокер №2 — промпт неотделим от LLM-вызова

`buildGeneralPrompt` и `buildPersonalPrompt` — `private` методы. Чтобы добавить новый вариант промпта (например, «промпт под daily-standup vs промпт под weekly-sync»), нужно либо:
- Добавить ветвление if/else в `buildGeneralPrompt` — рост if-pyramid.
- Скопировать весь метод и переименовать — копипаста.

Тестировать промпты отдельно от LLM **невозможно** без рефлексии или хака.

#### Блокер №3 — `renderGeneralContent` 236 строк

Это монолитная Markdown-функция, которая знает про все секции агенды одновременно. Любое изменение порядка секций или добавление новой ломает шаблон, по которому фронт читает результат.

Плюс: `renderGeneralContent` рендерит **усечённую версию** (то, что в `MeetingAgenda.content`), а `renderForWeb` — полную (включая `commitments_check`, `tasks_between`, `backlog_stats`). Уже сегодня в БД лежат разные версии одной агенды, и тесты на «как выглядит» зависят от того, какой рендерер взят. См. [main plan M-9](transcript-pipeline-refactor.md#m-9-meetingagendacontent-vs-rawjson--рендереры--несимметричны).

#### Блокер №4 — `getNameVariants` хардкод сотрудников

[`AgendaService.php:666-700`](../app/Services/Agenda/AgendaService.php) содержит хардкод-словарь:
```
Борис → boris
Иван → ivan
Виктор → витя
Фёдор → жерновой
Константин → konstantin
…
```

Это **встроенный словарь конкретных сотрудников Vanda**. Не масштабируется на нового клиента. Любое расширение требует правки кода.

#### Блокер №5 — нет промежуточной модели данных

Между «собрали контекст» и «вызвали LLM» нет валидированной структуры. Если завтра добавить «учитывать настроение участников из последнего ревью» — придётся снова править `collectStructuredData`. Нет места, куда можно положить новое поле без поломки старого читателя.

---

## 3. Предположения о конструкторе (нужна валидация)

> **Эти предположения — мои догадки, не утверждения.** Если они неверны — архитектура должна быть другой. Прошу проверить и уточнить.

### Предположение A: набор секций варьируется

Пользователь (admin команды или организации) может выбирать, какие секции включать в общую агенду:
- ✅ Темы для обсуждения
- ✅ Проверка commitments из прошлого митинга
- ☑ Статистика бэклога (опционально)
- ☑ Stuck tasks (опционально)
- ☐ NEW: Метрики из performance-отчёта (будущая фича)
- ☐ NEW: OKR-прогресс команды (будущая фича)

### Предположение B: правила извлечения варьируются

Например, для разных типов встреч (daily standup, weekly sync, retrospective) **разные промпты** и **разные правила сборки контекста**:
- Daily standup → темы из последних 24 часов, без backlog stats.
- Weekly sync → темы за неделю, с backlog, с stuck tasks.
- Retrospective → акцент на завершённое, не на open issues.

### Предположение C: шаблон агенды — конфигурируемый

В будущем будет таблица типа `agenda_templates(team_id, name, sections[], rules[], prompt_version)`. Конструктор агенды на фронте позволяет admin'у собрать свой шаблон.

### Предположение D: текущее поведение становится «дефолтным шаблоном»

Не удаляем существующий код, а оборачиваем как один из вариантов: `DefaultGeneralAgendaTemplate`. Команды, у которых нет своего шаблона, продолжают получать ту же повестку, что и раньше.

### Предположение E: `UpcomingAgenda` не входит в конструктор

Personal follow-up — отдельный модуль, не настраивается шаблонами в этой итерации.

**Если какое-то из A-E неверно — архитектура ниже требует пересмотра.** Особенно A и B.

---

## 4. Целевая архитектура

### 4.1 Высокоуровневое разделение

```
┌──────────────────────────────────────────────────────────────────┐
│                     AgendaService (orchestrator)                 │
│ — public API: generateForEvent(CalendarEvent, ?Template): void   │
│ — координирует вызов остальных слоёв                             │
└────────┬──────────┬──────────┬──────────┬──────────┬─────────────┘
         │          │          │          │          │
         ▼          ▼          ▼          ▼          ▼
   ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌──────────┐
   │ Context │ │ Prompt  │ │ LLM     │ │ Persist │ │ Renderer │
   │ Builder │ │ Builder │ │ Caller  │ │  -er    │ │   x3     │
   └─────────┘ └─────────┘ └─────────┘ └─────────┘ └──────────┘
        │           │
        ▼           ▼
  ┌──────────────────────┐
  │ Sections (plug-in)   │  — каждая секция = отдельный класс
  │ ┌──────────────────┐ │
  │ │TopicsSection     │ │
  │ │CommitmentsSection│ │
  │ │BacklogSection    │ │
  │ │StuckTasksSection │ │
  │ │OKRSection (NEW)  │ │
  │ │... (extensible)  │ │
  │ └──────────────────┘ │
  └──────────────────────┘
```

### 4.2 Слои и их обязанности

| Слой | Класс | Обязанность | Тестируемость |
|---|---|---|---|
| **Orchestrator** | `AgendaService` | Принимает `(CalendarEvent, ?AgendaTemplate)` → координирует слои → возвращает `MeetingAgenda` | Integration test |
| **Context** | `AgendaContextBuilder` | Собирает данные (events, issues, series state) в `AgendaContext` DTO | Unit, mock БД |
| **Sections** | `AgendaSection` interface + N implementations | Каждая секция = плагин: умеет сказать `shouldInclude()` и `collectData()` | Unit, без БД |
| **Prompt** | `AgendaPromptBuilder` + `AgendaPrompt` (interface) | Из `AgendaContext + Template` строит финальный промпт | Unit, assertString |
| **LLM** | `LlmGateway` (общий из main plan ADR-3) | Вызывает OpenRouter, возвращает JSON | Mock |
| **Persist** | `AgendaPersister` | Сохраняет JSON в `MeetingAgenda.raw_json` + `content` | Unit, in-memory БД |
| **Renderer** | `AgendaRenderer` interface + 3 implementations (Web/Telegram/Plain) | Из `raw_json` строит markdown/HTML | Unit, snapshot tests |
| **Template** | `AgendaTemplate` (model + service) | Описывает «какие секции, в каком порядке, с какими правилами» | Unit + factory |

### 4.3 Ключевые контракты (псевдокод, не реализация)

```php
// Контекст — то, что собрано до промпта.
final class AgendaContext {
    public function __construct(
        public readonly CalendarEvent $event,
        public readonly ?CalendarEvent $previousEvent,
        public readonly ?MeetingSummary $previousSummary,
        public readonly Collection $previousEvents,
        public readonly Collection $issues,
        public readonly ?Organization $organization,
        public readonly ?MeetingSeriesState $seriesState,
        public readonly Collection $upcomingAgendas,
        public readonly ?MeetingAgenda $previousAgenda,
        public readonly Collection $attendees,
    ) {}
}

// Каждая секция знает свою логику.
interface AgendaSection {
    public function name(): string;                            // 'topics', 'commitments', 'backlog'
    public function shouldInclude(AgendaContext $ctx, AgendaTemplate $tpl): bool;
    public function collectData(AgendaContext $ctx): array;    // в raw_json
    public function promptInstructions(AgendaTemplate $tpl): string; // секция промпта
    public function renderMarkdown(array $data): string;       // для рендерера
}

// Промпт собирается из секций + шаблона.
final class AgendaPromptBuilder {
    public function build(AgendaContext $ctx, AgendaTemplate $tpl, Collection $sections): string;
}

// Шаблон — конфигурация: какие секции, какой промпт-формат, какие правила.
final class AgendaTemplate {
    public readonly string $name;                  // 'default', 'daily_standup', 'weekly_sync'
    public readonly array $enabledSections;        // ['topics', 'commitments', 'backlog']
    public readonly string $promptVersion;         // 'v1' | 'v2'
    public readonly ?int $teamId;                  // null = global default
    public readonly array $rules;                  // serialized config
}

// Рендерер.
interface AgendaRenderer {
    public function render(MeetingAgenda $agenda): string;
}
final class WebAgendaRenderer implements AgendaRenderer { ... }
final class TelegramAgendaRenderer implements AgendaRenderer { ... }
final class PlainAgendaRenderer implements AgendaRenderer { ... }
```

### 4.4 Поток (target)

```
AgendaService::generateForEvent($event, $template = null)
   ↓
   1. $template ??= TemplateRegistry::resolve($event)        # default или team-specific
   2. $context = AgendaContextBuilder::build($event)
   3. $sections = SectionRegistry::for($template)            # фильтр enabled
   4. $rawData = []; foreach ($sections as $s) {
          if ($s->shouldInclude($context, $template))
              $rawData[$s->name()] = $s->collectData($context);
      }
   5. $prompt = AgendaPromptBuilder::build($context, $template, $sections)
   6. $llmResult = LlmGateway::chatJson($prompt, ...)
   7. $merged = array_merge($rawData, json_decode($llmResult))
   8. $agenda = AgendaPersister::save($event, $template, $merged)
   9. return $agenda
```

Рендеринг — **отдельный шаг**, по запросу:
```
WebAgendaRenderer::render($agenda)        → для UI
TelegramAgendaRenderer::render($agenda)   → для нотификации
```
То, что сейчас лежит в `MeetingAgenda.content` — рассматриваем как **кеш одного из рендеров** (вероятно plain), который сбрасывается при смене схемы. См. 9.2.

---

## 5. Распил AgendaService — карта переноса

Идея: **существующее поведение = `DefaultAgendaTemplate` с дефолтным набором секций.** Никаких изменений для пользователя в момент рефакторинга.

### 5.1 Куда уезжает каждый метод

| Текущий метод (`AgendaService.php`) | Куда переносится |
|---|---|
| `generateForEvent` | Остаётся в `AgendaService`, но становится тонким (10-20 строк) |
| `generateGeneralAgenda` | Превращается в orchestration через `AgendaPromptBuilder` + `LlmGateway` + `AgendaPersister` |
| `generatePersonalAgenda` | То же, отдельная цепочка с `PersonalAgendaTemplate` |
| `collectStructuredData` | `AgendaContextBuilder` + распределение по секциям (см. 5.2) |
| `buildGeneralPrompt` | `AgendaPromptBuilder` + `DefaultGeneralAgendaPrompt` (класс-промпт) |
| `parseCommitment` | `Domain\Agenda\Parsers\CommitmentParser` (pure function) |
| `extractCommitmentsFromSummary` | `Domain\Agenda\Parsers\CommitmentParser::extractFromSummary` |
| `matchCommitmentStatus` | `Domain\Agenda\CommitmentStatusMatcher` (использует `NameMatcher`) |
| `getNameVariants` | `Domain\Names\NameMatcher` (с конфигурируемым словарём — см. 5.4) |
| `getTasksBetweenMeetings` | `Issue::scopeBetweenMeetings()` или `IssueQueryService` |
| `getBacklogStats` | **Использовать существующий** `app/Services/IssueStatsService.php` (M-11 в main plan) |
| `extractTopicsFromSummary` | `Domain\MeetingSummary\TopicsExtractor` |
| `buildPersonalPrompt` | `DefaultPersonalAgendaPrompt` (класс) |
| `collectFollowUps` | `Sections\FollowUpsSection::collectData` |
| `normalizeDateTime` | `Domain\DateTime\Normalizer` (статичная утилита) |
| `renderGeneralContent` | `Renderers\PlainGeneralAgendaRenderer` |
| `renderForWeb` | `Renderers\WebGeneralAgendaRenderer` |
| `renderForTelegram` | `Renderers\TelegramGeneralAgendaRenderer` |
| `detectStuckTasks` | `Sections\StuckTasksSection::collectData` |
| `renderPersonalContent` | `Renderers\PlainPersonalAgendaRenderer` |

### 5.2 Секции (default set)

Под `Predположение A`. Каждая секция — отдельный класс в `app/Domain/Agenda/Sections/`:

| Секция | Что включает | Из чего сейчас в коде |
|---|---|---|
| `TopicsSection` | Темы для обсуждения (из summary, из stuck tasks) | `extractTopicsFromSummary` + LLM-генерация в промпте |
| `CommitmentsSection` | Проверка commitments из прошлого митинга | `extractCommitmentsFromSummary` + `matchCommitmentStatus` + `parseCommitment` |
| `BacklogSection` | Статистика бэклога | `getBacklogStats` (используя `IssueStatsService`) |
| `StuckTasksSection` | Задачи, висящие давно | `detectStuckTasks` |
| `TasksBetweenMeetingsSection` | Issues, созданные между двумя митингами | `getTasksBetweenMeetings` |
| `OpenQuestionsSection` | Открытые вопросы (генерация LLM) | сейчас часть промпта |
| `FollowUpsSection` | Из `UpcomingAgenda` других участников | `collectFollowUps` |

Новые секции (плагины) — добавляются как отдельные классы без правки existing.

### 5.3 Шаблоны (default set)

Под `Предположение B + D`:

```php
// app/Domain/Agenda/Templates/
DefaultGeneralAgendaTemplate {
    enabledSections = ['topics', 'commitments', 'backlog', 'stuck_tasks', 
                       'tasks_between', 'open_questions']
    promptVersion = 'v1'
    rules = []  // дефолт
}

DefaultPersonalAgendaTemplate {
    enabledSections = ['previous_meeting_recap', 'assigned_tasks', 
                       'due_by_this_meeting', 'discussion_points']
    promptVersion = 'v1'
}

// Будущие:
DailyStandupTemplate { enabledSections = ['topics', 'stuck_tasks'], rules = ['recent_24h_only'] }
WeeklySyncTemplate { ... }
RetrospectiveTemplate { ... }
```

Хранятся в `app/Domain/Agenda/Templates/*.php` сначала как PHP-классы. **Когда появится UI-конструктор** — мигрируем в БД-таблицу `agenda_templates`. См. 5.5.

### 5.4 NameMatcher — без хардкода

Замена хардкоду из `getNameVariants`:

```php
// Структура: имя_канон → массив диминутивов
// app/Domain/Names/NameVariantsRegistry.php
class NameVariantsRegistry {
    public function variantsFor(string $name): array { ... }
    
    private function loadDictionary(): array {
        // 1. Системный словарь (публичный, ru/en диминутивы) — JSON-файл в storage
        // 2. + Org-специфичный словарь — таблица organization_name_aliases (опционально)
        // 3. + Hardcoded fallback — если БД недоступна
    }
}
```

`MeetingTaskService::participantMatcher` и `IssueMergeService::nameMatches` тоже переходят на этот резолвер. Но это **отдельная задача**, выходящая за scope конструктора.

### 5.5 Когда появляется UI-конструктор

В первой итерации шаблоны — PHP-классы (`DefaultGeneralAgendaTemplate`, `DailyStandupTemplate`). Чтобы admin создавал свои — нужна:

```sql
CREATE TABLE agenda_templates (
    id SERIAL PRIMARY KEY,
    organization_id BIGINT NULL,
    team_id BIGINT NULL,
    name VARCHAR(255),
    type VARCHAR(64),                  -- 'general' | 'personal'
    enabled_sections JSONB,            -- ["topics", "commitments"]
    prompt_version VARCHAR(32),
    rules JSONB,                       -- произвольный config секций
    schema_version SMALLINT DEFAULT 1, -- для будущих миграций
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

`AgendaTemplate` сначала PHP-класс, потом — Eloquent-модель с тем же интерфейсом. Это **отложенная миграция**, не делать сейчас.

---

## 6. Prompt-слой для агенды

### 6.1 Контракт промпта

```php
namespace App\Domain\Agenda\Prompts;

interface AgendaPrompt {
    public function model(): string;            // 'agenda'
    public function maxTokens(): int;           // 8192
    public function build(AgendaContext $ctx, AgendaTemplate $tpl, Collection $sections): RenderedPrompt;
}

final class RenderedPrompt {
    public function __construct(
        public readonly string $body,
        public readonly array $messages = [],   // for system+user pattern, опционально
    ) {}
}
```

### 6.2 Реализации (default set)

```
app/Domain/Agenda/Prompts/
├── DefaultGeneralAgendaPromptV1.php   ← существующий buildGeneralPrompt
├── DefaultPersonalAgendaPromptV1.php  ← существующий buildPersonalPrompt
├── (будущие)
│   ├── DailyStandupAgendaPromptV1.php
│   ├── WeeklySyncAgendaPromptV1.php
│   └── DefaultGeneralAgendaPromptV2.php  ← следующая версия дефолта
```

### 6.3 Версионирование промптов

`promptVersion` в `AgendaTemplate` указывает класс через factory:

```php
class AgendaPromptFactory {
    public function for(string $type, string $version): AgendaPrompt {
        return match ([$type, $version]) {
            ['general', 'v1'] => new DefaultGeneralAgendaPromptV1(),
            ['general', 'v2'] => new DefaultGeneralAgendaPromptV2(),
            ['personal', 'v1'] => new DefaultPersonalAgendaPromptV1(),
            ['daily_standup', 'v1'] => new DailyStandupAgendaPromptV1(),
            // ...
        };
    }
}
```

Несколько версий могут существовать одновременно. Команды на разных шаблонах могут получать разные промпты.

### 6.4 Тестируемость промптов

Главное — каждый промпт **тестируется отдельно от LLM**:

```php
// tests/Unit/Domain/Agenda/Prompts/DefaultGeneralAgendaPromptV1Test.php

it_includes_meeting_date_in_prompt() {
    $ctx = AgendaContextFactory::make(['event' => CalendarEvent::factory()->create([
        'starts_at' => '2026-04-15 10:00',
    ])]);
    $rendered = (new DefaultGeneralAgendaPromptV1())->build($ctx, $template, $sections);
    
    $this->assertStringContainsString('15.04.2026', $rendered->body);
}

it_lists_enabled_sections_only_in_instructions() {
    $template = new AgendaTemplate(enabledSections: ['topics', 'backlog']);
    $rendered = (new DefaultGeneralAgendaPromptV1())->build($ctx, $template, $sections);
    
    $this->assertStringContainsString('Темы для обсуждения', $rendered->body);
    $this->assertStringContainsString('Бэклог', $rendered->body);
    $this->assertStringNotContainsString('Stuck tasks', $rendered->body);
}

it_passes_previous_commitments_when_section_enabled() { ... }
```

Это **главное преимущество распила**: каждое правило промпта — отдельный тест-кейс на свой класс. Вместо одного «test_buildGeneralPrompt» на 50 ассертов.

### 6.5 Промпты как часть саге agenda template

Если шаблон в БД — `prompt_version` хранится в нём. `AgendaPromptFactory` резолвит. Если в БД нет такой версии — fallback на `v1` + log warning.

---

## 7. Migration plan — как перейти не сломав

### 7.1 Принципы

1. **Регрессионные тесты ПЕРВЫМИ** — до начала распила фиксируем существующее поведение в тестах. Снапшот текущей агенды для 3-5 разных meeting фикстур.
2. **Strangler pattern** — новая архитектура поднимается параллельно со старой. Старый код остаётся на месте.
3. **Feature flag** — `agenda.use_new_pipeline` (default = false). Сначала включаем для одной тестовой команды, потом раскатываем.
4. **Snapshot tests на рендереры** — после миграции web/telegram/plain рендеры **должны выдавать байт-в-байт ту же строку** для одних и тех же `raw_json`. Иначе фронт сломан.
5. **Backwards compat для `MeetingAgenda.raw_json`** — добавляем `schema_version`, читатель умеет читать v1.

### 7.2 Пошаговый план (без кода, описание шагов)

#### Шаг 1: Регрессионная сетка (1 неделя)

- Снапшот-тесты текущего `AgendaService::generateForEvent` для 5 фикстур (разные сценарии: с history, без history, с participants, с команды-organization, с upcoming agendas).
- Каждый тест мокает LLM фиксированным ответом, гоняет генерацию, сравнивает `MeetingAgenda.raw_json` и `content` с эталоном.
- Тесты дают **зелёный baseline**. Любая правка в дальнейшем не должна их ломать.

#### Шаг 2: Распил pure-functions (1 неделя)

Без изменения поведения, чисто перенос:
- `parseCommitment` → `Domain\Agenda\CommitmentParser`
- `extractCommitmentsFromSummary` → то же
- `matchCommitmentStatus` → `Domain\Agenda\CommitmentStatusMatcher`
- `getNameVariants` → `Domain\Names\NameMatcher`
- `extractTopicsFromSummary` → `Domain\MeetingSummary\TopicsExtractor`
- `parseCommitment`, `normalizeDateTime` — utils
- `detectStuckTasks` → `StuckTasksDetector`

`AgendaService` начинает делегировать в эти классы. Тесты Шага 1 продолжают быть зелёными.

**Это самая безопасная часть распила.** Pure functions, легко тестировать, нет состояний.

#### Шаг 3: Контекст-DTO + ContextBuilder (3-5 дней)

- Создаём `AgendaContext` DTO с полями (event, issues, previousEvents, ...).
- `AgendaContextBuilder::build($event)` инкапсулирует то, что сейчас делает первая часть `generateForEvent` (строки 28-115).
- `AgendaService::generateForEvent` теперь начинается с `$ctx = $this->contextBuilder->build($event)`.

Снапшот-тесты по-прежнему зелёные.

#### Шаг 4: Sections (1 неделя)

- Создаём интерфейс `AgendaSection`.
- Каждую существующую секцию (topics, commitments, backlog, stuck_tasks, tasks_between) оборачиваем как класс.
- `SectionRegistry` собирает их.
- `collectStructuredData` теперь = `foreach ($sections as $s) { $raw[$s->name()] = $s->collectData($ctx); }`.

Снапшот-тесты прежнюю агенду рендерят так же.

#### Шаг 5: Promt-слой (3-5 дней)

- `DefaultGeneralAgendaPromptV1` = существующий `buildGeneralPrompt`.
- `DefaultPersonalAgendaPromptV1` = существующий `buildPersonalPrompt`.
- `AgendaPromptBuilder::build($ctx, $template, $sections)` делегирует в правильную реализацию через `AgendaPromptFactory`.
- `AgendaService` теперь не строит промпт сам, а вызывает builder.

Снапшот-тесты зелёные (тот же промпт → тот же ответ → тот же `raw_json`).

#### Шаг 6: Renderers (3-5 дней)

- `WebAgendaRenderer`, `TelegramAgendaRenderer`, `PlainAgendaRenderer` — три класса.
- `MeetingAgenda::content` пишется через `PlainAgendaRenderer` — байт-в-байт то же, что сейчас выдаёт `renderGeneralContent`.
- `SendAgendaNotificationsJob` использует `TelegramAgendaRenderer` (вместо `AgendaService::renderForTelegram`).
- Старые static-методы помечаются `@deprecated`, но не удаляются — пока feature flag выключен.

#### Шаг 7: Templates (3-5 дней)

- `DefaultGeneralAgendaTemplate`, `DefaultPersonalAgendaTemplate` — PHP-классы.
- `TemplateRegistry::resolve($event)` пока всегда возвращает default.
- `AgendaService::generateForEvent($event, ?Template = null)` — параметр шаблона.

Снапшот-тесты передают `null` (default). Поведение идентично.

#### Шаг 8: Feature flag и параллельный запуск (1 неделя)

- Флаг `config('agenda.use_new_pipeline', false)`.
- В `AgendaService::generateForEvent`: `if ($flag) new pipeline; else old code`.
- На 1-2 тестовых команд (не demo!) включаем флаг.
- Сравниваем `raw_json` вживую: новый pipeline должен выдавать тот же результат для тех же транскриптов.
- Через 1-2 недели — раскатываем на все команды.

#### Шаг 9: Удаление старого кода (1 день)

После недели-двух 100%-раскатки:
- Удаляем старые методы из `AgendaService`.
- `AgendaService` — тонкий orchestrator (50-100 строк).
- Снимаем флаг.

### 7.3 Что точно может сломаться (риски)

| Риск | Митигация |
|---|---|
| Новый promtBuilder выдаёт чуть другой текст | Снапшот-тест на байт-в-байт. Если LLM-ответ отличается — это ОК, но значит, тест надо обновлять осознанно |
| Markdown в `content` отличается на пробел | Snapshot test с trim/normalize OR явная проверка что-фронт-всё-ещё-парсит |
| Telegram нотификация съезжает | Smoke-тест отправляет в test-канал и сравнивает |
| Чтение старых записей `MeetingAgenda.raw_json` v1 после изменения формата | `schema_version` + `RawJsonReader::read($agenda)` который умеет старую структуру |
| Поведение для команды без `meta` (старая запись) | `TemplateRegistry::resolve` возвращает `DefaultTemplate` если не нашёл |

### 7.4 Сколько времени всего

| Шаг | Срок |
|---|---|
| 1. Регрессионная сетка | 1 нед |
| 2. Pure-functions | 1 нед |
| 3. Context DTO | 3-5 дней |
| 4. Sections | 1 нед |
| 5. Prompt-слой | 3-5 дней |
| 6. Renderers | 3-5 дней |
| 7. Templates | 3-5 дней |
| 8. Параллельный запуск | 1 нед |
| 9. Удаление старого | 1 день |

**Итого: 5-7 недель.** Можно частично параллелить (Steps 2 и 4 — независимые), реалистичный срок — 5 недель силами одного-двух разработчиков.

---

## 8. Как добавляются новые фичи (примеры)

После завершения миграции добавление фич выглядит так:

### Пример A: новая секция «OKR-прогресс»

1. Создать `app/Domain/Agenda/Sections/OkrProgressSection.php`:
   ```php
   class OkrProgressSection implements AgendaSection {
       public function name(): string { return 'okr_progress'; }
       public function shouldInclude($ctx, $tpl): bool {
           return in_array($this->name(), $tpl->enabledSections);
       }
       public function collectData($ctx): array { ... }
       public function promptInstructions($tpl): string { ... }
       public function renderMarkdown($data): string { ... }
   }
   ```
2. Зарегистрировать в `SectionRegistry`.
3. Добавить юнит-тест на секцию (4-5 кейсов).
4. Включить в `WeeklySyncTemplate.enabledSections` или новый шаблон.
5. Регрессионные тесты остальных шаблонов **не задеваются** — у них этой секции нет.

**Изменений в существующих файлах:** только в `SectionRegistry` (добавление одной строки регистрации).

### Пример B: новый тип агенды «Daily standup»

1. Создать `DailyStandupTemplate` PHP-класс с `enabledSections = ['topics', 'stuck_tasks']`.
2. Создать `DailyStandupAgendaPromptV1` (уже короче и фокуснее).
3. `TemplateRegistry::resolve` определяет тип события и возвращает `DailyStandup` для standup-meeting'ов.
4. Юнит-тест на промпт + интеграционный на полный flow.

**Изменений в существующих файлах:** `TemplateRegistry::resolve` (одна ветка).

### Пример C: новое правило «учитывать настроение из последнего ревью»

1. Расширить `AgendaContextBuilder` — добавить поле `$context->lastReview`.
2. Один из существующих секций (например, `TopicsSection`) использует это в `promptInstructions`, если `$tpl->rules['use_review_mood']` true.
3. Snapshot-тесты для команд без флага — без изменений (флаг false).
4. Тест с включённым флагом — отдельный.

**Изменений в существующих файлах:** только `AgendaContextBuilder` (добавление одного поля). Чтение `$context->lastReview` опционально (`?MeetingReview $lastReview = null`).

### Пример D: новый рендерер «PDF»

1. Создать `app/Domain/Agenda/Renderers/PdfAgendaRenderer.php`.
2. Регистрация в DI-контейнере.
3. Snapshot-тест.
4. Использовать из любого callsite (например, новой команды `agenda:export-pdf`).

**Изменений в существующих файлах:** ноль. Только новый файл.

---

## 9. Feature flags и schema versioning

### 9.1 Feature flags для миграции

Минимальный набор:
- `agenda.use_new_pipeline` (bool, default false) — включает Steps 1-7 нового pipeline.
- `agenda.use_new_renderers` (bool, default false) — включает новые `WebAgendaRenderer`/`TelegramAgendaRenderer`. Параллельно со старыми.
- `agenda.template_id_override` (?int, default null) — для тестирования принудительно задать template.

Хранение — `config/agenda.php` или таблица `feature_flags` (если уже есть). Можно использовать `laravel-pennant` если нужна per-team гранулярность.

### 9.2 Schema versioning для `MeetingAgenda.raw_json`

```sql
ALTER TABLE meeting_agendas ADD COLUMN raw_json_schema_version SMALLINT DEFAULT 1;
```

Reader всегда смотрит на `schema_version`:

```php
class MeetingAgendaReader {
    public function read(MeetingAgenda $agenda): AgendaPayload {
        return match ($agenda->raw_json_schema_version) {
            1 => $this->readV1($agenda->raw_json),
            2 => $this->readV2($agenda->raw_json),
            default => throw new \RuntimeException("Unknown schema version"),
        };
    }
}
```

При первой существенной правке формата — новый `readV2`, старые записи остаются читаемыми. Через год можно сделать backfill-команду для миграции.

### 9.3 Snapshot test для рендерера

Самая важная страховка против поломки:

```php
it_renders_default_general_agenda_to_match_snapshot() {
    $rawJson = file_get_contents(__DIR__ . '/fixtures/agenda_default_general_v1.json');
    $agenda = MeetingAgenda::factory()->make(['raw_json' => json_decode($rawJson, true)]);
    
    $rendered = (new PlainAgendaRenderer())->render($agenda);
    
    $expected = file_get_contents(__DIR__ . '/snapshots/agenda_default_general_v1.md');
    $this->assertEquals($expected, $rendered);
}
```

Любое изменение рендерера ломает этот тест → human-decision: либо это регрессия, либо сознательная правка (обновляем snapshot).

---

## 10. Связь с основным планом

| Этот документ | [Основной план](transcript-pipeline-refactor.md) |
|---|---|
| Распил `AgendaService` (всё) | H-1 + Phase 3 |
| Prompt-слой только для агенды | ADR-2 (усечённая версия) + H-2 |
| `LlmGateway` использование | ADR-3 (зависимость) |
| `NameMatcher` | H-11 (новая, см. 9.4) |
| `IssueStatsService` использование вместо `getBacklogStats` | M-11 |
| `MeetingAgenda.content` schema clarification | M-9 |
| Feature flags подход | расширяет 9.10 main plan |

**Что должно быть сделано в main plan ДО этого документа:**

1. **Pre-Phase 0** (main plan 9.3) — SQL-аудит, OpenRouterClient::chat фикс. Без этого тесты-страховку не написать.
2. **Phase 0 minimal** (main plan 9.7) — регрессионные тесты на существующее поведение агенды (Шаг 1 в 7.2).
3. **Phase 1 must-haves** (main plan 9.8) — queued listeners, чтобы pipeline был стабилен.

**Что Distractor:** ADR-1 (TranscriptPipeline) — **НЕ нужен** для конструктора агенды. Конструктор работает поверх существующей event-driven системы.

---

## 11. Открытые вопросы

### 11.1 Валидация предположений A-E (см. раздел 3)

Самое важное. Без ответов архитектура — догадка.

- **A**: подтверждается ли, что секции варьируются от команды к команде?
- **B**: подтверждается ли, что разные типы встреч → разные правила?
- **C**: будет ли UI-конструктор? Когда?
- **D**: оставлять ли существующее поведение как Default? (думаю, да)
- **E**: `UpcomingAgendaService` тоже под конструктор или нет?

### 11.2 Уровень настройки шаблона

- Шаблон на уровне team? organization? user? per-event?
- Может ли user override team's template для одной встречи?
- Versioning — кто отвечает за переезд команды на v2 prompt?

### 11.3 Connection к Methodology

В системе есть `Methodology` model (используется `FollowupService`). Связан ли конструктор агенды с методологией? Например, методология «Scrum» имеет свой шаблон агенды по умолчанию?

### 11.4 Roles & permissions

Кто может редактировать шаблон? Любой member команды? Только owner? Это влияет на dataModel `agenda_templates`.

### 11.5 Backwards-compat для готовых агенд в БД

Уже сгенерированные `MeetingAgenda` за прошлые встречи — их `raw_json` в текущем формате. После миграции на новый renderer — UI продолжит их корректно показывать? Если нет — нужен backfill или multi-version reader.

### 11.6 Отношение к `UpcomingAgendaService`

Технически он отдельный сервис, но семантически — «персональная агенда для следующей встречи». Можно ли унифицировать с `personal` agenda конструктора? Это **отдельная архитектурная задача** на потом.

### 11.7 Тест-coverage для новых секций

Планируется ли требовать **обязательный** snapshot-тест для каждой новой секции? Это инфра-вопрос (как enforced — через CI? через code review?).

### 11.8 Локализация имён

`NameMatcher` работает только для русского сейчас. Нужны ли en/es/etc словари? Это влияет на дизайн `NameVariantsRegistry`.

---

## Приложение A: Файлы (что появляется)

```
app/Domain/Agenda/
├── AgendaContext.php                    # DTO
├── AgendaTemplate.php                   # value object (PHP-класс на старте)
├── Sections/
│   ├── AgendaSection.php                # interface
│   ├── TopicsSection.php
│   ├── CommitmentsSection.php
│   ├── BacklogSection.php
│   ├── StuckTasksSection.php
│   ├── TasksBetweenMeetingsSection.php
│   ├── OpenQuestionsSection.php
│   └── FollowUpsSection.php
├── Templates/
│   ├── DefaultGeneralAgendaTemplate.php
│   ├── DefaultPersonalAgendaTemplate.php
│   └── (будущие)
├── Prompts/
│   ├── AgendaPrompt.php                 # interface
│   ├── RenderedPrompt.php               # value object
│   ├── DefaultGeneralAgendaPromptV1.php
│   └── DefaultPersonalAgendaPromptV1.php
├── Renderers/
│   ├── AgendaRenderer.php               # interface
│   ├── PlainAgendaRenderer.php          # для MeetingAgenda.content
│   ├── WebAgendaRenderer.php            # для UI
│   └── TelegramAgendaRenderer.php       # для Telegram
├── Parsers/
│   ├── CommitmentParser.php
│   └── TopicsExtractor.php
├── CommitmentStatusMatcher.php
├── StuckTasksDetector.php
└── AgendaPromptFactory.php

app/Domain/Names/
├── NameMatcher.php
└── NameVariantsRegistry.php

app/Services/Agenda/
├── AgendaService.php                    # тонкий orchestrator (50-100 строк)
├── AgendaContextBuilder.php
├── AgendaPersister.php
├── SectionRegistry.php
├── TemplateRegistry.php
├── MeetingAgendaReader.php              # для schema_version
├── MeetingSeriesStateService.php        # без изменений
├── PreviousMeetingResolver.php          # без изменений
└── UpcomingAgendaService.php            # отдельно, не входит в конструктор пока

config/agenda.php                        # feature flags
```

```
tests/
├── Unit/Domain/Agenda/
│   ├── Sections/*Test.php (по тесту на секцию)
│   ├── Prompts/*Test.php
│   ├── Renderers/*Test.php
│   ├── Parsers/*Test.php
│   └── ContextBuilderTest.php
├── Feature/Agenda/
│   ├── AgendaServiceSnapshotTest.php   # регрессия — главное
│   ├── AgendaTemplateApplicationTest.php
│   └── ParallelOldNewPipelineTest.php  # для Step 8
└── snapshots/agenda/
    └── *.md                             # эталоны рендера
```

---

## Приложение B: Что в этом документе НЕ обсуждается

- Унификация `AgendaService` ↔ `UpcomingAgendaService` (отдельная задача).
- Pipeline-pattern для всего пост-транскрипт пайплайна (см. main plan ADR-1).
- LLM cost optimization (см. main plan 9.6).
- Унификация Telegram нотификаторов (см. main plan H-7).
- Distinct prompt layer для остальных сервисов (Summary, Review, Followup) — здесь только агенда.
- Полная унификация `MeetingTaskService` ↔ `IssueExtractionService` (см. main plan C-1).

Все эти задачи могут идти параллельно с Agenda Constructor, но не входят в его scope.
