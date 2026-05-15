# Transcript Pipeline — аудит и план рефакторинга

**Статус:** черновик, обсуждение архитектуры. Локальный документ, не пушится.
**Дата составления:** 2026-05-08.
**Скоп:** весь код, выполняющийся ПОСЛЕ получения транскрипта встречи (от `App\Events\TranscriptParsed` и далее), включая агенду/протокол/извлечение задач/решений/инсайтов/обзоров.

---

## Enhancement Summary

**Обогащено:** 2026-05-08 (тот же день).
**Прогон:** 8 параллельных агентов — `architecture-strategist`, `code-simplicity-reviewer`, `data-integrity-guardian`, `pattern-recognition-specialist`, `performance-oracle`, `best-practices-researcher`, `framework-docs-researcher`, `learnings-researcher`.

### Фактологические поправки к v1 (читать ДО плана)
- **`QUEUE_CONNECTION=redis` в production** (`.env:91`), `sync` — только в `.env.testing` и dev-локалке. Все упоминания «queue=sync ничего не изменит» в разделах 5.2 и 5.3 — некорректны: race conditions из 5.3 уже работают в проде, не «когда-нибудь».
- **`OpenRouterClient::chat`** — `public static`. Утверждение «инжектится через DI» (раздел 2.5) формально работает только потому, что PHP позволяет дёргать static через инстанс, но это и есть техдолг C-5.
- **Диспатч `IssuesExtracted`** идёт из [`app/Jobs/ExtractIssuesFromTranscriptJob.php:28`](../app/Jobs/ExtractIssuesFromTranscriptJob.php) и `Console/Commands/TestIssueExtractionPipeline:65` — НЕ из сервиса. Закрыт открытый вопрос Приложения C.
- **LLM calls per meeting пересчитан**: не 18-30, а **35-47** (был недосчитан Insight-блок: `InsightEvolutionService` × 6 категорий × 4 участника = 24, `InsightRelationshipService` × 2 на пару × C(4,2) = 12). См. раздел 9.6.
- **Cost ≈ $0.73/встречу** при текущей модели (gemini-3-pro-preview через OpenRouter). Wall-clock в queue=sync — 5–6 минут, что объясняет timeout-боль из CLAUDE.md memory.
- **`Decision::issues()` баг конкретизирован**: `withTimestamps(['created_at', null])` передаёт массив там, где Laravel ждёт строку (`BelongsToMany::withTimestamps($createdAt, $updatedAt)`). Однострочный фикс — `withPivot('created_at')` (см. [memory note](../../../home/b/.claude/projects/-home-b-vanda/memory/known_bug_decision_issues_relation.md)).
- **`decision_issue` уже имеет composite PRIMARY KEY**(`decision_id, issue_id`) через миграцию 2026_04_27_120100. ADR-9 unique-constraint ИЗБЫТОЧЕН — нужен только `insertOrIgnore` вместо `exists()+insert()`.

### Ключевые улучшения плана
1. **Pre-Phase 0** — обязательный SQL-аудит дубликатов (Insight items, Decisions per team), фикс `OpenRouterClient::chat` на инстанс ДО тестов, проверка Eloquent model events (могут давать двойной триггер при queued), grep на `dispatchSync` (места обходов async-модели), измерение текущего LLM cost baseline.
2. **Phase 1 must-haves**: `ShouldQueueAfterCommit` (не `ShouldQueue`), `WithoutOverlapping->expireAfter()` (иначе lock зависает на kill -9), `ThrottlesExceptions::failWhen(PermanentLlmException::class)`, кастом-иерархия `App\Exceptions\Llm\*`, `RateLimited` middleware (без него Phase 1 = `429 storm` от OpenRouter), `enforceMorphMap` для polymorphic Issue→sourceable, idempotency для Telegram-нотификаций.
3. **Конкретный однострочный fix N+1**: [`TranscriptBuilderService::build()`](../app/Services/Followup/TranscriptBuilderService.php) забыл `->with('participant')` — на 300-500 entries × 6 builds это 1.5–3 секунды wall-clock впустую. **5x ускорение одной строкой**.
4. **Saga log table** (`pipeline_idempotency_keys` или `transcript_pipeline_runs`) реализуем уже в Phase 1, не в Phase 5/6 — чтобы каждый job сразу писал в saga log.
5. **Mergeable LLM calls (P2/Phase 4)**: Summary+Decisions+RepeatedDisc → один промпт, IssueExtract+Merge → один, Evolution × 6 категорий → один. Экономия ~110 sec wall-clock + $0.10/встречу. С даунгрейдом 7 «дешёвых» вызовов на Flash/Haiku — ~50% bill saved.

### Новые проблемы, обнаруженные обзором (детали в разделе 9)
- **C-7** (новая Critical): `askLlmForCoverage` — прямой дубль алгоритма в `LinkDecisionsToIssuesService::link()` и `VerifyMeetingArtifactsJob::askLlmForCoverage()`. Demo и production идут разными путями linking decisions↔issues.
- **H-10**: `try/catch + Log::error + status FAILED` повторяется в 16+ местах. Кандидат на `LlmArtifactRunner` trait/class.
- **H-11**: Три независимых name-matcher'а (`nameMatches`, `namesMatch`, `getNameVariants` с **хардкодом имён сотрудников Vanda** — Виктор/Витя/Фёдор/Жерновой). Не масштабируется на нового клиента.
- **H-12**: Listeners используют `app(...)` (service locator) непоследовательно с DI.
- **H-13**: Промпт-сходство «semantic match» в 3 местах (IssueMerge, RepeatedDisc, Review.previous_suggestions_check) — общий шаблон.
- **M-7**: Не для всех LLM-артефактов есть `<Artifact>Generated` событие. Нет: `FollowupGenerated`, `UpcomingAgendaGenerated`, `MeetingAgendaGenerated`, `MeetingSeriesStateUpdated`, `RepeatedDiscussionsDetected`, `DecisionsExtracted`.
- **M-8**: `Setting::get('model.X', config('ai...X'))` повторяется 23 раза.
- **M-9**: `MeetingAgenda.content` — усечённый flat-text vs полное `raw_json` + `renderForWeb/Telegram`. Возможна неконсистентность отображения.
- **M-10**: `AgendaService::extractCommitmentsFromSummary` — fallback для старого формата summary, может быть dead code.
- **M-11**: `AgendaService::getBacklogStats` дублирует существующий `IssueStatsService`.
- **L-5..L-8**: `MeetingTaskStatus` enum в `Issue` model, разные return-типы для глагола `extract`, listener naming chaos, mixed `Status::DONE` vs `->value`.

### Два контрастных взгляда — обсудить ДО Phase 0
- **architecture-strategist** говорит: «корневая проблема — нет владельца операции “обработка транскрипта”. ADR-1 (Pipeline) — единственное лекарство, остальное симптомы».
- **code-simplicity-reviewer** говорит: «план в 1705 строк — over-engineering. ADR-1, ADR-7, ADR-9, Phase 6 — выкинуть. Реальной работы — ~5 файлов на 1 спринт + queued listeners на полдня + 5 регрессионных тестов».

Эти позиции взаимоисключающие и должны быть разрешены ДО запуска Phase 1. См. подраздел 9.2.

---

## Оглавление

1. [Executive summary](#1-executive-summary)
2. [Текущая архитектура](#2-текущая-архитектура)
   - 2.1 Точка входа: `ParseTranscriptJob`
   - 2.2 Event-граф пост-транскрипта
   - 2.3 Карта job'ов
   - 2.4 Карта сервисов и их обязанности
   - 2.5 LLM-вызовы: модели, промпты, разрозненность
   - 2.6 Модели данных и таблицы
   - 2.7 Контроллеры, команды, scheduler
3. [Каталог проблем](#3-каталог-проблем-по-тяжести)
4. [Phase 0 — регрессионная сеть](#4-phase-0--регрессионная-сеть-тестов)
5. [Phase 1 — queued listeners и базовая стабилизация](#5-phase-1--queued-listeners-и-базовая-стабилизация)
6. [Целевая архитектура (ADR-эскизы)](#6-целевая-архитектура-adr-эскизы)
7. [План фаз](#7-план-фаз)
8. [Открытые вопросы](#8-открытые-вопросы)
9. [Research Insights (multi-agent review)](#9-research-insights-multi-agent-review)
   - 9.1 Фактологические поправки
   - 9.2 Два контрастных взгляда — что выбрать
   - 9.3 Pre-Phase 0 — обязательная подготовка
   - 9.4 Новые проблемы (C-7, H-10..H-13, M-7..M-11, L-5..L-8)
   - 9.5 Расширения существующих проблем
   - 9.6 LLM cost & latency reality check
   - 9.7 Phase 0 — уточнения
   - 9.8 Phase 1 — must-haves
   - 9.9 ADR-эскизы — ревизия
   - 9.10 Pattern wins to preserve
   - 9.11 Quick wins (P0)
   - 9.12 Релевантные источники
   - 9.13 Сводка институциональных learnings
   - 9.14 Открытые вопросы — обновлённые

---

## 1. Executive summary

Пост-транскриптный пайплайн представляет собой **17 listener'ов**, **15+ jobs** и **30+ сервисов**, разбросанных по 8 модулям (`Meeting`, `Followup`, `Agenda`, `Decisions`, `Insight`, `Issue`, `Methodologies` и корневые сервисы). Триггер — событие `App\Events\TranscriptParsed`, которое вызывает 5 listener'ов; те, в свою очередь, поднимают каскад дочерних событий и job'ов.

**Главные риски, которые мешают спокойно править код:**

1. **Sync-listener'ы на тяжёлых LLM-вызовах** (Summary, Review, Decisions, RepeatedDiscussions). Падение любого ломает обработку транскрипта целиком, retry перепарсит транскрипт. Только Insight-листенеры реально queued.
2. **Дубликат логики извлечения задач** — `MeetingTaskService` (используется в Demo + контроллер `/tasks/generate`, который никем не вызывается из фронтенда) и `IssueExtractionService` (production). Оба пишут в `issues`, у обоих свои события и листенеры.
3. **God-class `AgendaService` (1255 строк)** — сбор контекста, парсинг commitment'ов, два промпта, рендеринг markdown, статистика бэклога — всё в одном файле.
4. **Промпты разбросаны** по private-методам сервисов и одной Blade-вьюхе (`methodology_prompt.blade.php`). Тестировать промпты отдельно от LLM-вызова невозможно.
5. **Покрытие тестами «мозга» системы — около нуля.** Нет тестов на `MeetingSummaryService`, `MeetingReviewService`, `InsightExtractionService`, `ExtractDecisionsService`, `VerifyMeetingArtifactsJob`, `MeetingSeriesStateService`, `DetectRepeatedDiscussionsService`. Это значит, что любая правка делается слепо.
6. **Транзакционность непостоянна.** `ExtractDecisionsService` оборачивает запись в транзакцию, `VerifyMeetingArtifactsJob` пишет в `decision_issue` напрямую без транзакции.
7. **`InsightExtractionService::persist` может удваивать инсайты при повторном запуске** — `firstOrCreate` для `InsightSource`, но `items()->create` для каждого нового item без удаления старых.
8. **Дублирование чтения транскрипта** — `TranscriptBuilderService::build()` вызывается 5+ раз для одной встречи (по одному разу в каждом сервисе). Кеша нет.
9. **Дублирование логики «кто участники события»** — `UpcomingAgendaService`, `AgendaService`, `SendAgendaNotificationsJob`, `CalendarEventOrganizationResolver` — у каждого свой набор путей разрешения, иногда несовместимый.

**Принятые решения по объёму этой итерации:**

- Документ и план **без кода**.
- Старый путь извлечения задач (`MeetingTaskService`) **в Demo не трогаем**. Production-эндпоинт `/tasks/generate` — кандидат на удаление, но это в плане, а не сейчас.
- Главный фокус первой реальной итерации после этого документа: **тесты-страховка + перевод listener'ов на queued**.
- Все правки локальные.

---

## 2. Текущая архитектура

### 2.1 Точка входа: `ParseTranscriptJob`

[`app/Jobs/ParseTranscriptJob.php`](../app/Jobs/ParseTranscriptJob.php)

Принимает `CalendarEvent` и URL JSON-транскрипта от Recall. Скачивает, очищает существующие записи, парсит speakers и timeline, создаёт `Participant` + `TranscriptEntry`, в конце:

```php
TranscriptParsed::dispatch($this->calendarEvent);
```

Ключевые особенности:

- **`participants()->delete()`** на строке `42` — каждый запуск стирает участников. Если `participant.profile_id` уже был сматчен ранее (`ParticipantProfileMatchingService`), сматчинг теряется и должен делаться заново.
- **Идемпотентности нет.** Повторный вызов перепарсит и удвоит side-effect'ы вниз по цепи (например, `InsightSource` уже существует → пишутся дубли items, см. C-3).
- Триггер — `Webhooks/RecallWebhook.php` после готовности транскрипта.

### 2.2 Event-граф пост-транскрипта

```
ParseTranscriptJob
   └─ event TranscriptParsed
        │
        ├── [sync] GenerateMeetingSummary
        │      └─ MeetingSummaryService::generate()  ── LLM (model.meeting_summary)
        │            └─ event MeetingSummaryGenerated
        │                 ├── [sync] ExtractDecisionsAfterSummary
        │                 │      └─ ExtractDecisionsService::extract()  ── LLM (enrichment)
        │                 ├── [sync] DetectRepeatedDiscussions
        │                 │      └─ DetectRepeatedDiscussionsService::detect() ── LLM
        │                 ├── [sync] UpdateMeetingSeriesState
        │                 │      └─ UpdateMeetingSeriesStateJob (queued)
        │                 │            └─ MeetingSeriesStateService::updateAfterMeeting() ── LLM
        │                 └── [sync] SendMeetingSummaryNotification
        │                        └─ Telegram API
        │
        ├── [sync] GenerateFollowup
        │      ├─ for each team → GenerateFollowupJob (queued)
        │      │     └─ FollowupService::generate() ── LLM (model.followup, blade prompt)
        │      └─ ExtractIssuesFromTranscriptJob (queued)
        │            └─ IssueExtractionService::extract() ── LLM (model.followup)
        │                  └─ IssueMergeService::persist() ── LLM (dedup)
        │                        └─ event IssuesExtracted
        │                             ├── [sync] DispatchAgentTasksForIssues  (no-op, log only)
        │                             └── (диспатч VerifyMeetingArtifactsJob — см. ниже)
        │
        ├── [sync] GenerateUpcomingAgenda
        │      └─ GenerateUpcomingAgendaJob (queued)
        │            └─ UpcomingAgendaService::generateForEvent()
        │                 └─ for each user → generateForUser() ── LLM (model.agenda)
        │
        ├── [sync] GenerateMeetingReview
        │      └─ MeetingReviewService::generate() ── LLM (model.meeting_review)
        │            └─ event MeetingReviewGenerated
        │                 └── [sync] SendMeetingReviewNotification → Telegram
        │
        └── [queued] ExtractInsightItemsListener
               └─ InsightExtractionService::extract() ── LLM (model.insight)
                     └─ event InsightItemsExtracted
                          ├── [queued] UpdateInsightProfilesListener
                          │     └─ InsightEvolutionService::evolveFromSource()
                          └── [queued] UpdateInsightRelationshipsListener
                                └─ InsightRelationshipService::processFromEvent()
```

**Где `VerifyMeetingArtifactsJob` запускается?** Грепом по `VerifyMeetingArtifactsJob::dispatch` находится в `app/Jobs/ExtractIssuesFromTranscriptJob.php` — после создания issues (нужно подтвердить отдельным проходом, пока считаем, что да). Это дотягивает Decision'ы до Issue'ев.

**Что НЕ из этого графа** (запускается отдельно):

- `GenerateAgendaJob` — manual API `POST /calendar-events/{id}/agendas/generate` и команда `agenda:generate-missing`. Использует другой сервис (`AgendaService`) и другую модель (`MeetingAgenda`).
- `MeetingTaskController@generate` (POST `/tasks/generate`) → `MeetingTaskService::extract()` — manual API, фронтендом **не вызывается** (грепы по `tasks/generate` в `spodial_hr_frontend` пусты), но используется в `ProcessDemoEventJob`.
- `RegenerateFollowupJob` — manual API `POST /followups/{id}/regenerate`.
- `decisions:send-followups` (cron каждые 10 мин) → `SendDecisionFollowupsJob` → `DecisionFollowupNotifier`.
- `SendAgendaNotificationsJob` — manual dispatch, рассылка `MeetingAgenda` в Telegram.

### 2.3 Карта job'ов

| Job | Где dispatched | Queued? | Что делает |
|---|---|---|---|
| `ParseTranscriptJob` | `RecallWebhook` | ✅ | Скачивает транскрипт, диспатчит `TranscriptParsed` |
| `GenerateFollowupJob` | listener `GenerateFollowup` (per team) | ✅ | `FollowupService::generate` |
| `RegenerateFollowupJob` | API `FollowupController@regenerate`, AgentTool `RegenerateFollowupTool` | ✅ | `FollowupService::regenerate` |
| `ExtractIssuesFromTranscriptJob` | listener `GenerateFollowup` | ✅ | `IssueExtractionService::extract` |
| `VerifyMeetingArtifactsJob` | `ExtractIssuesFromTranscriptJob` (по факту запуска) | ✅ | gap-fill decisions → issues, нотификация неполных |
| `GenerateUpcomingAgendaJob` | listener `GenerateUpcomingAgenda` | ✅ | `UpcomingAgendaService::generateForEvent` |
| `GenerateAgendaJob` | API `AgendaController@generate`, команда `agenda:generate-missing` | ✅ | `AgendaService::generateForEvent` (большая повестка) |
| `UpdateMeetingSeriesStateJob` | listener `UpdateMeetingSeriesState` | ✅ | `MeetingSeriesStateService::updateAfterMeeting` |
| `GenerateMethodologySchemeJob` | (manual?) | ✅ | `MethodologySchemeGenerator` — генерация JSON-схемы методологии |
| `DetectRepeatedDiscussionsJob` | возможно, не используется (есть и сервис, и job) | ✅ | `DetectRepeatedDiscussionsService::detect` |
| `SendAgendaNotificationsJob` | manual dispatch | ✅ | Telegram-нотификация по `MeetingAgenda` |
| `SendDecisionFollowupsJob` | scheduler (`every 10 min`) | ✅ | Напоминания по орфанным `Decision` |
| `SendEmailJob` | разные сервисы | ✅ | Email из EmailService |
| `CheckPaperclipIssueStatusJob` | вне нашего скоупа | ✅ | Polling Paperclip API |
| `CriticalPathAgentAnalysisJob`, `RebuildCriticalPathJob`, `QuickCpmUpdateJob` | вне нашего скоупа | ✅ | Critical path module |
| `RunAgentTaskJob` | агенты | ✅ | Запуск агента через executor |
| `ProcessChatBranchJob`, `ProcessChatWorkerJob`, `ProcessTelegramBranchJob`, `ProcessTelegramWorkerJob` | чат-флоу | ✅ | Не наш скоуп |
| `Demo/*` | demo seeding | ✅ | Не трогаем |

**В скоупе аудита (помимо отдельных уточнений):** `GenerateFollowupJob`, `RegenerateFollowupJob`, `ExtractIssuesFromTranscriptJob`, `VerifyMeetingArtifactsJob`, `GenerateUpcomingAgendaJob`, `GenerateAgendaJob`, `UpdateMeetingSeriesStateJob`, `SendAgendaNotificationsJob`, `SendDecisionFollowupsJob`.

### 2.4 Карта сервисов и их обязанности

#### Сервисы основного флоу

##### `App\Services\Meeting\MeetingSummaryService`

[`app/Services/Meeting/MeetingSummaryService.php`](../app/Services/Meeting/MeetingSummaryService.php) (134 строки)

- **Вход:** `CalendarEvent`.
- **Триггер:** sync listener `GenerateMeetingSummary`.
- **LLM:** `model.meeting_summary` (→ gemini-3-pro-preview), max 4096 tokens, JSON-режим.
- **Промпт:** inline в `buildPrompt()`, со встроенным многострочным эталонным «примером протокола».
- **Запись:** `MeetingSummary` с `updateOrCreate([], ...)` — на встречу один summary, перезаписывается.
- **Диспатчит:** `MeetingSummaryGenerated`.
- **Обработка ошибок:** `try/catch`, на ошибке — статус `FAILED`, лог. Исключение проглатывается.
- **AgentActivityLog:** да, если есть `event->source->user`.

##### `App\Services\Meeting\MeetingReviewService`

[`app/Services/Meeting/MeetingReviewService.php`](../app/Services/Meeting/MeetingReviewService.php) (257 строк)

- **Вход:** `CalendarEvent`.
- **Триггер:** sync listener `GenerateMeetingReview`.
- **LLM:** `model.meeting_review`, max 8192 tokens, JSON.
- **Промпт:** inline в `buildPrompt()` с метаданными встречи + статистикой участия (PHP-расчёт `buildParticipationStats`) + историей последних 5 ревью.
- **Запись:** `MeetingReview` через `updateOrCreate([], ...)`.
- **Диспатчит:** `MeetingReviewGenerated` (на него подписан `SendMeetingReviewNotification`).

##### `App\Services\Meeting\DetectRepeatedDiscussionsService`

- **Триггер:** sync listener `DetectRepeatedDiscussions`.
- **LLM:** да (модель `model.meeting_summary`, по контексту кода).
- **Запись:** обновляет JSON-поле `MeetingSummary.repeated_discussions`.

##### `App\Services\Meeting\MeetingTaskService` (старый путь задач)

[`app/Services/Meeting/MeetingTaskService.php`](../app/Services/Meeting/MeetingTaskService.php) (140 строк)

- **Триггер:** API `MeetingTaskController@generate` (никем не вызывается из фронта) и `ProcessDemoEventJob` (demo).
- **Поведение:** 
  - сначала прогоняет `ParticipantProfileMatchingService::match($event)` — пытается сматчить participants с профилями;
  - **`$event->issues()->forceDelete()`** на строке `57` — стирает все issues встречи перед созданием;
  - LLM-вызов `model.meeting_tasks`, JSON, простой промпт с blocks профилей и orgContext;
  - создаёт `Issue` со статусом `OPEN`, `assignee_id` через map participant→profile→user;
  - диспатчит `MeetingTasksExtracted` с коллекцией `Issue`.
- **Слушают `MeetingTasksExtracted`:**
  - `LinkDecisionsAfterTasksExtracted` → `LinkDecisionsToIssuesService::link()` (linking decisions↔issues),
  - `NotifyCalendarOwnerAboutCreatedTasks` (Telegram организатору),
  - `SendMeetingTasksNotification` (Telegram в Team-чаты).

**Это — параллельный и более старый путь.** Мы не трогаем его в demo, но в production он мёртвый (см. C-1).

##### `App\Services\Meeting\ParticipantProfileMatchingService`

[`app/Services/Meeting/ParticipantProfileMatchingService.php`](../app/Services/Meeting/ParticipantProfileMatchingService.php)

Используется только в `MeetingTaskService`. По имени это «сматчить participants с Profile». Для production-флоу автоматического сматчинга нет — `ParseTranscriptJob` пересоздаёт participants с `profile_id = null` и никто не вызывает matching.

**Следствие:** в production transcripts участники почти всегда без `profile_id`, и логика `IssueExtractionService::resolveAssigneeId()` опирается на name-matching через `IssueMergeService::nameMatches()` (см. ниже).

##### `App\Services\Meeting\MeetingContextService`

Используется в `PreMeetingBriefService`, `Today\DailyNudgeService`, `Today\TodayBriefingService` — **вне нашего скоупа** (today-флоу), но трогает те же модели (CalendarEvent, MeetingSummary). При рефакторинге надо беречь его API.

##### `App\Services\Followup\FollowupService`

[`app/Services/Followup/FollowupService.php`](../app/Services/Followup/FollowupService.php) (131 строка)

- **Вход:** `CalendarEvent`, `Team`, `User`.
- **Триггер:** `GenerateFollowupJob` (per team), `RegenerateFollowupJob` (manual).
- **Транзакция:** `DB::transaction(...)` оборачивает создание `Followup` и `generateContent`.
- **LLM:** `model.followup`, max 8192, JSON; промпт через **Blade-шаблон** [`resources/views/prompts/methodology_prompt.blade.php`](../resources/views/prompts/methodology_prompt.blade.php).
- **Контекст промпта:** `methodology->text`, `transcript`, `artifact_id`, `artifact_title`, `ArtifactSchema::dataDescription()`.
- **Запись:** `Followup` (text — целиком JSON от LLM как строка).
- **Диспатчит:** ничего (нет события `FollowupGenerated`).
- **Side-effects:** `AgentActivityLog::recordActivity('followup_generated')`.

`Log::info('messages', $messages)` на строке `97` — массивный вывод полного промпта в логи каждой генерации. Это создаёт большой шум.

##### `App\Services\IssueExtractionService` (новый путь задач)

[`app/Services/IssueExtractionService.php`](../app/Services/IssueExtractionService.php) (166 строк)

- **Вход:** `CalendarEvent`, `Team`, `User`.
- **Триггер:** `ExtractIssuesFromTranscriptJob`.
- **LLM:** `model.followup` (sic — модель «фолоуапа», а не `model.meeting_tasks`!), max 4096, JSON.
- **Промпт:** inline `buildSystemPrompt` — детальный, с правилами «is/is-not task», field-rules, форматом `{name, description, type, assignee_name, due_date, priority}`.
- **JSON-извлечение:** регексп `/\{[\s\S]*\}/s` — fallback на случай если LLM дописал текст вокруг JSON.
- **Запись:** делегируется `IssueMergeService::persist()`.
- **Диспатчит:** ничего самостоятельно (диспатч `IssuesExtracted` — внутри `IssueMergeService`? **нужно проверить**, в коде сервиса диспатча нет; вероятно событие диспатчится в `ExtractIssuesFromTranscriptJob` после).
- **Side-effects:** `AgentActivityLog::recordActivity('issues_extracted')`.

##### `App\Services\IssueMergeService`

[`app/Services/IssueMergeService.php`](../app/Services/IssueMergeService.php) (357 строк)

- **Назначение:** дедупликация — для каждого нового item решает `create | update | skip`.
- **LLM-вызов:** да, `model.followup` (отдельный от извлечения), system+user, JSON.
- **System prompt:** `buildMergeSystemPrompt()` — детально объясняет матчинг по INTENT/GOAL.
- **Запись:**
  - `create` → `Issue::create(...)`;
  - `update` → `$existing->update(...)` + `IssueComment::create(...)` (комментарий с привязкой к calendar_event);
  - `skip` → ничего;
  - не обработанные LLM индексы — fallback в `create` (защита от data loss).
- **Резолвинг assignee:**
  1) через `event->profiles` (прямая привязка `calendar_event_profile`);
  2) через всех members команды по name-match (`nameMatches` — substring/contains/equals lower-case).
- **Парсинг даты:** `Carbon::parse($value)`.
- **Маппинг priority:** строка → `Issue::PRIORITY_*`.
- **Транзакции нет.** Серия `Issue::create` + `IssueComment::create` + `Issue->update` идут без обёртки.

##### `App\Services\Decisions\ExtractDecisionsService`

[`app/Services/Decisions/ExtractDecisionsService.php`](../app/Services/Decisions/ExtractDecisionsService.php) (163 строки)

- **Вход:** `MeetingSummary`.
- **Триггер:** sync listener `ExtractDecisionsAfterSummary` на `MeetingSummaryGenerated`.
- **LLM:** **статический** вызов `OpenRouterClient::chat(...)` (а не через `$this->llm`!), `model.meeting_summary`.
- **Промпт:** inline в `enrichWithAuthors()` — для списка из summary вытягивает author + topic, поддерживает `skip:true` для процедурных решений.
- **Запись:**
  - `Decision::where('summary_id', ...)->delete()` — удаляет все решения текущего summary;
  - **создаёт по `Decision` на каждую team из `resolveTeamContexts($event)` для каждого решения** — то есть если у события 2 команды и 5 решений, в БД будет 10 строк `Decision`.
- **Транзакция:** `DB::transaction(fn() => ...)`.
- **Trait:** `ResolvesTeamContexts` — общий с `LinkDecisionsToIssuesService`, `DecisionFollowupNotifier`.

##### `App\Services\Decisions\DecisionAuthorResolver`

Отдельный сервис, вызывается из `ExtractDecisionsService::extract` для каждого решения, чтобы найти `author_user_id`/`author_profile_id` по `author_raw_name`.

##### `App\Services\Decisions\LinkDecisionsToIssuesService`

[`app/Services/Decisions/LinkDecisionsToIssuesService.php`](../app/Services/Decisions/LinkDecisionsToIssuesService.php)

- **Триггер:** только listener `LinkDecisionsAfterTasksExtracted` (то есть **только в demo-флоу**, через `MeetingTasksExtracted`).
- **В production-флоу не используется.** Linking decisions↔issues в production идёт через `VerifyMeetingArtifactsJob`.
- Это **дубликат функционала** для одной задачи в двух местах с разной логикой.

##### `App\Services\Decisions\DecisionFollowupNotifier`

[`app/Services/Decisions/DecisionFollowupNotifier.php`](../app/Services/Decisions/DecisionFollowupNotifier.php)

- **Триггер:** `SendDecisionFollowupsJob` (cron каждые 10 мин).
- **Работа:** находит `Decision` без issues и без followup'ов, событие которых > 120 мин назад. Шлёт автору в Telegram напоминалку.

##### `App\Services\Decisions\ExtractKeyPointsService`

Существует в `app/Services/Decisions/`. **Использование надо проверить** — возможно тоже dead code.

##### `App\Services\Agenda\AgendaService` (god-class)

[`app/Services/Agenda/AgendaService.php`](../app/Services/Agenda/AgendaService.php) (1255 строк)

Методы:

| Метод | Назначение |
|---|---|
| `generateForEvent` (28-115) | Главная точка, собирает контекст |
| `generateGeneralAgenda` (117-271) | Промпт + LLM для общей повестки |
| `generatePersonalAgenda` (273-350) | Промпт + LLM для персональной повестки |
| `collectStructuredData` (352-435) | Сбор структурированных данных встречи |
| `buildGeneralPrompt` (437-527) | Большой inline-промпт |
| `parseCommitment` (529-563) | Парсинг строкового commitment в структуру |
| `extractCommitmentsFromSummary` (565-614) | Парсинг commitments из MeetingSummary |
| `matchCommitmentStatus` (616-664) | Сматчивание commitment ↔ issue по имени и тексту |
| `getNameVariants` (666-700) | Варианты имени (Виктор/Витя/Витёк и т.п.) |
| `getTasksBetweenMeetings` (702-726) | Issues, созданные между двумя митингами |
| `getBacklogStats` (728-763) | Статистика бэклога команды |
| `extractTopicsFromSummary` (765-798) | Темы из summary |
| `buildPersonalPrompt` (800-919) | Inline-промпт персональный |
| `collectFollowUps` (921-936) | Объединение upcoming agendas в follow-ups |
| `normalizeDateTime` (938-945) | Carbon-нормализация |
| `renderGeneralContent` (947-1183) | **236 строк** Markdown-рендеринга |
| `detectStuckTasks` (1185-1206) | Issues, висящие давно |
| `renderPersonalContent` (1208-1255) | Markdown-рендеринг персональный |

Это **god-class по всем признакам**: смешаны 7 разных обязанностей (data, prompts, parsing, name matching, statistics, rendering, orchestration). Самая большая угроза «правка тут ломает там».

##### `App\Services\Agenda\UpcomingAgendaService`

[`app/Services/Agenda/UpcomingAgendaService.php`](../app/Services/Agenda/UpcomingAgendaService.php) (~128 строк)

- **Триггер:** `GenerateUpcomingAgendaJob`.
- Резолвит участников через 4 пути (participants/profile/user, calendar_event_profile, source.user, event.creator).
- Для каждого вызывает `generateForUser` — своя LLM-генерация, `model.agenda`.
- Запись в **другую** таблицу — `upcoming_agendas` (не путать с `meeting_agendas`).

##### `App\Services\Agenda\AgendaContext`

DTO с контекстом для AgendaService.

##### `App\Services\Agenda\PreviousMeetingResolver`

Поиск предыдущих митингов в той же серии.

##### `App\Services\Agenda\MeetingSeriesStateService`

[`app/Services/Agenda/MeetingSeriesStateService.php`](../app/Services/Agenda/MeetingSeriesStateService.php) (~116 строк)

Поддерживает «состояние серии встреч» — markdown-документ, который аккумулирует знания через свёртку (fold). Каждая встреча обновляет `MeetingSeriesState.content` через LLM, читая предыдущую версию + текущий summary.

`series_identifier` строится из CalendarEvent — **возможна коллизия** при одинаковых названиях у разных recurrence-серий (см. M-3).

##### `App\Services\Insight\*` — пять сервисов

- `InsightExtractionService` — основной (см. файл выше).
- `InsightPromptBuilder` — единственное «правильное» вынесенное место для промпта.
- `InsightEvolutionService` — обновляет долгосрочные тренды профиля (вызывается из `UpdateInsightProfilesListener`).
- `InsightRelationshipService` — связи между профилями (`UpdateInsightRelationshipsListener`).
- `InsightRetrievalService`, `InsightMaintenanceService`, `InsightTelegramService`, `InsightService` — вспомогательные. Часть из них может относиться к чтению/админке, не к транскрипт-флоу.

##### `App\Services\Followup\TranscriptBuilderService`

[`app/Services/Followup/TranscriptBuilderService.php`](../app/Services/Followup/TranscriptBuilderService.php)

- Превращает `TranscriptEntry[]` в строку для LLM.
- Используется **минимум 5 раз** на одной встрече: MeetingSummary, MeetingReview, FollowupService (per team), IssueExtraction, ExtractDecisions::enrichWithAuthors, InsightExtraction.
- В `app/Services/Followup/` — странное место, потому что используется не только followup'ом.

##### `App\Services\CalendarEventOrganizationResolver`

Резолвит organization+team по participants. Используется в `GenerateFollowup` listener и `MeetingTaskService`. Возвращает `null` если не получилось — тогда issues не извлекаются. Это правильная логика, но участвует в общей путанице «как связаны user/team/event» (см. CLAUDE.md).

#### Сервисы вне основного флоу но связанные

- `App\Services\Issue\IncompleteIssuesNotifier` — Telegram-уведомления о неполных issues после `VerifyMeetingArtifactsJob`.
- `App\Services\IssueAgentService`, `IssueAgentFlowService`, `IssueAgentFlowProgressService`, `IssueStatsService`, `IssueTypeResolver` — issue lifecycle вне нашего скоупа.
- `App\Services\Methodologies\*` — `MethodologySchemeGenerator`, `SchemePromptFactory`, `BaseSchemePrompt`, `PromptV1`, `PromptV2`. Генерация JSON-схемы методологии (отдельная задача, привязана к `Methodology`-сущности, **не вызывается из транскрипт-флоу**, но используется в `Followup` через `methodology_prompt.blade.php`).

### 2.5 LLM-вызовы: модели, промпты, разрозненность

#### Конфигурация моделей: [`config/ai.php`](../config/ai.php)

```php
'openrouter' => [
    'models' => [
        'followup'        => 'google/gemini-3.1-pro-preview',
        'meeting_summary' => 'google/gemini-3.1-pro-preview',
        'meeting_review'  => 'google/gemini-3.1-pro-preview',
        'meeting_tasks'   => 'google/gemini-3.1-pro-preview',
        'insight'         => 'google/gemini-3.1-pro-preview',
        'agenda'          => 'google/gemini-3.1-pro-preview',
        'scheme'          => 'google/gemini-3.1-pro-preview',
        ...
    ],
],
```

**Все модели сейчас одинаковые.** Конфигурация сделана как «может разные», но в проде всё на gemini-3-pro-preview.

#### Где промпты живут

| Тип | Где | Пример |
|---|---|---|
| Inline в private-методе сервиса | большинство | `MeetingSummaryService::buildPrompt`, `MeetingReviewService::buildPrompt`, `IssueExtractionService::buildSystemPrompt`, и т.д. |
| Inline через heredoc в job'е | один случай | `VerifyMeetingArtifactsJob::askLlmForCoverage` |
| Blade-шаблон | один | `resources/views/prompts/methodology_prompt.blade.php` (только для Followup) |
| Отдельный builder-класс | один | `App\Services\Insight\InsightPromptBuilder::buildExtractionPrompt` |
| Прокладка с версиями | один | `App\Services\Methodologies\Prompts\PromptV1`/`PromptV2` через `SchemePromptFactory` |

**Нет единого подхода.** Тестировать промпты отдельно от LLM невозможно почти везде. Сравнить прохождение разных промптов через одни и те же фикстуры — нельзя.

#### Где LLM вызывается — паттерны

Два паттерна, оба применяются параллельно:

**Pattern A — через инжектированный экземпляр** (большинство):

```php
public function __construct(private readonly OpenRouterClient $llm) {}
$this->llm->chat(...)
```

Технически работает, потому что `chat()` — `public static`, а PHP позволяет вызывать static через инстанс. Но это вводит в заблуждение: читатель думает, что у клиента есть инстанс-стейт.

**Pattern B — статически**:

```php
OpenRouterClient::chat(...)
```

Используется в `ExtractDecisionsService::enrichWithAuthors` и `VerifyMeetingArtifactsJob::askLlmForCoverage`.

Разнобой делает мокирование клиента в тестах сложнее: при инжекции легко заменить через сервис-контейнер, при static — нужен Mockery::mock('alias:...') или другой workaround.

#### Список всех LLM-вызовов в скоупе

| Сервис/Job | Метод | Модель |
|---|---|---|
| `MeetingSummaryService::generate` | один вызов | `model.meeting_summary` |
| `MeetingReviewService::generate` | один вызов | `model.meeting_review` |
| `DetectRepeatedDiscussionsService::detect` | по разу на team | `model.meeting_summary` (предположительно) |
| `MeetingSeriesStateService::updateAfterMeeting` | один вызов | `model.meeting_summary` |
| `MeetingTaskService::extract` | один вызов | `model.meeting_tasks` |
| `FollowupService::generateContent` | один вызов | `model.followup` |
| `IssueExtractionService::extract` | один вызов (Pass 1) | `model.followup` |
| `IssueMergeService::getDecisions` | один вызов (Pass 2) | `model.followup` |
| `ExtractDecisionsService::enrichWithAuthors` | один вызов | `model.meeting_summary` |
| `VerifyMeetingArtifactsJob::askLlmForCoverage` | один вызов | `model.meeting_tasks` |
| `UpcomingAgendaService::generateForUser` | по разу на user | `model.agenda` |
| `AgendaService::generateGeneralAgenda` | один вызов | `model.agenda` |
| `AgendaService::generatePersonalAgenda` | по разу на user | `model.agenda` |
| `InsightExtractionService::callLLM` | один вызов | `model.insight` |
| `InsightEvolutionService::evolveFromSource` | один вызов (LLM, по контексту) | `model.insight`? |
| `InsightRelationshipService::processFromEvent` | один или несколько | `model.insight`? |

**Итого на одну встречу с одной командой и 4 участниками** в production:
- Summary (1) + Review (1) + Decisions (1) + RepeatedDisc (1) + SeriesState (1) + Followup (1) + Issues (1) + Merge (1) + UpcomingAgenda (4) + Insight extract (1) + Evolution (4) + Relationships (1) ≈ **18 LLM-вызовов**.

Если у юзера 3 команды — добавляется x3 на followup, x3 на decisions, x3 на repeated discussions. Может получиться 25–30 вызовов.

`VerifyMeetingArtifactsJob::ensureSummary` ещё может **повторно** запустить `MeetingSummaryService::generate` если summary неполный → ещё один.

### 2.6 Модели данных и таблицы

| Модель | Ключевые поля | JSON-blob'ы | Ключевые связи |
|---|---|---|---|
| `CalendarEvent` | `source_id`, `creator_user_id`, `starts_at`, `ends_at`, `series_key`, `bot_id` | `description` (text), нет JSON | `participants` (HasMany), `transcriptEntries`, `meetingSummary` (HasOne), `meetingReview` (HasOne), `followups` (HasMany), `meetingAgendas`, `issues` (Polymorphic), `sources` (BelongsToMany), `profiles` (BelongsToMany через `calendar_event_profile`) |
| `Participant` | `calendar_event_id`, `name`, `profile_id` (nullable) | — | `transcriptEntries`, `profile` |
| `TranscriptEntry` | `calendar_event_id`, `participant_id`, `text`, `start_relative`, `end_relative`, `start_absolute`, `end_absolute` | — | — |
| `MeetingSummary` | `calendar_event_id`, `status` | `title`, `summary`, `key_points`, `decisions`, `commitments`, `repeated_discussions` | `calendarEvent` (BelongsTo), `decisions` (HasMany через `summary_id`) |
| `MeetingReview` | `calendar_event_id`, `status`, `score` | `score_breakdown`, `key_insight`, `suggestions`, `participation`, `agenda_analysis`, `trend`, `previous_suggestions_check` | `calendarEvent` |
| `Followup` | `calendar_event_id`, `team_id`, `user_id`, `methodology_id`, `status` | `text` (целиком JSON-строка от LLM) | `team`, `user`, `methodology`, `decisions` (HasMany?) |
| `MeetingAgenda` | `calendar_event_id`, `user_id` (null=general), `type`, `status`, `send_scheduled_at`, `sent_at` | `raw_json`, `content` | `calendarEvent`, `user` |
| `UpcomingAgenda` | `user_id`, `source_calendar_event_id`, `series_key`, `status` | `raw_json`, `content` | `user`, `sourceCalendarEvent` |
| `Issue` | `user_id`, `team_id`, `organization_id`, `sourceable_type`, `sourceable_id`, `name`, `description`, `assignee_id`, `assignee_name`, `due_date`, `status`, `priority`, `type` | — | `team`, `assignee`, `sourceable` (Morph), `decisions` (BelongsToMany через `decision_issue`) |
| `IssueComment` | `issue_id`, `user_id`, `parent_id`, `calendar_event_id`, `content` | — | `issue`, `user`, `calendarEvent`, `parent` |
| `Decision` | `calendar_event_id`, `summary_id`, `team_id`, `organization_id`, `author_user_id`, `author_profile_id`, `author_raw_name`, `text`, `topic`, `source_type` | — | `calendarEvent`, `summary`, `team`, `issues` (BelongsToMany через `decision_issue`) |
| `MeetingSeriesState` | `series_identifier`, `version`, `source_event_id` | `content` (markdown) | `sourceEvent` |
| `Methodology` | `team_id`, `user_id`, `status`, `scheme_version` | `text`, `scheme` (JSON) | `team`, `user`, `followups` |
| `InsightSource` | `profile_id`, `source_type`, `source_id`, `processed_at` | — | `items`, `shortTermMemories`, `profile` |
| `InsightItem` | `insight_source_id`, `profile_id`, `category`, `fact`, `confidence` | — | `source`, `profile` |
| `InsightShortTermMemory` | `insight_source_id`, `profile_id`, `context_type`, `content`, `expires_at` | — | `source`, `profile` |

#### Pivot-таблицы

- `calendar_event_source` — `CalendarEvent` ↔ `Source`
- `calendar_event_profile` — `CalendarEvent` ↔ `Profile`
- `decision_issue` — `Decision` ↔ `Issue` (поле `created_at`, без `updated_at` судя по `VerifyMeetingArtifactsJob::insert(['created_at' => now()])`)
- `team_user` — `Team` ↔ `User`

#### Известные баги моделей (CLAUDE.md memory)

- `Decision::issues()` relation — **сломан**, обходить через `DB::table('decision_issue')`. Это и видно в `VerifyMeetingArtifactsJob` — он работает напрямую с DB::table, потому что relation глючит.

### 2.7 Контроллеры, команды, scheduler

#### API endpoints (в скоупе)

| Метод+URL | Контроллер | Что делает |
|---|---|---|
| `GET /calendar-events/{id}/meeting-summary` | `MeetingSummaryController@show` | Чтение |
| `POST /calendar-events/{id}/meeting-summary/generate` | `MeetingSummaryController@generate` | Manual `MeetingSummaryService::generate` |
| `GET /calendar-events/{id}/meeting-review` | `MeetingReviewController@show` | Чтение |
| `POST /calendar-events/{id}/meeting-review/generate` | `MeetingReviewController@generate` | Manual `MeetingReviewService::generate` |
| `POST /calendar-events/{id}/followups/generate` | `FollowupController@generate` | Dispatch `GenerateFollowupJob` |
| `POST /followups/{id}/regenerate` | `FollowupController@regenerate` | Dispatch `RegenerateFollowupJob` |
| `GET /teams/{team}/followups`, `GET /followups/{id}` | `FollowupController` | Чтение |
| `GET /calendar-events/{id}/agendas` | `AgendaController@index` | Чтение |
| `GET /calendar-events/{id}/agendas/{agenda}` | `AgendaController@show` | Чтение |
| `POST /calendar-events/{id}/agendas/generate` | `AgendaController@generate` | Dispatch `GenerateAgendaJob` |
| `GET /me/agendas` | `AgendaController@myAgendas` | Чтение |
| `GET /me/upcoming-agenda` | `UpcomingAgendaController@show` | Чтение |
| `GET /calendar-events/{id}/tasks`, `GET /tasks/{id}` | `MeetingTaskController` | Чтение Issue (read API) |
| `POST /calendar-events/{id}/tasks/generate` | `MeetingTaskController@generate` | **Manual `MeetingTaskService::extract`** — фронтом не вызывается |

#### Artisan-команды

- `agenda:generate-missing` — находит события без agenda, диспатчит `GenerateAgendaJob`.
- `decisions:send-followups` — `SendDecisionFollowupsJob`, в scheduler каждые 10 минут (`bootstrap/app.php:81`).

#### Scheduler

[`bootstrap/app.php`](../bootstrap/app.php) (последняя секция):

```php
$schedule->command('telescope:prune --hours=12')->dailyAt('23:59')->timezone('Europe/Moscow');
$schedule->command('email:cleanup-verifications')->daily();
$schedule->command('decisions:send-followups')->everyTenMinutes()->withoutOverlapping();
```

#### Listener-регистрация

Используется **auto-discovery Laravel 11** — нет `EventServiceProvider`, нет явного `Event::listen`. Listeners в `app/Listeners/` должны иметь метод `handle($event)` с правильным type-hint.

**Следствие:** аудит флоу — только грепом по `dispatch` и просмотром listener-классов. Нет одного места, где видны все мэппинги.

---

## 3. Каталог проблем (по тяжести)

Метки:
- **C** — critical, блокирует уверенность в коде, может терять или дублировать данные.
- **H** — high, ухудшает devex/тестируемость или создаёт каскадные риски.
- **M** — medium, локальное несовершенство.
- **L** — low, косметика.

### C-1. Дубликат логики извлечения задач (старый/новый путь)

**Симптом:** в системе сосуществуют два пути извлечения задач из транскрипта: `MeetingTaskService` (старый) и `IssueExtractionService` (новый). Оба пишут в `issues`, у обоих свои события (`MeetingTasksExtracted` vs `IssuesExtracted`), у каждого собственный набор listener'ов.

**Механизм:**

| | Старый | Новый |
|---|---|---|
| Сервис | `MeetingTaskService` | `IssueExtractionService` |
| Триггер | API `POST /tasks/generate` (фронт не вызывает) + `ProcessDemoEventJob` | `ExtractIssuesFromTranscriptJob` от `TranscriptParsed` |
| Поведение | `forceDelete()` всех Issue события + LLM + создание | LLM + `IssueMergeService` (create/update/skip) |
| Модель | `model.meeting_tasks` | `model.followup` (sic) |
| Событие | `MeetingTasksExtracted` | `IssuesExtracted` |
| Listeners | `LinkDecisionsAfterTasksExtracted`, `NotifyCalendarOwnerAboutCreatedTasks`, `SendMeetingTasksNotification` | `DispatchAgentTasksForIssues` (no-op) |
| Linking decisions↔issues | Через `LinkDecisionsToIssuesService` | Через `VerifyMeetingArtifactsJob` |

**Последствия:**

- Если кто-то вызовет `/tasks/generate` после того, как production-флоу уже отработал — все issues будут удалены и созданы заново без дедупликации.
- Linking decisions↔issues идёт двумя разными путями.
- Telegram-нотификация о задачах в demo и в production использует разный код.
- Тесты на «как извлекаются задачи» — это два набора тестов на одну фичу.

**Файлы:**

- [`app/Services/Meeting/MeetingTaskService.php`](../app/Services/Meeting/MeetingTaskService.php)
- [`app/Services/IssueExtractionService.php`](../app/Services/IssueExtractionService.php)
- [`app/Services/IssueMergeService.php`](../app/Services/IssueMergeService.php)
- [`app/Listeners/LinkDecisionsAfterTasksExtracted.php`](../app/Listeners/LinkDecisionsAfterTasksExtracted.php)
- [`app/Listeners/NotifyCalendarOwnerAboutCreatedTasks.php`](../app/Listeners/NotifyCalendarOwnerAboutCreatedTasks.php)
- [`app/Listeners/SendMeetingTasksNotification.php`](../app/Listeners/SendMeetingTasksNotification.php)
- [`app/Http/Controllers/API/v1/MeetingTaskController.php`](../app/Http/Controllers/API/v1/MeetingTaskController.php)
- [`app/Jobs/Demo/ProcessDemoEventJob.php`](../app/Jobs/Demo/ProcessDemoEventJob.php)

**Варианты решений (на потом, в этой итерации только фиксируем):**

1. **(Рекомендуется)** Извлечь интерфейс `TaskExtractionStrategy`. Demo использует `SimpleTaskExtractionStrategy` (current behavior MeetingTaskService без force-delete), production — `MergingTaskExtractionStrategy` (current IssueExtractionService+IssueMergeService). Удалить `MeetingTaskController@generate` (его никто не зовёт). Унифицировать событие до `IssuesExtracted`. Listeners `LinkDecisionsAfterTasksExtracted`/`NotifyCalendarOwnerAboutCreatedTasks`/`SendMeetingTasksNotification` либо переподписать на `IssuesExtracted`, либо переключить demo на единый pipeline.
2. Оставить два пути, чётко изолировав: старый путь только из `Demo` namespace, удалить роут `/tasks/generate`. Меньше работы, но дубликат остаётся.

**Зависимости:** перед действиями — Phase 0 (тесты-страховка).

---

### C-2. Sync listener'ы на тяжёлых LLM-вызовах

**Симптом:** из 17 listener'ов реально queued — 3 (Insight). Остальные синхронны.

**Механизм:**

`TranscriptParsed::dispatch($event)` в Laravel запускает **все listener'ы в одном процессе последовательно**, кроме реализующих `ShouldQueue`. Поскольку `dispatch` сам по себе вызывается из `ParseTranscriptJob::handle()`, который — Job, всё это исполняется в одном worker-процессе.

Цепочка sync-нагрузок на главный поток обработки транскрипта:

| Listener | Сервис | Время LLM |
|---|---|---|
| `GenerateMeetingSummary` | `MeetingSummaryService` (4096 tok) | ~5 сек |
| `GenerateMeetingReview` | `MeetingReviewService` (8192 tok) | ~8 сек |
| `GenerateFollowup` | dispatch + `ExtractIssuesFromTranscriptJob` (тот queued) | <1 сек dispatch |
| `GenerateUpcomingAgenda` | dispatch | <1 сек |

И каскадно из `MeetingSummaryGenerated` (sync):

| Listener | LLM |
|---|---|
| `ExtractDecisionsAfterSummary` | `model.meeting_summary` |
| `DetectRepeatedDiscussions` | по разу на team |
| `UpdateMeetingSeriesState` | dispatch |
| `SendMeetingSummaryNotification` | Telegram API |

`MeetingReviewGenerated`:

| Listener | |
|---|---|
| `SendMeetingReviewNotification` | Telegram API |

**Последствия:**

- Любая ошибка в любом sync-listener'е поднимет исключение в `ParseTranscriptJob` → retry job → транскрипт перепарсится **с нуля** (включая удаление Participant, см. C-3).
- Нет per-listener retry — нельзя «повторить только review, остальное оставить».
- Latency обработки одного транскрипта = сумма всех LLM (легко 30+ секунд только на sync-цепи).
- Telegram-API сбой ломает всю обработку.

**Файлы:**

- [`app/Listeners/GenerateMeetingSummary.php`](../app/Listeners/GenerateMeetingSummary.php)
- [`app/Listeners/GenerateMeetingReview.php`](../app/Listeners/GenerateMeetingReview.php)
- [`app/Listeners/GenerateFollowup.php`](../app/Listeners/GenerateFollowup.php)
- [`app/Listeners/ExtractDecisionsAfterSummary.php`](../app/Listeners/ExtractDecisionsAfterSummary.php)
- [`app/Listeners/DetectRepeatedDiscussions.php`](../app/Listeners/DetectRepeatedDiscussions.php)
- [`app/Listeners/UpdateMeetingSeriesState.php`](../app/Listeners/UpdateMeetingSeriesState.php)
- [`app/Listeners/SendMeetingSummaryNotification.php`](../app/Listeners/SendMeetingSummaryNotification.php)
- [`app/Listeners/SendMeetingReviewNotification.php`](../app/Listeners/SendMeetingReviewNotification.php)

**Решение (Phase 1):** перевести каждый listener на `implements ShouldQueue` с осторожной настройкой `tries`, `backoff`, `timeout`. Подробности — в разделе 5.

---

### C-3. Re-parsing удваивает Insight items, может терять profile-matching

**Симптом:** при повторном вызове `ParseTranscriptJob` для уже обработанной встречи `InsightSource` найдётся через `firstOrCreate`, но `items()->create` создаст НОВЫЕ записи без удаления старых. То же со `shortTermMemories`.

**Механизм:**

В [`app/Services/Insight/InsightExtractionService.php:159-189`](../app/Services/Insight/InsightExtractionService.php):

```php
$source = InsightSource::firstOrCreate(
    ['profile_id' => $profile->id, 'source_type' => 'transcript', 'source_id' => $event->id],
    ['processed_at' => now()],
);

foreach ($participant->items as $item) {
    $source->items()->create([...]);   // ← дубликаты при повторе
}
```

И ещё: [`app/Jobs/ParseTranscriptJob.php:41-42`](../app/Jobs/ParseTranscriptJob.php):

```php
$this->calendarEvent->transcriptEntries()->delete();
$this->calendarEvent->participants()->delete();
```

`participants()->delete()` стирает `participant.profile_id` (он на participant). Если до парсинга кто-то сматчил participant с profile (через `ParticipantProfileMatchingService`) — это пропадёт.

**Последствия:**

- Повторный запуск (по retry job или ручной) → разрастание insight items.
- Потеря результатов `MeetingTaskService::extract()` (он матчит participants), если перепарсить транскрипт после.
- Потенциальная неконсистентность между прогонами.

**Файлы:**

- [`app/Jobs/ParseTranscriptJob.php`](../app/Jobs/ParseTranscriptJob.php)
- [`app/Services/Insight/InsightExtractionService.php`](../app/Services/Insight/InsightExtractionService.php)

**Решение (на потом):**

- В `ParseTranscriptJob` — `updateOrCreate(['name' => ...], [...])` для Participant, `transcriptEntries` тоже `delete then re-create` оставить, но идемпотентно.
- В `InsightExtractionService::persist`: при существующем `InsightSource` — `delete` старых items+shortTermMemories ИЛИ выйти раньше с пометкой «уже обработано». Понятная семантика — обсуждать.

---

### C-4. Транзакционность непоследовательна

**Симптом:** одни сервисы оборачивают запись в `DB::transaction`, другие — нет, для тех же связанных таблиц.

**Где есть транзакция:**

- `FollowupService::generate` / `regenerate` — да, оборачивает `Followup::create` + content.
- `ExtractDecisionsService::extract` — да, оборачивает `Decision::where(summary_id)->delete()` + `Decision::create` x N teams.

**Где нет:**

- `IssueMergeService::persist` → `applyDecisions` создаёт/обновляет N issues + N IssueComment без транзакции. Если PHP упадёт между, останутся частично созданные issues.
- `VerifyMeetingArtifactsJob::gapFillUncoveredDecisions` — `IssueMergeService::persist` (без транзакции) + `DB::table('decision_issue')->insert` (без транзакции).
- `VerifyMeetingArtifactsJob::linkOrReportUncoveredDecisions` — `DB::table('decision_issue')->insert` без транзакции, плюс LLM-вызов между.
- `MeetingSummaryService::generate` / `MeetingReviewService::generate` — без транзакции, но там одна запись через updateOrCreate.
- `InsightExtractionService::persist` — нет, foreach с `firstOrCreate` + N x `items()->create` + N x `shortTermMemories()->create`.

**Последствия:**

- Возможны частичные состояния при сбоях.
- Дубликаты в `decision_issue` теоретически защищены проверкой `exists()` перед `insert`, но между exists и insert есть гонка (минорно для текущей нагрузки).

**Решение:** обёртка job-bound операций в `DB::transaction`. Идемпотентные ключи (`unique` на `decision_issue` (decision_id, issue_id)) через миграцию.

---

### C-5. Декларативная неконсистентность LLM-клиента (static vs instance)

**Симптом:** `OpenRouterClient::chat()` объявлен как `public static`, но в большинстве сервисов инжектится через DI и вызывается `$this->llm->chat(...)`. В `ExtractDecisionsService::enrichWithAuthors` и `VerifyMeetingArtifactsJob::askLlmForCoverage` — статически.

**Файлы:**

- [`app/Services/OpenRouterClient.php:18`](../app/Services/OpenRouterClient.php) — `public static function chat`
- [`app/Services/Decisions/ExtractDecisionsService.php:120`](../app/Services/Decisions/ExtractDecisionsService.php) — `OpenRouterClient::chat(...)`
- [`app/Jobs/VerifyMeetingArtifactsJob.php:236`](../app/Jobs/VerifyMeetingArtifactsJob.php) — `OpenRouterClient::chat(...)`
- Все остальные сервисы — `$this->llm->chat(...)` с инжекцией

**Последствия:**

- Тесты: при моках через сервис-контейнер static-вызовы не интерсептятся. Нужен `Mockery::mock('alias:OpenRouterClient')` или другой воркэраунд.
- Читателю кода непонятно, какой паттерн правильный.

**Решение:** перейти на единый паттерн — либо все через инстанс (`public function chat`), либо все статически (но тогда тестировать намного сложнее). Рекомендация — все через инстанс.

---

### C-6. Дубликат `Decision`-ов на каждую team события

**Симптом:** `ExtractDecisionsService::extract` проходит `foreach ($teamContexts as [$teamId, $organizationId])` и для **каждого решения создаёт по `Decision` на каждую team**. То есть 5 решений × 3 команды = 15 строк.

[`app/Services/Decisions/ExtractDecisionsService.php:59-73`](../app/Services/Decisions/ExtractDecisionsService.php):

```php
foreach ($teamContexts as [$teamId, $organizationId]) {
    Decision::create([
        'calendar_event_id' => $event->id,
        'summary_id'        => $summary->id,
        'team_id'           => $teamId,
        ...
    ]);
}
```

**Последствия:**

- `VerifyMeetingArtifactsJob::getUncoveredDecisions` ищет по `calendar_event_id` без фильтра по team — то есть видит все 15 строк. Но gap-fill пишет в `team` из job → может создать issue для team-A на основе decision team-B.
- `decision_issue` linking может быть неоднозначен.
- Просто странно семантически: одно решение — одна сущность. То, что оно «существует в контексте N команд», это логика чтения, а не дубликации в БД.

**Файлы:**

- [`app/Services/Decisions/ExtractDecisionsService.php`](../app/Services/Decisions/ExtractDecisionsService.php)
- [`app/Services/Decisions/ResolvesTeamContexts.php`](../app/Services/Decisions/ResolvesTeamContexts.php) — trait
- [`app/Jobs/VerifyMeetingArtifactsJob.php`](../app/Jobs/VerifyMeetingArtifactsJob.php)

**Решение (на потом):** одна `Decision` на одно решение, связь с teams — через pivot `decision_team` (если нужна множественность). Но это серьёзная миграция.

---

### H-1. God-class `AgendaService` (1255 строк)

**Симптом:** `AgendaService.php` — 1255 строк, 18 методов, 7 разных обязанностей (см. таблицу в 2.4).

**Последствия:**

- Любая правка в `renderGeneralContent` (240 строк markdown!) требует понимания, как `parseCommitment` форматирует данные, как `matchCommitmentStatus` сматчивает, как `getNameVariants` нормализует имена.
- Тесты на отдельные подсистемы невозможны без поднятия всего класса.
- Изменение формата markdown ломает форму `MeetingAgenda.content`, который, возможно, сохранён в БД для тысяч событий — нужно перегенерировать.

**Решение (на потом):** распилить на:

- `AgendaContextBuilder` — `collectStructuredData`, `getTasksBetweenMeetings`, `getBacklogStats`, `extractTopicsFromSummary`, `detectStuckTasks`.
- `CommitmentParser` — `parseCommitment`, `extractCommitmentsFromSummary`, `matchCommitmentStatus`, `getNameVariants`.
- `GeneralAgendaGenerator` — promptBuilder + LLM call.
- `PersonalAgendaGenerator` — promptBuilder + LLM call.
- `AgendaRenderer` — `renderGeneralContent`, `renderPersonalContent`.
- `AgendaService` (orchestration) — координация: вызвать context, generators, persist, render.

---

### H-2. Промпты неотделимы от LLM-вызовов

**Симптом:** большинство промптов — private heredoc внутри сервисов. Нельзя написать тест «когда у нас X фикстура транскрипта, промпт содержит правильную дату/имена/инструкции». Сравнить версии промптов на одних данных — нельзя.

**Файлы:** см. таблицу в 2.5.

**Решение (на потом):** общий интерфейс/абстракция:

```php
interface TranscriptPromptInterface {
    public function build(array $context): string;
    public function model(): string;
    public function maxTokens(): int;
    public function expectsJson(): bool;
}
```

Каждый промпт — отдельный класс, в `app/Domain/Prompts/Transcript/*` (намеренно вне Services). Юнит-тесты на промпты — отдельно от LLM.

---

### H-3. Дублирование чтения транскрипта

**Симптом:** `TranscriptBuilderService::build($event)` вызывается ≥5 раз на одной встрече: Summary, Review, Followup, IssueExtraction, ExtractDecisions, Insight.

**Файлы:** [`app/Services/Followup/TranscriptBuilderService.php`](../app/Services/Followup/TranscriptBuilderService.php) (намерано неточный путь — он используется вне Followup).

**Последствия:**

- 5+ обращений к `TranscriptEntry::where('calendar_event_id', $id)->with('participant')->get()`. Не критично, но N+1-подобно.
- Любая правка форматирования транскрипта влияет сразу на все 5 промптов — обычно это и нужно, но иногда хочется разный формат для разных задач.

**Решение (на потом):**

- Просто кеш через `Cache::rememberForever("transcript:{$eventId}", ...)` со сбросом из `ParseTranscriptJob::handle()`.
- Или прокидывать готовую строку через job-цепочку (TranscriptParsed event несёт `$transcript` и `$calendarEvent`).
- Перенести сервис в `app/Services/Transcript/` (отдельная папка).

---

### H-4. Дублирование «кто участники события»

**Симптом:** 4+ места разрешения «какие User'ы причастны к этому CalendarEvent», каждое с разной логикой:

- `UpcomingAgendaService::generateForEvent` — 4 пути (participants.profile.user, profiles.user, sources.user, creator).
- `AgendaService::generateForEvent` — через participants.profile.user_id.
- `SendAgendaNotificationsJob` — через source.user.teams.
- `CalendarEventOrganizationResolver` — через participants.profile.

CLAUDE.md есть отдельный раздел [«Связь пользователей с CalendarEvent»](../CLAUDE.md) — он документирует эту путаницу как факт жизни, без рефакторинга.

**Решение (на потом):** `App\Services\Calendar\CalendarEventUserResolver` с явным контрактом:

```php
public function attendees(CalendarEvent $event): Collection;     // все возможные User
public function organizer(CalendarEvent $event): ?User;          // owner Source
public function teams(CalendarEvent $event): Collection;         // все Team
public function teamMembers(CalendarEvent $event): Collection;   // все User team-members
```

Каждый из 4 текущих мест переключается на этот резолвер.

---

### H-5. Покрытие тестами «мозга» — нулевое

**Симптом:** см. список в Executive Summary. Подавляющее большинство сервисов с LLM-вызовом не имеют unit-тестов.

**Какие есть** (из `find tests`):

- `tests/Feature/AgendaGenerationFallbackTest.php`, `AgendaGenerationRetryTest.php`, `AgendaGenerateMissingCommandTest.php` — есть.
- `tests/Feature/FollowupGenerationTest.php`, `FollowupRegenerationControllerTest.php`, `FollowupRegenerationToolTest.php`, `FollowupPolicyTest.php`, `RegenerateFollowupJobTest.php` — есть.
- `tests/Feature/IssueExtractionPipelineTest.php` — есть.
- `tests/Feature/RecallWebhookTest.php` — есть.
- `tests/Feature/MeetingContextServiceTest.php` — есть (но это другой сервис — для today-флоу).
- `tests/Unit/AgendaServiceTest.php`, `tests/Unit/FollowupArtifactStateServiceTest.php` — есть.

**Чего нет:**

- `MeetingSummaryService` — нет.
- `MeetingReviewService` — нет.
- `InsightExtractionService` (+ `Evolution`, `Relationship`) — нет.
- `ExtractDecisionsService` — нет.
- `DetectRepeatedDiscussionsService` — нет.
- `MeetingSeriesStateService` — нет.
- `IssueMergeService` — **нет** (несмотря на то, что там много нюансной логики).
- `VerifyMeetingArtifactsJob` — нет.
- `MeetingTaskService` — нет.
- `UpcomingAgendaService` — нет.
- Listeners (поведение цепочек events): почти нет.

**Решение (Phase 0):** см. раздел 4.

---

### H-6. `AgendaService` — risky `whereHas` chains

В [`app/Services/Agenda/AgendaService.php`](../app/Services/Agenda/AgendaService.php) множество `whereHas` запросов через 3-4 уровня. Любой N+1 здесь усугубится. Эта классика — нужно покрыть тестом базовых сценариев и отдельным замером.

---

### H-7. Дубликат жанра «Telegram нотификация»

`SendMeetingSummaryNotification`, `SendMeetingReviewNotification`, `SendMeetingTasksNotification`, `NotifyCalendarOwnerAboutCreatedTasks`, `IncompleteIssuesNotifier`, `DecisionFollowupNotifier` — у каждого свой код инициализации `Telegram\Bot\Api`, чтения `TeamNotificationSetting`, цикла по `TelegramChatRegistration`. Шаблон копипасты.

**Решение (на потом):** общий `Notifications\TelegramTeamNotifier` с DSL вроде:

```php
TelegramTeamNotifier::for($team)
    ->whenSettingEnabled('notify_meeting_summary')
    ->send($message);
```

---

### H-8. `DispatchAgentTasksForIssues` — мёртвый listener

[`app/Listeners/DispatchAgentTasksForIssues.php`](../app/Listeners/DispatchAgentTasksForIssues.php) — только пишет лог «auto-dispatch is disabled». Подписан на `IssuesExtracted`. Либо реализовать (и зачем-то это нужно), либо удалить — обсудить.

---

### H-9. `LinkDecisionsToIssuesService` живёт только для demo

Старая прокладка для `MeetingTasksExtracted` → `LinkDecisionsAfterTasksExtracted`. Production использует `VerifyMeetingArtifactsJob`. Это два разных алгоритма linking. Нужно унифицировать в рамках C-1.

---

### M-1. Series identifier коллизии

`MeetingSeriesState::buildSeriesIdentifier($event)` — судя по тому, что `AgendaService` имеет fallback через `whereHas('sourceEvent', fn ($q) => $q->inSameSeriesAs($event))`, идентификатор не всегда совпадает у событий одной серии. Возможна коллизия между разными сериями с одинаковыми title (как предположение от Explore-агента — нужна верификация в коде MeetingSeriesState).

---

### M-2. `Log::info('messages', $messages)` в FollowupService — гигантский лог

[`app/Services/Followup/FollowupService.php:97`](../app/Services/Followup/FollowupService.php) — пишет в лог содержимое промпта. Это десятки KB на каждый followup. Засоряет storage и telescope-prune не успевает.

---

### M-3. JSON-extraction регексп в нескольких сервисах

`preg_match('/\{[\s\S]*\}/s', $json, $matches)` — копипаст в 5+ файлах. Можно вытянуть в helper `JsonResponseExtractor::extract($raw)`.

---

### M-4. `firstOrCreate` риски схлопывания

CLAUDE.md (memory) уже фиксирует похожий случай:

> `Participant::firstOrCreate(['profile_id' => null])` collapses multiple unmatched speakers — fixed by including name.

Аналогичные паттерны:
- `InsightSource::firstOrCreate([profile_id, source_type, source_id], [processed_at])` — тут уникальная троица, ок.
- В `IssueMergeService::resolveAssigneeId` — нет, но nameMatches очень либеральный (substring обоих направлений).

---

### M-5. `MeetingTaskController@index` всегда ходит в `Issue` — но это уже общее место

`/calendar-events/{id}/tasks` и `/tasks/{id}` — читают из единой таблицы Issue. Имя контроллера legacy («Task»), но факт чтения корректный. На переименование — пренебречь, если фронтенд завязан на эндпоинт.

---

### M-6. Неконсистентность статус-енумов

- `FollowupStatus` — IN_PROGRESS / DONE / FAILED.
- `AgendaStatus` — IN_PROGRESS / DONE / FAILED.
- `MeetingTaskStatus` — OPEN / DONE / другие.
- `MeetingSummary.status` — строка через тот же `FollowupStatus` (странная переиспользованность).
- `MeetingReview.status` — то же.
- `Decision` — `source_type` (DecisionSourceType), но нет workflow status.

Нужен общий `LlmArtifactStatus` для одинаковых workflow.

---

### L-1. Странный namespace `App\Services\Followup\TranscriptBuilderService`

Транскрипт-билдер используется кучей сервисов вне Followup. Логично перенести в `App\Services\Transcript\TranscriptBuilderService` или просто `App\Services\TranscriptBuilderService`.

### L-2. PromptV1+PromptV2 без явной стратегии deprecation

`SchemePromptFactory::createPrompt` выбирает по `MethodologySchemeVersion`. Не указано, что V1 deprecated. На текущей итерации не критично.

### L-3. Названия job'ов

`GenerateAgendaJob` и `GenerateUpcomingAgendaJob` — оба про agenda, но это совершенно разные сущности. Ясные имена: `GenerateMeetingAgendaJob` (для общей повестки перед встречей) и `PrepareNextMeetingFollowupJob` (для индивидуальной подготовки участника к следующей встрече).

### L-4. Дубликат `parseDueDate` / `mapPriority`

В `IssueMergeService`. Эти же утилиты могут быть нужны и в других местах. Можно вытянуть в `Issue` как static helpers.

---

## 4. Phase 0 — регрессионная сеть тестов

**Цель:** прежде чем что-либо двигать, зафиксировать поведение текущего кода в тестах. После этого любая правка будет либо сохранять тесты зелёными, либо явно их менять (и тогда видно, что меняется).

**Принципы:**

- Все LLM-вызовы — через мок `OpenRouterClient`. Возвращаем фиксированный JSON.
- Все Telegram-вызовы — мок `Telegram\Bot\Api` через `Bus::fake` или Mockery alias.
- Фикстура транскрипта — один общий: `tests/Fixtures/Transcripts/sample_techsync.json` (мини-вариант), `tests/Fixtures/Transcripts/sample_long.json` для перегрузок.
- Используем `RefreshDatabase` или `DatabaseTransactions` для изоляции.
- Таблицы должны заполняться из фабрик (Factory). Если каких-то фабрик не хватает — заводим в Phase 0.

### 4.1 Список тестов по сервисам (минимум)

#### `MeetingSummaryService`

- `tests/Unit/Meeting/MeetingSummaryServiceTest.php`:
  - `it_creates_or_updates_summary_with_status_in_progress_initially`
  - `it_calls_llm_with_meeting_summary_model`
  - `it_persists_decoded_json_into_fields`
  - `it_dispatches_meeting_summary_generated_event`
  - `it_records_agent_activity_log_when_owner_exists`
  - `it_handles_llm_error_setting_status_failed`
  - `it_handles_invalid_json_response`
  - `it_extracts_json_from_text_with_surrounding_chars`

#### `MeetingReviewService`

- `tests/Unit/Meeting/MeetingReviewServiceTest.php`:
  - `it_calculates_participation_stats_from_transcript`
  - `it_loads_history_of_previous_5_reviews`
  - `it_passes_history_block_into_prompt`
  - `it_handles_no_history_case`
  - `it_persists_score_and_breakdown`
  - `it_dispatches_meeting_review_generated`
  - `it_marks_status_failed_on_llm_error`
  - `it_handles_invalid_json_with_regex_fallback`

#### `FollowupService`

- `tests/Feature/Followup/FollowupServiceTest.php` (Feature потому что Blade-шаблон):
  - `it_uses_team_methodology_when_present`
  - `it_falls_back_to_default_methodology`
  - `it_creates_followup_in_progress_then_done`
  - `it_creates_failed_followup_on_llm_error`
  - `it_records_agent_activity_log`
  - `it_persists_full_llm_json_as_text`
  - `regenerate_creates_new_followup_record_keeping_old_intact`

#### `IssueExtractionService` (расширить существующий `IssueExtractionPipelineTest`)

- `it_returns_empty_collection_for_blank_transcript`
- `it_uses_followup_model`
- `it_extracts_only_items_with_non_empty_name`
- `it_passes_organization_context_when_present`
- `it_handles_text_with_json_inside`
- `it_logs_and_returns_empty_on_llm_error`
- `it_records_agent_activity_log_on_success`

#### `IssueMergeService`

- `tests/Unit/IssueMergeServiceTest.php`:
  - `persist_returns_empty_collection_for_empty_input`
  - `persist_creates_all_when_no_existing_issues`
  - `persist_falls_back_to_create_all_on_llm_failure`
  - `applies_create_action`
  - `applies_update_action_with_fields_and_comment`
  - `applies_skip_action`
  - `falls_back_to_create_when_existing_issue_id_invalid`
  - `creates_issue_for_unhandled_indexes_to_avoid_data_loss`
  - `resolveAssigneeId_uses_event_profiles_first`
  - `resolveAssigneeId_falls_back_to_team_members_by_name`
  - `nameMatches_handles_substring_in_both_directions`
  - `parseDueDate_returns_null_for_invalid`
  - `mapPriority_normalizes_strings_to_constants`

#### `ExtractDecisionsService`

- `tests/Unit/Decisions/ExtractDecisionsServiceTest.php`:
  - `extract_returns_zero_when_summary_has_no_decisions`
  - `extract_creates_one_decision_per_team_per_decision_text`
  - `extract_skips_decisions_marked_as_procedural_skip`
  - `extract_uses_decision_author_resolver`
  - `extract_deletes_existing_decisions_of_summary_before_recreating`
  - `extract_falls_back_when_llm_enrichment_fails`
  - `extract_handles_invalid_json_response`

#### `VerifyMeetingArtifactsJob`

- `tests/Feature/Jobs/VerifyMeetingArtifactsJobTest.php`:
  - `ensures_summary_when_missing`
  - `does_not_regenerate_summary_when_complete`
  - `gap_fills_uncovered_decisions_creating_issues`
  - `gap_fill_writes_decision_issue_pivot`
  - `links_remaining_decisions_via_llm`
  - `notifies_when_incomplete_issues_present`
  - `notifies_when_unresolved_decisions_remain`
  - `does_not_notify_when_all_clear`

#### `InsightExtractionService`

- `tests/Unit/Insight/InsightExtractionServiceTest.php`:
  - `returns_empty_array_for_blank_transcript`
  - `returns_empty_array_when_no_participants_with_profiles`
  - `builds_profile_map_only_for_google_calendar_channel`
  - `dispatches_insight_items_extracted_event_when_sources_created` (через listener-side тест)
  - `creates_insight_source_per_participant`
  - `creates_insight_items_per_extracted_fact`
  - `sets_short_term_ttl_7_days_for_emotional_state_30_otherwise`
  - `handles_llm_error_returning_empty_array`
  - **`reentrant_extract_does_not_duplicate_items`** ← регрессия для C-3

#### `MeetingSeriesStateService`

- `tests/Unit/Agenda/MeetingSeriesStateServiceTest.php`:
  - `creates_initial_state_when_none_exists`
  - `appends_new_version_on_update`
  - `uses_meeting_summary_model_for_fold`
  - `passes_previous_content_into_prompt`
  - `handles_llm_error_gracefully`

#### `DetectRepeatedDiscussionsService`

- Минимально один integration-тест: `it_writes_repeated_discussions_into_summary_json`.

#### `MeetingTaskService` (старый путь)

- В demo-флоу используется. Тестируем в demo-сессии через `ProcessDemoEventJob`. Один happy-path и один failure-path.

#### `IssueExtractionPipelineTest` (Feature, расширить существующий)

- `transcript_parsed_event_dispatches_followup_and_issues_jobs`
- `transcript_parsed_dispatches_meeting_summary`
- `transcript_parsed_dispatches_meeting_review`
- `transcript_parsed_dispatches_upcoming_agenda`
- `transcript_parsed_dispatches_insight_extraction_listener`
- `meeting_summary_generated_dispatches_decisions_repeated_state_notification`
- `meeting_review_generated_dispatches_notification`
- `issues_extracted_runs_verify_artifacts_job`
- `failures_in_one_listener_do_not_break_other_listeners` ← пока не выполняется, см. C-2

#### `AgendaService` (расширить существующий `AgendaServiceTest`)

- Минимально:
  - `commitments_check_matches_status_via_name_variants`
  - `general_agenda_uses_three_previous_meetings`
  - `personal_agenda_per_user_attendees`
  - `renders_general_content_with_all_sections`
  - `renders_personal_content_for_user`
  - `getNameVariants_returns_diminutives` (Виктор → Витя)
  - `parseCommitment_extracts_who_what_deadline`
  - `extractCommitmentsFromSummary_returns_empty_for_no_section`

#### Listener-цепочка end-to-end smoke

- `tests/Feature/TranscriptParsedFlowTest.php`:
  - один большой тест, вызывающий `TranscriptParsed::dispatch` с фикстурой и проверяющий, что **через `Queue::fake` все ожидаемые job'ы запушены** + **через `Event::fake` — все события диспатчатся**. Не запускаем LLM, проверяем только пайплайн-shape.

### 4.2 Поддерживающая инфраструктура

#### Моки и хелперы

- `tests/TestCase.php` — добавить трейт `MocksOpenRouter` или helper-метод:

```php
protected function mockLlmResponse(string $jsonOrText, ?string $model = null): void
{
    $this->mock(OpenRouterClient::class, function ($mock) use ($jsonOrText, $model) {
        $mock->shouldReceive('chat')->andReturn($jsonOrText);
    });
}
```

Для static-вызовов (`OpenRouterClient::chat(...)`) понадобится либо переходный приём (Mockery::mock alias), либо замена static-вызовов на инстанс через DI до тестов (тогда unit-тесты `ExtractDecisionsService` и `VerifyMeetingArtifactsJob` потребуют рефакторинга, который мы сейчас не делаем).

**Компромисс:** для тестов C-5-зависимых сервисов используем feature-тест уровня (через job/listener) и проверяем поведение через результаты в БД, без интерсепта LLM. В качестве заглушки — конфигурим `model.meeting_summary => 'mock'`, имеем `MockOpenRouterDriver`, который возвращает заранее заданные fixture'ы. Это сложнее, но один раз.

**Решение в этой фазе:** строго unit-тестируем те сервисы, у которых LLM инжектится. Static-сервисы покрываем feature-тестами с прокси-конфигом ИЛИ откладываем покрытие до Phase 2 (когда переходим на единый паттерн).

#### Фикстуры

- `tests/Fixtures/Transcripts/sample_techsync.json` — реальный файл из `vanda/transcript_*.txt` (но в формате Recall JSON).
- `tests/Fixtures/LLM/meeting_summary_response.json`
- `tests/Fixtures/LLM/meeting_review_response.json`
- `tests/Fixtures/LLM/issues_extraction_response.json`
- `tests/Fixtures/LLM/issue_merge_response.json`
- `tests/Fixtures/LLM/decisions_enrichment_response.json`
- `tests/Fixtures/LLM/insights_extraction_response.json`
- `tests/Fixtures/LLM/agenda_general_response.json`, `agenda_personal_response.json`

#### Factories — что нужно завести/обновить

Сверка с `database/factories/`:

- `CalendarEventFactory` — есть, нужно расширение `withTranscript()`, `withParticipants(int)`, `withSummary()`.
- `ParticipantFactory` / `TranscriptEntryFactory` — нужны.
- `MeetingSummaryFactory`, `MeetingReviewFactory` — нужны.
- `FollowupFactory` — может быть.
- `IssueFactory`, `IssueCommentFactory` — есть.
- `DecisionFactory` — нужно.
- `MeetingSeriesStateFactory` — нужно.
- `MethodologyFactory` — нужно (для FollowupServiceTest).

### 4.3 Критерии успеха фазы

- Все unit/feature-тесты выше — зелёные.
- Покрытие сервисов в скоупе ≥70% по Xdebug coverage.
- В CI прогон тестов поднимает «mock OpenRouter» и не уходит в реальную сеть.
- Все фабрики самодостаточны (не требуют ручного аранжмента в каждом тесте).

### 4.4 Приблизительный объём работы Phase 0

- ~12 новых тестовых файлов.
- ~80–120 тестовых кейсов.
- ~10 новых фабрик/обновлений.
- ~5–10 fixture-файлов JSON.
- 1 trait `MocksOpenRouter` + 1 trait `MocksTelegram`.

---

## 5. Phase 1 — queued listeners и базовая стабилизация

**Цель:** убрать каскадное падение sync-цепи (C-2). Поведение должно остаться идентичным, но устойчивым к ошибкам.

**Предусловие:** Phase 0 завершён, регрессионные тесты на месте.

### 5.1 Listener'ы и предложения

| Listener | Текущий | Предлагаемое | Соображения |
|---|---|---|---|
| `GenerateMeetingSummary` | sync | `implements ShouldQueue`, `tries=3`, `backoff=[30,60,120]` | LLM 4096, ~5 сек |
| `GenerateMeetingReview` | sync | `implements ShouldQueue`, `tries=3`, `backoff=[30,60,120]` | LLM 8192, ~8 сек |
| `GenerateUpcomingAgenda` | sync | `implements ShouldQueue` (но он только dispatch'ит job — необязательно) | dispatch-only, но всё равно лучше queue |
| `GenerateFollowup` | sync | `implements ShouldQueue` (но dispatch-only) | как выше |
| `ExtractDecisionsAfterSummary` | sync | `implements ShouldQueue`, `tries=3` | LLM-вызов, надо queue |
| `DetectRepeatedDiscussions` | sync | `implements ShouldQueue`, `tries=3` | LLM-вызов |
| `UpdateMeetingSeriesState` | sync (диспатчит queued job) | `implements ShouldQueue` | dispatch-only, но ok |
| `SendMeetingSummaryNotification` | sync | `implements ShouldQueue`, `tries=2`, `backoff=60` | Telegram API, не критично если запоздает |
| `SendMeetingReviewNotification` | sync | `implements ShouldQueue`, `tries=2` | Telegram |
| `LinkDecisionsAfterTasksExtracted` | sync (demo only) | оставить sync, т.к. в demo синхронно проще | demo-spec |
| `NotifyCalendarOwnerAboutCreatedTasks` | sync (demo only) | оставить sync | demo-spec |
| `SendMeetingTasksNotification` | sync (demo only) | оставить sync | demo-spec |
| `DispatchAgentTasksForIssues` | sync, no-op | удалить (вне Phase 1, в Phase 2) | dead code |
| `RescheduleBot` | sync (другое событие) | вне нашего скоупа | — |
| `ExtractInsightItemsListener` | queued | оставить | OK |
| `UpdateInsightProfilesListener` | queued | оставить | OK |
| `UpdateInsightRelationshipsListener` | queued | оставить | OK |

### 5.2 Конфигурация очередей

**Поправка к v1:** prod `.env` уже `QUEUE_CONNECTION=redis`, а `sync` — только в `.env.testing` и часто в dev-локалке. Это значит:

- **Race conditions из 5.3 уже работают в проде**, не «после Phase 1».
- Insight-листенеры (3 шт) — единственные сейчас реально queued. Остальные sync. Перевод остальных на `ShouldQueue` сразу даст async-поведение в prod.
- В dev (sync) перевод НЕ изменит поведения — нужно поднять локальный `database` или `redis` queue, чтобы воспроизводить race conditions.

**Что нужно для Phase 1:**

1. Локально включить `QUEUE_CONNECTION=database` хотя бы для воспроизведения. `php artisan make:queue-table && migrate` + `php artisan queue:listen --tries=3 --timeout=300 --queue=high,default` в отдельном терминале.
2. Учесть: **уже сегодня** в prod некоторые цепи async — например, `ExtractIssuesFromTranscriptJob → VerifyMeetingArtifactsJob` асинхронно, но `Decisions` создаются на TranscriptParsed-listener'е sync. Если `ExtractDecisionsAfterSummary` опаздывает, `VerifyMeetingArtifactsJob.getUncoveredDecisions()` вернёт пустую коллекцию → gap-fill пропустит работу. Это уже потенциальный (молчаливый) баг, который надо проверить логами.

### 5.3 Возможные проблемы при переходе

#### Порядок выполнения

Сейчас при sync-вызове listener'ы исполняются в порядке регистрации (Laravel идёт по auto-discovery в алфавитном порядке имени класса? — **неконтролируемо**, но за десятки релизов сложилась стабильность). При queued — порядок неважен, всё распараллеливается. Зависимости:

- `ExtractDecisionsAfterSummary` зависит от `MeetingSummary` — но получает её через `event->summary`, фактическая запись уже сделана `MeetingSummaryService::generate` до диспатча `MeetingSummaryGenerated`. Безопасно.
- `DetectRepeatedDiscussions` тоже работает с `event->summary` — безопасно.
- `UpdateMeetingSeriesState` тоже.
- `SendMeetingSummaryNotification` — читает `summary->calendarEvent` (уже в БД).

**Race-конкуренции:** `DetectRepeatedDiscussions` пишет `MeetingSummary.repeated_discussions`. Если второй listener тоже пишет в `MeetingSummary` — race. Проверяем: только `DetectRepeatedDiscussions` пишет в `repeated_discussions`. Остальные пишут в свои таблицы. **OK.**

#### IssuesExtracted и VerifyMeetingArtifactsJob

`VerifyMeetingArtifactsJob` ожидает, что `Decision`'ы уже созданы (он смотрит uncovered decisions). А Decision'ы создаются в `ExtractDecisionsAfterSummary` (через `MeetingSummaryGenerated`).

Текущий sync-флоу:
```
TranscriptParsed
  → GenerateMeetingSummary (sync) → MeetingSummaryGenerated → ExtractDecisions (sync) → Decisions созданы
  → GenerateFollowup (sync) → ExtractIssuesFromTranscriptJob (queued)
                                  → IssueExtraction → IssueMerge → ... → VerifyMeetingArtifactsJob
```

Поскольку `ExtractIssuesFromTranscriptJob` queued, он запустится **позже**, когда decisions уже есть. ОК сейчас — но только потому, что queue=sync в dev. В реальном queued environment:

- `ExtractIssuesFromTranscriptJob` пушится одновременно с `GenerateFollowupJob`.
- `MeetingSummaryGenerated`-listener'ы тоже идут паралелльно.
- Возможна гонка: `VerifyMeetingArtifactsJob` стартанёт раньше, чем `ExtractDecisionsAfterSummary` дописал Decision'ы.

**Решение:** либо (а) после Phase 1 цепочку выстроить через `Bus::chain([...])`, либо (б) внутри `VerifyMeetingArtifactsJob` ретраить, если `Decision`'ы ещё не созданы (и summary в `IN_PROGRESS`).

Это надо явно описать в техзадании Phase 1.

#### Тесты после перевода

Все тесты, которые запускают `TranscriptParsed::dispatch` или job'ы и потом проверяют записи в БД — могут упасть, потому что queued listener'ы не сработают синхронно. Стандартный ответ — `Bus::fake` + `Queue::fake` или `Event::fake` и ассертим по-другому. Это надо учесть в Phase 0.

### 5.4 Telegram-уведомления

При переводе `SendMeetingSummaryNotification` / `SendMeetingReviewNotification` / `SendMeetingTasksNotification` на queued:

- Если Telegram API недоступен → retry. После `tries=2` — упадёт.
- Failed jobs → нужен `failed_jobs` мониторинг или хук в `failed()` для алёрта.
- Идемпотентность: повторный `SendMeetingSummaryNotification` (после retry) не должен слать сообщение дважды. Сейчас — проверка `sent_at`? Нет, sent_at у `MeetingAgenda`, а у `MeetingSummary` — нет. Добавить флаг `summary_sent_at` или таблицу `notifications_sent` с дедуп-ключом.

### 5.5 Что НЕ делаем в Phase 1

- НЕ распиливаем AgendaService.
- НЕ выносим промпты.
- НЕ удаляем mead code (`DispatchAgentTasksForIssues`, `MeetingTaskController@generate`).
- НЕ меняем поведение Insight (он уже queued).
- НЕ объединяем notifier'ы.
- НЕ объединяем старый/новый путь задач.

Только перевод листенеров и фикс возможных race-conditions.

### 5.6 Критерии успеха Phase 1

- Все Phase 0 тесты по-прежнему зелёные (с обновлением на `Queue::fake`/`Bus::fake`).
- Симуляция падения LLM в одном listener (например, MeetingReviewService) НЕ ломает остальных (Summary/Decisions/Followup пройдут).
- Job retries отрабатывают: `tries=3` с фейлами на 2 первых попытках → 3-я успешная.
- Failed jobs логируются (`failed_jobs` table или sentry-хук).

---

## 6. Целевая архитектура (ADR-эскизы)

Это **видение на потом**, не план Phase 1. Каждый блок — на отдельную сессию проектирования и обсуждения.

### ADR-1: TranscriptPipeline + Stages

Заменить web of listeners на явный пайплайн:

```php
// Концептуально:
class TranscriptPipeline
{
    /** @var array<TranscriptStage> */
    private array $stages;

    public function run(CalendarEvent $event, ParsedTranscript $transcript): PipelineResult
    {
        $context = new PipelineContext($event, $transcript);

        foreach ($this->stages as $stage) {
            try {
                $stage->run($context);
            } catch (\Throwable $e) {
                $context->recordFailure($stage, $e);
                if ($stage->isCritical()) throw $e;
            }
        }

        return $context->result();
    }
}

interface TranscriptStage
{
    public function name(): string;
    public function isCritical(): bool;
    public function shouldRun(PipelineContext $ctx): bool;
    public function run(PipelineContext $ctx): void;
}
```

Stages (черновой список):
- `MeetingSummaryStage` (critical: false, но нужен дальше)
- `MeetingReviewStage`
- `DecisionsExtractionStage` (depends on summary)
- `RepeatedDiscussionsStage` (depends on summary)
- `MeetingSeriesStateStage` (depends on summary)
- `IssueExtractionStage`
- `IssueMergeStage` (depends on extraction)
- `DecisionsToIssuesLinkingStage` (depends on both)
- `IncompleteIssuesNotificationStage`
- `UpcomingAgendaStage`
- `FollowupStages` (per team)
- `InsightExtractionStage`
- `InsightEvolutionStage`
- `InsightRelationshipStage`
- `NotificationsStage`

Каждый Stage = один Job (queued, retryable). `run()` идёт через job-orchestrator (`Bus::chain` для зависимостей, параллельные через `Bus::dispatch`).

**Плюсы:**
- Явный граф зависимостей.
- Stage's `isCritical` управляет, ломаем ли всё или продолжаем.
- Тестируемо: каждый Stage с моками.
- Observability: PipelineContext → AgentActivityLog централизованно.

**Минусы:**
- Большой рефакторинг.
- Замена событий на orchestrator меняет mental model.

### ADR-2: Prompt-слой

```php
namespace App\Domain\Prompts;

interface PromptInterface
{
    public function build(array $context): string;
    public function model(): string;
    public function maxTokens(): int;
}

namespace App\Domain\Prompts\Transcript;

final class MeetingSummaryPrompt implements PromptInterface
{
    public function build(array $ctx): string { /* heredoc, с {transcript}, {meetingDate} */ }
    public function model(): string { return Setting::get('model.meeting_summary', config('ai.providers.openrouter.models.meeting_summary')); }
    public function maxTokens(): int { return 4096; }
}
```

Сервисы становятся тонкими:

```php
class MeetingSummaryService
{
    public function __construct(
        private readonly LlmGateway $llm,
        private readonly MeetingSummaryPrompt $prompt,
        private readonly TranscriptBuilderService $transcriptBuilder,
    ) {}

    public function generate(CalendarEvent $event): MeetingSummary
    {
        $transcript = $this->transcriptBuilder->build($event);
        $rendered = $this->prompt->build([
            'transcript' => $transcript,
            'meetingDate' => $event->starts_at,
        ]);
        $json = $this->llm->chatJson($rendered, model: $this->prompt->model(), maxTokens: $this->prompt->maxTokens());
        // ... persist
    }
}
```

**Плюсы:**
- Промпты тестируются отдельно (`assertStringContainsString`).
- Можно легко A/B-тестировать версии.
- Версионирование явное (`MeetingSummaryPromptV2`).

### ADR-3: `LlmGateway` (замена прямого `OpenRouterClient::chat`)

```php
interface LlmGateway
{
    public function chatJson(string $prompt, string $model, int $maxTokens): string;
    public function chat(array $messages, string $model, int $maxTokens, bool $forceJson = false): string;
}
```

Реализация — `OpenRouterLlmGateway`. Единый паттерн (не static), DI везде. Тестируется заменой бинда в контейнере.

### ADR-4: TranscriptCache / Передача транскрипта по цепочке

Вариант A: Cache.

```php
class TranscriptBuilderService
{
    public function build(CalendarEvent $event): string
    {
        return Cache::remember(
            "transcript:event:{$event->id}",
            now()->addHour(),
            fn() => $this->buildFromDb($event)
        );
    }
}
```

Сброс из `ParseTranscriptJob::handle()` (`Cache::forget`).

Вариант B: ParsedTranscript DTO в pipeline-контексте, прокидывается по job'ам.

### ADR-5: `CalendarEventUserResolver`

```php
namespace App\Services\Calendar;

class CalendarEventUserResolver
{
    public function attendees(CalendarEvent $event): Collection;     // union всех путей
    public function organizer(CalendarEvent $event): ?User;
    public function teams(CalendarEvent $event): Collection;
    public function teamMembers(CalendarEvent $event, ?Team $team = null): Collection;
}
```

Все 4+ места, использующие свой набор whereHas, переключаются на этот сервис.

### ADR-6: Унификация manual/auto путей задач

После Phase 0 + Phase 1:

1. Удалить роут `POST /tasks/generate` (никем не вызывается).
2. Унифицировать `MeetingTaskService` и `IssueExtractionService` через `TaskExtractionStrategy` (или просто переиспользование `IssueExtractionService::extract` в `ProcessDemoEventJob`).
3. Унифицировать события: всегда `IssuesExtracted`. Listener'ы `LinkDecisionsAfterTasksExtracted` / `NotifyCalendarOwnerAboutCreatedTasks` / `SendMeetingTasksNotification` подписать на `IssuesExtracted` либо удалить, если их обязанности дубликат с production-флоу.
4. Удалить `LinkDecisionsToIssuesService` (его делает `VerifyMeetingArtifactsJob`).

### ADR-7: Observability и AgentActivityLog

Сейчас каждый сервис кидает `AgentActivityLog::recordActivity(...)` со своими `toolName`. Унифицировать в `PipelineContext::observe(stage, result)` — пишет AgentActivityLog централизованно, с pipeline_run_id.

Добавить таблицу `transcript_pipeline_runs` (`calendar_event_id`, `status`, `stages_succeeded`, `stages_failed`, `started_at`, `finished_at`, `error_summary`) — observability на уровне «обработался ли транскрипт целиком».

### ADR-8: Нейминг и расположение

- `app/Services/Followup/TranscriptBuilderService` → `app/Services/Transcript/TranscriptBuilder`.
- `MeetingSummaryService`, `MeetingReviewService` → `app/Services/Meeting/` остаются.
- `IssueExtractionService` (root) → `app/Services/Issue/IssueExtractor`.
- `GenerateUpcomingAgendaJob` → `PrepareNextMeetingFollowupJob`.

### ADR-9: Idempotency keys

- `InsightSource` уже имеет уникальный triple (profile_id, source_type, source_id) — но логика persist всё равно может задвоить items. Добавить uniqueness на (insight_source_id, fact_hash) — миграция.
- `decision_issue` — уникальный (decision_id, issue_id) — миграция.

---

## 7. План фаз

| Phase | Объём | Что включает | Риск | Готово, если |
|---|---|---|---|---|
| **0** | 1–2 недели | Регрессионная сеть тестов (раздел 4) | Низкий | Все тесты зелёные, фабрики поддерживают сценарии, моки готовы |
| **1** | 3–5 дней | Queued listeners (раздел 5) | Средний (race conditions) | LLM-сбой в одном этапе не ломает остальные, retry работает |
| **2** | 1 неделя | Удаление dead code (DispatchAgentTasksForIssues, route /tasks/generate, дубликаты), унификация Telegram-нотификаторов | Низкий | -1 листенер, -1 роут, -2 копипаст telegram |
| **3** | 2–3 недели | Распил `AgendaService` на 5+ классов, тесты на каждый | Средний | Каждая обязанность в своём классе с тестами |
| **4** | 1–2 недели | Prompt-слой (ADR-2) + LlmGateway (ADR-3) | Средний | Все промпты — отдельные классы, все вызовы через gateway |
| **5** | 2–3 недели | TranscriptPipeline + Stages (ADR-1) | Высокий | Web of listeners заменён на pipeline, observability on |
| **6** | 1 неделя | Унификация задач, удаление MeetingTaskService production-route, миграция demo на единый pipeline | Средний | Один путь извлечения задач, demo через тот же сервис |
| **7** | 1 неделя | Idempotency / транзакции (ADR-9) | Средний | Re-parse не дублирует данные, частичные сбои откатываются |

**Phase 0 и 1 — единственное, что предлагается делать сейчас.** Остальное — материал для отдельных обсуждений и сессий.

---

## 8. Открытые вопросы

Их надо проговорить отдельно. Не делаем решений в этом документе.

### 8.1 Демо: оставлять ли свой путь?

`MeetingTaskService` живёт ради demo (`ProcessDemoEventJob`). Альтернатива — переключить demo на `IssueExtractionService` + `IssueMergeService`. Но в demo:

- Нет «существующих issues» для merge.
- `MeetingTaskService::participantMatcher->match()` — он создаёт мэппинг `participant.profile_id`, что использует `IssueMergeService` через `event->profiles`.
- Demo генерит участников через генерации (см. CLAUDE.md memory) и явно расставляет profile_id.

**Вопрос:** оставить demo на старом пути (как «тестовая полоса»), или переключить на единый production-флоу с фейковым LLM-ответом? Последнее даёт уверенность, что demo-тесты ловят регрессии production.

### 8.2 Разные модели для разных задач?

Сейчас все задачи на gemini-3-pro-preview. Конфиг это позволяет. Возможно, для merge / linking подойдёт haiku (дешевле и быстрее), для summary — opus (качественнее). На сейчас — пренебречь, но в плане архитектуры зафиксировать, что модель — это конфиг каждого Stage, не глобальная константа.

### 8.3 Sync-точка для финальной нотификации

Сейчас `SendMeetingSummaryNotification` и `SendMeetingReviewNotification` шлют в Telegram отдельно. После пайплайна логично — один **сводный** Telegram-сводник: «По встрече X готово: Summary, Review, X задач, Y решений». Это вопрос UX, но при перепроектировании пайплайна сюда же.

### 8.4 `MeetingSeriesState` коллизии

Нужна верификация в коде — как именно строится `series_identifier`. Если по title — потенциальная коллизия. Если по `series_key`/`recurrence_id` календаря — ОК.

### 8.5 `Decision::issues()` relation сломан

Известный баг (CLAUDE.md memory). Пора чинить, потому что любой рефакторинг, который ходит через decisions↔issues, упрётся в обходы через `DB::table`.

### 8.6 `RegenerateFollowupJob` vs идемпотентность

Сейчас он создаёт **новый** `Followup` каждый раз. То есть если пользователь нажал «регенерировать» 5 раз — у него 5 followup'ов. Это либо фича (история версий), либо баг — обсудить.

### 8.7 Ошибки в `agenda_analysis`

Поле `MeetingReview.agenda_analysis` — `had_clear_agenda`, `discussed_topics`, `unplanned_topics`, `missed_topics`, `summary`. Промпт требует `description = null → пустые missed_topics`. Это пограничная логика, легко может сломаться при правке промпта.

### 8.8 Insight / Profile

Все three Insight-сервиса (`Extraction`, `Evolution`, `Relationship`) тесно связаны с `Profile`. У этого пайплайна свой жизненный цикл и в production он уже queued. Можно оставить как есть и не лезть.

### 8.9 `AgendaContext` vs `PipelineContext`

В `AgendaService` есть DTO `AgendaContext`. Если в ADR-1 появится `PipelineContext`, надо решить, как они соотносятся: разные сущности или AgendaContext — частный случай PipelineContext'а.

### 8.10 `MeetingTaskController` имя

Если оставляем GET-эндпоинты `/tasks` (фронтенд использует), а POST `/tasks/generate` удаляем — стоит переименовать контроллер в `IssueController` для консистентности с Issue model. Но это пробивает API-схему фронта.

---

## 9. Research Insights (multi-agent review)

Этот раздел добавлен после первого черновика плана как результат прогона 8 параллельных агентов. Содержит: фактологические поправки, два контрастных взгляда на стратегию, новые проблемы (C-7, H-10..H-13, M-7..M-11, L-5..L-8), уточнения по фазам, конкретные quick wins, паттерны которые надо сохранить, и ссылки на актуальные источники.

---

### 9.1 Фактологические поправки

| Что в v1 плана | Действительность |
|---|---|
| «QUEUE_CONNECTION=sync (см. CLAUDE.md memory)» в 5.2 | `.env` (prod): `redis`. Sync только в `.env.testing`. Race conditions из 5.3 — already exists |
| «Pattern A — через инжектированный экземпляр» в 2.5 | `OpenRouterClient::chat` объявлен `public static`. Инстанс-вызовы работают только потому, что PHP это позволяет — это техдолг (C-5), а не легитимный паттерн |
| «Где именно диспатчится `IssuesExtracted`? — нужно проверить» (Приложение C) | [`ExtractIssuesFromTranscriptJob:28`](../app/Jobs/ExtractIssuesFromTranscriptJob.php). И ещё `Console/Commands/TestIssueExtractionPipeline:65`. Из сервиса (`IssueExtractionService::extract`, `IssueMergeService::persist`) — НЕ диспатчится |
| «18-30 LLM-вызовов на встречу» в 2.5 | **35-47 вызовов** (был занижен Insight-блок: Evolution × 6 категорий × 4 участника = 24, Relationship × 2 на пару × 6 пар = 12). Раздел 9.6 |
| «JSON-extract regex в 5+ файлах» (M-3) | **12 копий в production-коде** + 2 в Demo. Семантика тонко расходится между копиями. Раздел 9.4 (H-13) |
| «Telegram нотификаторы дубликат — 6 листенеров» (H-7) | **8+ копий** инициализации Telegram бота с похожим скелетом. Раздел 9.4 (H-7 расширен) |
| «`ParticipantProfileMatchingService` используется в `MeetingTaskService`» в 2.4 | **В production-флоу matching не вызывается вообще** — `IssueMergeService::resolveAssigneeId` полагается только на нечёткий name-matcher через `team->users`. Это серьёзнее, чем «дубликат путей» |
| «`Decision::issues()` relation сломан — обходить через DB::table» (memory) | Конкретная причина: `withTimestamps(['created_at', null])` передаёт массив там, где Laravel ждёт строку. **Однострочный фикс** — `withPivot('created_at')` |

---

### 9.2 Два контрастных взгляда — что выбрать

#### Architecture-strategist: «корневая проблема — нет владельца»

Девять C-проблем в каталоге — симптомы одного: *«нет владельца у фактической операции "обработка транскрипта"»*. Каждая задача распределена по случайным listener'ам, и нет места, где видно «вот эти 7 артефактов должны существовать после обработки события X».

Из этого:
- C-1 (два пути задач) — потому что некому сказать «уже сделано»
- C-3 (повтор удваивает) — нет orchestrator'а с понятием run_id
- C-4 (неконсистентная транзакционность) — каждый сервис решает сам
- C-6 (Decision на каждую team) — `ExtractDecisionsService` не знает, как читатель будет потреблять, пишет «на всякий случай»
- H-3 (5x чтений транскрипта) — никто не координирует
- H-4 (4 способа найти участников) — нет места истины

**Вывод**: ADR-1 (TranscriptPipeline + Stages) — это и есть единственное реальное лекарство. Phase 0–4 — медленный путь к Pipeline.

#### Code-simplicity-reviewer: «план — over-engineering, делать минимум»

План на 1705 строк решает проблемы, которые на 80% чинятся **тремя строчками `implements ShouldQueue`**. ADR-1 (15 Stage-классов + PipelineContext + orchestrator) — замена 17 listener'ов на 17 Stage'ов с дополнительным каркасом сверху, **выгода ноль**: events + ShouldQueue уже дают graph dependencies.

Программа минимум:
- **Спринт 1 (1-2 дня)**: C-3 фикс (`delete()` перед `create` в Insight + `updateOrCreate` в Participant), C-5 (static→instance), M-2 (огромный лог), удалить `DispatchAgentTasksForIssues.php`, удалить роут `POST /tasks/generate`.
- **Спринт 2 (2-3 дня)**: 7 listener'ов → `ShouldQueue` с retry+backoff, `release(30)` в `VerifyMeetingArtifactsJob`, sentry-хук в `failed()`, 5 регрессионных тестов.
- **Спринт 3 (опционально)**: `Cache::remember` для транскрипта, `IssueMergeServiceTest` (5-6 кейсов).

Что выкинуть: ADR-1, ADR-7, ADR-9, Phase 3 (распил AgendaService), Phase 6 (унификация demo). Возвращаемся когда появится конкретная боль.

#### Что я думаю (на основе обоих)

Они оба правы по-своему — на разных временных горизонтах:

- **Сейчас (1-2 спринта)** — simplicity-reviewer прав. ADR-1/Pipeline — это слишком большой шаг при отсутствии регрессионной сетки и при `OpenRouterClient::chat` static. Сначала надо просто залатать и стабилизировать.
- **На горизонте 6+ месяцев** — architecture-strategist прав. Если планируется добавлять новые типы артефактов (а в backend есть memory-architecture-tz планы — добавляются inсайты, отношения, паттерны), web of listeners будет расти и каждое изменение начнёт ломать смежные.

**Предлагаю компромисс:**
1. Phase 0 + 1 делаем **по программе simplicity-reviewer** (минимально, но с обязательными элементами architecture-strategist'а: rate-limiting, idempotency-таблица, ShouldQueueAfterCommit, expireAfter).
2. После Phase 1 — реальная пауза: 2-3 месяца наблюдения за async-поведением в проде. Если выявится конкретный класс багов «нет координатора» — переходим к Phase 5 (Pipeline). Если нет — оставляем event-driven навсегда.
3. ADR-1 в плане остаётся **как контингентный вариант**, не как обязательный шаг. Это снимает перекос.

---

### 9.3 Pre-Phase 0 — обязательная подготовка

Без этих шагов Phase 0 «строится на песке» (architecture-strategist):

#### 9.3.1 SQL-аудит текущих данных

Прогнать в prod (read-only) **до** любых правок:

```sql
-- Дубликаты Insight items (C-3)
SELECT insight_source_id, category, fact, COUNT(*) AS dup_count
FROM insight_items
GROUP BY insight_source_id, category, fact
HAVING COUNT(*) > 1
ORDER BY dup_count DESC LIMIT 50;

-- Сколько лишних строк
SELECT SUM(dup_count - 1) AS rows_to_delete FROM (
    SELECT COUNT(*) AS dup_count FROM insight_items
    GROUP BY insight_source_id, category, fact HAVING COUNT(*) > 1
) sub;

-- Длина самого длинного fact (для index-планирования)
SELECT MAX(LENGTH(fact)) AS max_fact_length FROM insight_items;

-- Decisions per team copies (C-6)
SELECT calendar_event_id, summary_id, text, COUNT(DISTINCT team_id) AS team_copies
FROM decisions WHERE summary_id IS NOT NULL
GROUP BY calendar_event_id, summary_id, text
HAVING COUNT(DISTINCT team_id) > 1
ORDER BY team_copies DESC LIMIT 30;

-- Insight items на один source (детектор накопления от retry)
SELECT s.id, s.profile_id, s.source_id, COUNT(i.id) AS items_count
FROM insight_sources s
LEFT JOIN insight_items i ON i.insight_source_id = s.id
GROUP BY s.id, s.profile_id, s.source_id
HAVING COUNT(i.id) > 30
ORDER BY items_count DESC LIMIT 20;

-- decision_issue ↔ Decision::issues() relation проверка
SELECT COUNT(*) FROM decision_issue;
-- Сравнить с Eloquent: Decision::with('issues')->get()->sum(fn($d) => $d->issues->count())
-- Если расходятся — relation возвращает кривое (вероятнее, double join)
```

Если `rows_to_delete > 0` или `team_copies > 1` — это **инциденты данных**, требующие cleanup-миграции до Phase 1.

#### 9.3.2 Фикс `OpenRouterClient::chat` на инстанс

Это формально C-5, но он **блокирует** написание тестов для `ExtractDecisionsService` и `VerifyMeetingArtifactsJob` (там static-вызовы, не интерсептятся через `$this->mock`). 30-минутная правка (3 точки):
- [`app/Services/OpenRouterClient.php:18`](../app/Services/OpenRouterClient.php) — `public static function chat` → `public function chat`.
- [`app/Services/Decisions/ExtractDecisionsService.php:120`](../app/Services/Decisions/ExtractDecisionsService.php) — `OpenRouterClient::chat(...)` → `$this->llm->chat(...)`.
- [`app/Jobs/VerifyMeetingArtifactsJob.php:236`](../app/Jobs/VerifyMeetingArtifactsJob.php) — то же.

Это часть тестовой инфраструктуры, делать в pre-Phase, не в Phase 4.

#### 9.3.3 Grep на Eloquent model events

```bash
grep -rn "static::saved\|static::created\|static::updated\|static::saving\|static::deleting" app/Models/
```

Если в моделях есть `boot()` listener'ы — они могут дублировать поведение event-listener'ов. При переходе на queued это станет двойным триггером. Например, `MeetingSummary::saved → reactToSummary()` параллельно с `MeetingSummaryGenerated::dispatch + listener'ы` = двойная работа.

#### 9.3.4 Grep на dispatchSync

```bash
grep -rn "::dispatchSync(" app/
```

`dispatchSync` — это места, где автор кода знал про async-модель и **обходил её** (т.е. хотел синхронной семантики). При переходе на queued эти точки могут создать неожиданные эффекты.

#### 9.3.5 Cost baseline

Зафиксировать текущий $/мес расход на OpenRouter (из их dashboard) и текущее число встреч в неделю/день. Без этого нельзя измерить успех Phase 4 (mergeable LLM calls) и P2-даунгрейда моделей.

---

### 9.4 Новые проблемы (сверх каталога раздела 3)

#### C-7. `askLlmForCoverage` — прямой дубль алгоритма

**Симптом:** идентичный promp + LLM-вызов в двух местах:
- [`app/Services/Decisions/LinkDecisionsToIssuesService.php:68-117`](../app/Services/Decisions/LinkDecisionsToIssuesService.php) — поддерживает массив `covering_issue_ids[]`.
- [`app/Jobs/VerifyMeetingArtifactsJob.php:204-266`](../app/Jobs/VerifyMeetingArtifactsJob.php) — ждёт `issue_id` (одно покрытие или null).

Одинаковые `decisionsList`/`issuesList` форматирования (`sprintf("id=%d topic=%s text=%s", ...)`), одна и та же модель `model.meeting_tasks`, одинаковый JSON-формат, текстовые отличия в формулировках («Открытые задачи команды» vs «Задачи (issues)»).

**Последствия:** Demo-флоу (через `LinkDecisionsAfterTasksExtracted` listener) и production (через `VerifyMeetingArtifactsJob`) дают **разные результаты** на одних данных. При расширении логики (например, «частичное покрытие») придётся править два промпта в синхроне — кто-нибудь забудет.

**Решение:** один сервис `DecisionsToIssuesLinker` с двумя методами (`linkCovering` для нескольких issues, `linkSingle` для одного). VerifyMeetingArtifactsJob и LinkDecisionsAfterTasksExtracted дёргают его.

#### H-10. `try / Log::error / status FAILED` повторяется в 16+ местах

**Симптом:** один и тот же скелет:
```php
try {
    $artifact->update(['status' => Status::IN_PROGRESS->value]);
    $json = $this->llm->chat(...);
    if (!preg_match('/\{[\s\S]*\}/s', $json, $matches)) { /* fallback */ }
    $data = json_decode($matches[0] ?? $json, true);
    $artifact->update(['status' => Status::DONE->value, /* ...fields... */]);
    Event::dispatch(new XGenerated($artifact));
} catch (\Throwable $e) {
    Log::error('XService: failed', ['error' => $e->getMessage()]);
    $artifact->update(['status' => Status::FAILED->value]);
}
```

Сайты (16+ из `pattern-recognition` агента): `MeetingSummaryService`, `MeetingReviewService`, `MeetingTaskService`, `FollowupService`, `IssueExtractionService`, `IssueMergeService`, `UpcomingAgendaService`, `AgendaService` (×2 случая — general + personal), `MeetingSeriesStateService`, `DetectRepeatedDiscussionsService`, `ExtractDecisionsService`, `LinkDecisionsToIssuesService`, `VerifyMeetingArtifactsJob`, `InsightExtractionService`, `InsightEvolutionService`, `InsightRelationshipService` (×2), `ParticipantProfileMatchingService`.

**Решение (Phase 4):** `LlmArtifactRunner` trait или sealed class:
```php
// Концепт:
LlmArtifactRunner::run(
    artifact: $summary,
    llm: fn() => $this->llm->chat([...]),
    parse: fn($raw) => JsonResponseExtractor::extract($raw),
    persist: fn($data) => $summary->update([...]),
    onSuccess: fn() => MeetingSummaryGenerated::dispatch($summary->fresh()),
);
```
Единая semantics: try/catch, status flow, JSON extraction, лог. Сэкономит ~30 строк на каждый сайт = ~500 строк кода.

#### H-11. Три независимых name-matcher с **хардкодом сотрудников Vanda**

**Симптом:**

| Реализация | Файл:строка | Семантика |
|---|---|---|
| `nameMatches` | [`IssueMergeService.php:258-265`](../app/Services/IssueMergeService.php) | substring оба направления, lowercase |
| `namesMatch` + `normalize` | [`Decisions/DecisionAuthorResolver.php:106-128`](../app/Services/Decisions/DecisionAuthorResolver.php) | равенство первых частей имени, минимум 3 символа |
| `getNameVariants` | [`Agenda/AgendaService.php:666-700`](../app/Services/Agenda/AgendaService.php) | **хардкод-словарь Vanda сотрудников**: Борис→boris, Иван→ivan, Виктор→витя, Фёдор→жерновой |
| `matchGlobalUserByName` | `DecisionAuthorResolver.php:95-104` | четвёртый алгоритм |

**Самое опасное** — `getNameVariants` встроил в код имена конкретных сотрудников Vanda. **Это не масштабируется на нового клиента.** Если к платформе подключится другая компания — диминутивы их сотрудников не будут учтены.

**Решение:** общий `NameMatcher` сервис c приоритезацией стратегий: exact → email-equivalent → first-name → diminutive (через локализованный словарь в БД, не hardcoded). Включить в Phase 3 или 4.

#### H-12. Listeners — service locator вместо DI, непоследовательно

**Симптом:**

| Listener | Pattern |
|---|---|
| `GenerateMeetingSummary`, `GenerateMeetingReview`, Insight × 3 | `app(Service::class)->method(...)` (service locator) |
| `GenerateFollowup`, `ExtractDecisionsAfterSummary`, `DetectRepeatedDiscussions`, `LinkDecisionsAfterTasksExtracted` | DI через `__construct` |

При тестировании первый pattern требует `$this->bind(Service::class, $mock)`, второй — `$this->mock(Service::class, ...)`. Два разных подхода в одном кодбазе.

**Решение (Phase 1):** все через `__construct`. Cosmetic, но снимает «почему этот тест не работает как тот».

#### H-13. Промпт «semantic match» в 3 местах

**Симптом:** три промпта про «сравни новое с историческим, найди семантически совпадающее»:
- [`IssueMergeService::buildMergeSystemPrompt()`](../app/Services/IssueMergeService.php) (291-355) — issues vs existing.
- [`DetectRepeatedDiscussionsService::buildSystemPrompt()`](../app/Services/Meeting/DetectRepeatedDiscussionsService.php) (225-269) — decisions vs historical.
- [`MeetingReviewService::buildPrompt`](../app/Services/Meeting/MeetingReviewService.php) блок `previous_suggestions_check` (175-191) — suggestions vs historical.

Все три просят LLM «сравнивать по смыслу, не по словам» с примерами одного жанра.

**Решение (Phase 4):** общий промпт-шаблон `SemanticMatchPrompt` с параметрами (что сравнивается, какие правила).

#### M-7. Не для всех артефактов есть `<Artifact>Generated` событие

**Существующие:** `TranscriptParsed`, `MeetingSummaryGenerated`, `MeetingReviewGenerated`, `IssuesExtracted`, `MeetingTasksExtracted`, `InsightItemsExtracted`.

**Отсутствующие:** `FollowupGenerated`, `UpcomingAgendaGenerated`, `MeetingAgendaGenerated`, `MeetingSeriesStateUpdated`, `RepeatedDiscussionsDetected`, `DecisionsExtracted`.

Плюс **`IssuesExtracted` диспатчится из job'а, а не из сервиса** — антипаттерн (вызывающая сторона ответственна за событие). Если кто-то вызовет `IssueExtractionService::extract` напрямую — событие не сработает.

**Решение (Phase 5 или ADR-1):** канонический набор событий, диспатчатся из сервиса.

#### M-8. `Setting::get('model.X', config('ai...X'))` × 23 раза

**Симптом:** 23 копии одного выражения. При переименовании ключа в `config/ai.php` придётся править 23 места.

**Решение:** wrapper `LlmModel::for('meeting_summary')` или `Setting::aiModel('meeting_summary')`.

#### M-9. `MeetingAgenda.content` vs `raw_json` + рендереры — несимметричны

**Симптом:** [`AgendaService::renderGeneralContent`](../app/Services/Agenda/AgendaService.php) (private) рендерит **усечённый** flat-text (`discussion_topics`, `main_problem`). Этот текст пишется в `agenda.content` БД-колонку. **Полное представление** получается через static `renderForWeb` / `renderForTelegram` (которые включают `commitments_check`, `tasks_between`, `backlog_stats`).

То есть в БД лежит неполная версия — фронт рекомпилирует полную из `raw_json`. Тесты на «как выглядит agenda» зависят от того, какой рендерер взят.

**Решение (Phase 3):** или `agenda.content` всегда максимально полный, или его вообще не хранить — пусть фронт рендерит из `raw_json`.

#### M-10. `extractCommitmentsFromSummary` — потенциально dead code

[`AgendaService.php:565-614`](../app/Services/Agenda/AgendaService.php) парсит markdown-таблицу или COMMITMENTS-секцию из старого формата `summary.summary`. Но `MeetingSummaryService` с какого-то момента пишет structured `commitments` в JSON-блоб как массив `{who, what, deadline}`.

**Решение:** проверить, есть ли `MeetingSummary` без `commitments` json (старые встречи). Если нет — fallback можно удалять. Если есть — миграция, которая backfill'ит `commitments` из summary text.

#### M-11. `AgendaService::getBacklogStats` дублирует `IssueStatsService`

В проекте уже есть [`app/Services/IssueStatsService.php`](../app/Services/IssueStatsService.php). `AgendaService::getBacklogStats` (728-763) считает похожую статистику самостоятельно.

**Решение:** перевести на `IssueStatsService` — снимет ~35 строк.

#### L-5..L-8 (минорные)

- **L-5**: `MeetingTaskStatus` enum (`OPEN`, `DONE`, ...) используется моделью `Issue`. Семантический разнобой.
- **L-6**: глагол `extract` имеет разные return-типы (Collection / int / void) в разных сервисах.
- **L-7**: Listener naming chaos — `Send*` vs `Notify*` (одна задача, два имени), `ExtractDecisionsAfterSummary` суффикс `AfterSummary` единичный.
- **L-8**: В [`AgendaService.php`](../app/Services/Agenda/AgendaService.php) в одном файле смешаны `AgendaStatus::DONE` (строка 248) и `AgendaStatus::DONE->value` (строка 139). Не баг (Eloquent cast'ит), но непоследовательно.

---

### 9.5 Расширения и переоценка существующих C/H проблем

| Метка | Что в плане v1 | Что обнаружили |
|---|---|---|
| **C-3** (re-parsing удваивает Insight) | «реалистично в queued environment» | **На проде уже могут быть тысячи дубликатов** — нужен SQL-аудит до фикса (см. 9.3.1). Это не «фикс на потом», а инцидент данных |
| **C-4** (транзакционность) | мягко описана | `IssueMergeService::applyDecisions` выполняет до N issues + N IssueComment + N updates без транзакции. Реалистичный сценарий: deadlock на IssueComment после успешного Issue update → assignee изменён, но без комментария о причине. Snowflake bug |
| **C-5** (static vs instance LLM) | помечена Critical | Реально это **Medium/High** для прода (не влияет на behavior), но **Critical для тестов** — блокирует мокирование. Переосмыслить в порядке Phase 0 |
| **C-6** (Decision per team) | «странный дизайн» | На самом деле **утечка решений между командами**: `VerifyMeetingArtifactsJob.gapFillUncoveredDecisions` берёт ALL копий, делает по LLM-вызову на каждую (10 копий = 10 вызовов) и создаёт issues для команды из job'а на чужой decision |
| **H-7** (Telegram нотификации) | «6 копий, унификация даёт 40 строк» | **8+ копий**. Плюс **в queued environment retry создаст дубли в Telegram чатах** → переходит в C при переходе на queued (Phase 1). Нужен `meeting_notifications_sent` table с unique-ключом до Phase 1, не во время |
| **H-8** (DispatchAgentTasksForIssues) | «Phase 2 удалить» | Удалить файл — это однострочник, не Phase. Делать в pre-Phase |
| **L-3** (имена job'ов) | «переименовать» | Вычеркнуть. Косметика без выгоды |
| **M-3** (JSON regex дубль) | «5+ файлов, helper» | **12 файлов, semantic divergence** между копиями (см. 9.4 H-13). Стало Medium/High |
| **C-1** (старый/новый путь задач) | «дубликат, унифицировать» | **Главная проблема внутри** — `ParticipantProfileMatchingService` НЕ вызывается в production. Это значит, что в проде `participant.profile_id` ≈ всегда null, и assignee матчится только через нечёткий `nameMatches` — который substring оба направления (Tim ≈ Timochovsky). Это серьёзнее, чем просто «два пути» |

---

### 9.6 LLM cost & latency reality check

Точный пересчёт по коду (performance-oracle):

#### Новый bill of LLM calls (per meeting, 1 team, 4 attendees)

| Источник | Файл:строка | Calls | Модель | maxTokens |
|---|---|---:|---|---:|
| `MeetingSummaryService::generate` | [`Services/Meeting/MeetingSummaryService.php:37`](../app/Services/Meeting/MeetingSummaryService.php) | 1 | meeting_summary | 4096 |
| `MeetingReviewService::generate` | [`Services/Meeting/MeetingReviewService.php:43`](../app/Services/Meeting/MeetingReviewService.php) | 1 | meeting_review | 8192 |
| `ExtractDecisionsService::enrichWithAuthors` | [`Services/Decisions/ExtractDecisionsService.php:120`](../app/Services/Decisions/ExtractDecisionsService.php) | 1 | meeting_summary | 2048 |
| `DetectRepeatedDiscussionsService::callLlm` | [`Services/Meeting/DetectRepeatedDiscussionsService.php:158`](../app/Services/Meeting/DetectRepeatedDiscussionsService.php) | 1/team | meeting_summary | 2048 |
| `MeetingSeriesStateService::updateAfterMeeting` | [`Services/Agenda/MeetingSeriesStateService.php:25`](../app/Services/Agenda/MeetingSeriesStateService.php) | 1 | meeting_summary | 4096 |
| `FollowupService::generateContent` | [`Services/Followup/FollowupService.php:99`](../app/Services/Followup/FollowupService.php) | 1/team | followup | 8192 |
| `IssueExtractionService::extract` | [`Services/IssueExtractionService.php:44`](../app/Services/IssueExtractionService.php) | 1/team | followup | 4096 |
| `IssueMergeService::getDecisions` | [`Services/IssueMergeService.php:81`](../app/Services/IssueMergeService.php) | 0–1/team | followup | 4096 |
| `VerifyMeetingArtifactsJob::askLlmForCoverage` | `Jobs/VerifyMeetingArtifactsJob.php:236` | 0–1 | meeting_tasks | 2048 |
| `UpcomingAgendaService::generateForUser` | `Services/Agenda/UpcomingAgendaService.php:86` | **N=4 (на user)** | agenda | 8192 |
| `InsightExtractionService::callLLM` | `Services/Insight/InsightExtractionService.php:109` | 1 | insight | 4096 |
| **`InsightEvolutionService::callLLM`** | `Services/Insight/InsightEvolutionService.php:133` | **до 6×4 = 24** (категории × участники) | insight | 2048 |
| **`InsightRelationshipService::extractPairObservation`** | `Services/Insight/InsightRelationshipService.php:157` | **C(4,2)=6** | insight | 2048 |
| **`InsightRelationshipService::callEvolutionLLM`** | `Services/Insight/InsightRelationshipService.php:197` | до 6 | insight | 1024 |

**Итого per meeting (1 team, 4 attendees):**
- **Min (cold start):** ~35 calls
- **Realistic (warm):** ~30 calls
- **Worst (3 teams):** ~42-47 calls

#### Cost / latency

Прайс OpenRouter `google/gemini-2.5-pro`: input ≈ $1.25/1M tok, output ≈ $5/1M tok.

- **Input ≈ 260k tokens/встречу** (6 промптов × 30k транскрипта + остальные)
- **Output ≈ 80k tokens/встречу**
- **Cost ≈ $0.73 на встречу**
- **100 встреч/день → $2.2k/мес**, 1000/день → $22k/мес

**Wall-clock в queue=sync (объясняет timeout-боль из CLAUDE.md):**
- Sync-цепь (то, что блокирует ParseTranscriptJob worker): **45-65 sec**
- Если QUEUE_CONNECTION=sync (как dev): **5-6 минут на встречу** — структурно, отсюда зомби-процессы.

**В реальном queued env (prod):**
- Summary видит пользователь через 12-18 сек.
- Review через 30-45 сек.
- Insight evolution-блок (24 calls × 5s) тянет ещё ~120 сек в фоне.

#### Mergeable LLM calls (Phase 4)

| Слияние | Сейчас | Можно | Экономия |
|---|---:|---|---:|
| Summary + Decisions enrich + RepeatedDisc | 3 calls | 1 call с JSON-секциями | ~30k×2 input, 2 ходки |
| IssueExtraction + IssueMerge::getDecisions | 2 calls | 1 call (chain-of-thought) | ~30k input, 1 ходка |
| InsightEvolution × 6 категорий | 6 calls/user | 1 call с массивом категорий | 5×2k tokens, 5×5sec = **25 sec/user × 4 users = ~100 sec wall-clock** |
| InsightRelationship pair × 2 calls | 12 calls | 6 calls (один JSON со всем) | 6 ходок |

**Самое выгодное — InsightEvolution.** Сейчас 24 call/встречу × 5 сек = 120 сек чистого wall-clock. С merging: ~5-8 сек. **Экономия ~110 сек + $0.10/встречу.**

#### Cheap-model candidates (Flash/Haiku)

Кандидаты на даунгрейд (`gemini-flash` или `claude-haiku`, в 5-10x дешевле и в 2-3x быстрее):
- `IssueMergeService::getDecisions` — классификация create/update/skip
- `ExtractDecisionsService::enrichWithAuthors` — извлечь имя + 5-словную тему
- `DetectRepeatedDiscussionsService::callLlm` — семантическое сравнение пар (вообще embedding оптимальнее)
- `VerifyMeetingArtifactsJob::askLlmForCoverage` — linking decision→issue
- `UpcomingAgendaService::generateForUser` — простые списки 4-5 пунктов
- `InsightEvolutionService` — слияние JSON-фактов
- `InsightRelationshipService::callEvolutionLLM` — то же

**Должны остаться на pro/opus:** Summary, Review, Followup, IssueExtraction (definition «что задача»), MeetingSeriesState (fold).

**Экономия:** ~50% от bill (~$0.35-0.40/встречу saved).

#### Финальная числовая оценка

| Метрика | Сейчас | После P0+P1 | После P2 (merge+downgrade) |
|---|---:|---:|---:|
| LLM calls/meeting | ~30 | ~30 | **~12** |
| Cost/meeting | $0.73 | $0.73 | **$0.35** |
| Wall-clock (queued env) | ~60s | ~40s | **~25s** |
| Wall-clock (queue=sync, dev) | 5-6 min | 3 min | **~1 min** |

#### Critical для Phase 1: rate limiting

OpenRouter rate limit на `gemini-2.5-pro` ≈ **60 RPM**. При 5 параллельных встречах в момент после Phase 1 (5 × 30 calls / minute обработки) — **2.5x over limit → 429 storm**.

**Без `RateLimited` middleware Phase 1 увеличит cost+latency, не уменьшит** (jobs уйдут в retry, упрутся в backoff). Это must-have.

---

### 9.7 Phase 0 — уточнения

#### Что добавить (от architecture-strategist)

Список тестов из v1 — про happy/sad path. Не покрыта **главная регрессионная сетка**:

1. **«Один listener падает — остальные продолжают»** — самый важный тест Phase 1, должен быть написан **до** Phase 1, не после.
2. **«Re-parse того же транскрипта не задваивает данные»** — общий тест на CalendarEvent (не только Insight). Проверять Decision, Issue, MeetingSummary, MeetingReview, Followup.
3. **End-to-end smoke** — `ParseTranscriptJob → весь pipeline → ожидаемые записи в N таблицах`. Текущий v1 тест через `Queue::fake` проверяет только shape, не семантику.
4. **Contract-тесты** между событиями и listener'ами — `MeetingSummaryGenerated` несёт `$summary`. Если завтра в payload добавится null-able поле, listener'ы сломаются молча.
5. **Property-based тесты на `IssueMergeService::nameMatches`** — substring matcher с двумя сторонами выдаёт совпадение «Tim» ≈ «Timochovsky». 5 минут уверенности.

#### Что упростить (от code-simplicity-reviewer)

Программа v1 — 80-120 кейсов в 12 файлах. Реально нужно **15-20 кейсов в 5 файлах**:

1. `reentrant_extract_does_not_duplicate_items` — регрессия C-3 (Insight + Participant + Decision + Issue + Summary).
2. `failures_in_one_listener_do_not_break_other_listeners` — регрессия C-2.
3. `IssueMergeServiceTest` — 5-6 кейсов на nuanced логику.
4. `VerifyMeetingArtifactsJobTest` — 3 кейса (gap_fill, all_clear, summary_not_ready_releases).
5. **Для прочих сервисов (`MeetingSummaryService`, `MeetingReviewService`, `ExtractDecisionsService`, `MeetingSeriesStateService`, `DetectRepeatedDiscussionsService`)** — 2 кейса каждому: happy-path + LLM-error. Без `it_calls_llm_with_meeting_summary_model` (тестирует Laravel framework).

**Нельзя** делать тест на `extract_creates_one_decision_per_team_per_decision_text` (C-6) — это **зацементирует баг**. Этот тест не пишется до того, как C-6 будет решён или сознательно зафиксирован как design choice.

#### Стратегия мокирования OpenRouterClient

**С учётом C-5 фикса (см. 9.3.2)**: после static→instance, всё через `$this->mock(OpenRouterClient::class, fn($m) => $m->shouldReceive('chat')->andReturn(...))`.

**До C-5 фикса** — `Http::fake('openrouter.ai/*')`. Это работает уже сейчас, не требует трогать прод-код, и сразу даёт e2e-тесты.

**Three-tiered approach:**
1. **Unit (mock LlmGateway/OpenRouterClient)** — тест только сервиса, без БД и LLM.
2. **Listener-level (`Queue::fake` + `Bus::fake`)** — проверка диспатчей и shape pipeline.
3. **End-to-end (`Http::fake` + sync queue)** — тест полной цепи на реальной БД с замоканным LLM.

#### Concurrency model document — отдельно

Добавить 1-pager до Phase 0: «при 5 транскриптах одновременно, что должно быть гарантировано?». Это базовый вход для Phase 1 (rate-limiting, WithoutOverlapping).

---

### 9.8 Phase 1 — must-haves (расширение раздела 5)

Без этих пунктов Phase 1 создаст больше проблем, чем решит:

#### 9.8.1 ShouldQueueAfterCommit — не ShouldQueue

`ShouldQueueAfterCommit` (Laravel 10.30+) → `ShouldQueueAfterCommit`. Контракт ждёт коммита транзакции до диспатча.

**Альтернатива на уровне события:**
```php
class MeetingSummaryGenerated implements \Illuminate\Contracts\Events\ShouldDispatchAfterCommit {
    use Dispatchable, SerializesModels;
    public function __construct(public Summary $summary) {}
}
```
После этого `MeetingSummaryGenerated::dispatch($summary)` (без `->fresh()`, потому что `SerializesModels` сам перечитает).

**Альтернатива глобально:** в `config/queue.php`:
```php
'redis' => ['after_commit' => true, /* ... */],
```

#### 9.8.2 WithoutOverlapping → expireAfter(180) обязательно

Без `expireAfter()` lock зависает на kill -9 → MaxAttemptsExceededException на следующих ретраях. Применить к:
- `ParseTranscriptJob`: `(new WithoutOverlapping("parse:{$event->id}"))->expireAfter(300)->releaseAfter(60)`
- `RegenerateFollowupJob`: `(new WithoutOverlapping("followup:{$event->id}:{$team->id}"))->expireAfter(180)`
- `GenerateMeetingSummary` listener: то же.

#### 9.8.3 ThrottlesExceptions::failWhen(PermanentLlmException::class)

```php
public function middleware(): array {
    return [
        (new ThrottlesExceptions(maxAttempts: 5, decayMinutes: 10))
            ->by('openrouter:meeting_summary')
            ->when(fn(\Throwable $e) => $e instanceof TransientLlmException)
            ->failWhen(PermanentLlmException::class),  // permanent → стоп, не retry
    ];
}
```

`failWhen()` (Laravel 11+) — ключевой контракт: останавливает chain. Без него permanent ошибки тихо ретраятся 3 раза.

#### 9.8.4 Custom exception hierarchy

В pre-Phase или Phase 1:

```php
namespace App\Exceptions\Llm;

abstract class LlmException extends \RuntimeException {}

// Transient — retry
class TransientLlmException extends LlmException {}
class LlmRateLimitException extends TransientLlmException {
    public function __construct(public readonly ?int $retryAfterSeconds = null) { /* ... */ }
}
class LlmServerErrorException extends TransientLlmException {}
class LlmNetworkException extends TransientLlmException {}

// Permanent — fail
class PermanentLlmException extends LlmException {}
class LlmQuotaExhaustedException extends PermanentLlmException {}
class LlmInvalidRequestException extends PermanentLlmException {}
class LlmJsonParseException extends PermanentLlmException {}
```

`OpenRouterClient::chat` (после C-5 фикса) разбрасывает по этой иерархии. Listener middleware решает retry/fail на основе типа.

#### 9.8.5 RateLimited middleware — must-have для Phase 1

```php
// AppServiceProvider::boot()
RateLimiter::for('openrouter-llm', fn(\Illuminate\Queue\Job $job) => Limit::perMinute(40));
```

Все LLM-listener'ы получают `(new RateLimited('openrouter-llm'))->releaseAfter(30)`. **Без этого** Phase 1 при 5+ параллельных транскриптах = 429 storm от OpenRouter.

#### 9.8.6 enforceMorphMap для polymorphic Issue

```php
// AppServiceProvider::boot()
Relation::enforceMorphMap([
    'calendar_event' => \App\Models\CalendarEvent::class,
    'meeting' => \App\Models\Meeting::class,
]);
```

Без этого при queued unserialize event'а с polymorphic `$issue->sourceable` — если класс переименован между dispatch и обработкой, deserialize падает. Сейчас морф-map нет в `AppServiceProvider`.

#### 9.8.7 Idempotency для Telegram-нотификаций — pre-condition Phase 1

`Telegram\Bot\Api->sendMessage()` бросает RequestException → retry → **второе одинаковое сообщение в чат**. На 50 встреч/день и `tries=2` это 3-5 раз/неделю.

**Минимум:** новая таблица:
```sql
CREATE TABLE meeting_notifications_sent (
    id SERIAL PRIMARY KEY,
    calendar_event_id BIGINT NOT NULL REFERENCES calendar_events(id),
    kind VARCHAR(64) NOT NULL,  -- 'summary', 'review', 'tasks', 'agenda'
    target_type VARCHAR(64) NOT NULL,  -- 'telegram_chat' or 'user_telegram'
    target_id VARCHAR(255) NOT NULL,
    message_id BIGINT,
    sent_at TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (calendar_event_id, kind, target_type, target_id)
);
```

Перед `sendMessage`: `INSERT ... ON CONFLICT DO NOTHING` → если 0 rows affected, не слать.

#### 9.8.8 ParseTranscriptJob — порядок операций

Сейчас: download → delete entries+participants → create. **Сценарий потери данных**: download → delete → fail-or-restart позже → Recall presigned URL мёртв → 403 → транскрипт удалён + URL мёртв = **полная потеря транскрипта**.

**Правильный порядок:**
```php
$response = Http::get($this->url);                          // 1. download первым
if (!$response->successful()) throw new AppException(...);
$parsed = $this->parser->parse($response->json());         // 2. parse в память (валидация)
DB::transaction(function () use ($parsed) {
    $this->event->transcriptEntries()->delete();           // 3. транзакция
    $this->event->participants()->delete();
    /* recreate */
});
TranscriptParsed::dispatch($this->event);                  // 4. после commit
```

#### 9.8.9 IssueMergeService::applyDecisions — DB::transaction

Сейчас N issues + N IssueComment + N updates без транзакции → deadlock на IssueComment оставляет частичное состояние. Обернуть в `DB::transaction` (LLM-вызов уже сделан в `getDecisions()`, в транзакции его нет).

#### 9.8.10 VerifyMeetingArtifactsJob — `release(30)` при IN_PROGRESS summary

```php
public function handle(...): void {
    $summary = $this->event->meetingSummary;
    if (!$summary || $summary->status === FollowupStatus::IN_PROGRESS->value) {
        $this->release(30);  // подождём, попробуем через 30 сек
        return;
    }
    // ... остальная логика
}
```

Закрывает race-condition с `ExtractDecisionsAfterSummary`.

#### 9.8.11 InsightExtractionService — delete() перед create()

```php
$source = InsightSource::firstOrCreate([...], ['processed_at' => now()]);
$source->items()->delete();              // ← добавить
$source->shortTermMemories()->delete();  // ← добавить
foreach ($participant->items as $item) { $source->items()->create([...]); }
```

**Не делать `unique(insight_source_id, content_hash)`** — LLM даёт разные формулировки одного факта, hash не сматчит, индекс даёт ложное чувство защиты.

#### 9.8.12 Decision::issues() relation фикс

```php
// app/Models/Decision.php
public function issues(): BelongsToMany {
    return $this->belongsToMany(Issue::class, 'decision_issue')
        ->withPivot('created_at');   // вместо withTimestamps(['created_at', null])
}
```

Это разблокирует чистое использование eloquent в новом коде.

#### 9.8.13 Saga log table в Phase 1, не Phase 5

Не ждать ADR-1, чтобы начать строить наблюдаемость. Минимум:

```sql
CREATE TABLE pipeline_idempotency_keys (
    id SERIAL PRIMARY KEY,
    key VARCHAR(191) UNIQUE NOT NULL,         -- "summary:event:42:v1"
    stage VARCHAR(64) NOT NULL,
    calendar_event_id BIGINT NOT NULL,
    status VARCHAR(32) NOT NULL,              -- queued|processing|done|failed
    result JSONB,                              -- {"summary_id": 42}
    started_at TIMESTAMP,
    finished_at TIMESTAMP,
    INDEX (calendar_event_id, stage)
);
```

Каждый Job/Listener сразу проверяет: «уже сделано — skip». Это даёт **idempotency на уровне jobs** (не только БД-констрейнтов) и observability «какой stage чаще падает».

Без этого Phase 5 (TranscriptPipeline) будет переписывать существующий код, не дополнять.

---

### 9.9 ADR-эскизы — ревизия

| ADR | Architecture-strategist | Code-simplicity-reviewer | Решение в плане |
|---|---|---|---|
| **ADR-1 (Pipeline+Stages)** | Это и есть лекарство. Корневая проблема. | Saga для events, выгода ноль. ВЫКИНУТЬ | **Контингентный** (см. 9.2). Решать после Phase 1, на основе наблюдений 2-3 мес |
| **ADR-2 (Prompt-слой)** | Интерфейс + контекст-DTO нужен. Поправить дизайн (`__invoke(Context): RenderedPrompt`) | `buildPrompt` сделать public + assertContains. Без интерфейса | **Поэтапно**: Phase 4 — Prompt-классы только для часто меняющихся (Summary/Review/Followup); inline остальные. Без big-bang |
| **ADR-3 (LlmGateway)** | Нужен. Между HTTP-клиентом и сервисом — слой для caching/metrics/retry-policy/fallback | Не нужен. Просто убрать `static`. 2 строки | **Минимум**: Pre-Phase 0 убрать `static` (см. 9.3.2). LlmGateway — Phase 4, если выявится боль с metrics/cost-tracking |
| **ADR-4 (TranscriptCache)** | Третий вариант: lazy-load + прокидывать аргументом. Без Redis | Cache::remember в одном файле, 5 строк | **Третий вариант** + `Cache::remember` для безопасности. Прокидывание через job-payload **не делать** (raise to 100KB на job) |
| **ADR-5 (CalendarEventUserResolver)** | Нужны параметры `AttendeeQuery` (`includeGuests`, `includeDemo`, `respectMuting`) — иначе новая путаница | (не комментировал) | **Расширить** AttendeeQuery DTO. Phase 3 |
| **ADR-6 (унификация задач)** | `ParticipantProfileMatchingService` НЕ вызывается в production — это серьёзнее, чем «дубликат путей». **Critical, не C-1 часть** | Удалить роут `POST /tasks/generate`, оставить старый сервис в demo. 5 минут | **Сразу удалить роут** (pre-Phase). Унификация demo↔production — Phase 6 опционально, не обязательно |
| **ADR-7 (transcript_pipeline_runs)** | Saga log нужен | VIEW над `EXISTS(SELECT...)` решает 80% | **Pipeline_idempotency_keys (см. 9.8.13)** — это и есть saga log, в Phase 1. ADR-7 как отдельная сущность не нужен |
| **ADR-9 (idempotency миграции)** | Unique constraints на уровне jobs нужны через `pipeline_idempotency_keys` | `decision_issue` уже имеет PK. Insight items — `delete()` перед create. Миграции преждевременны | **Только**: `meeting_notifications_sent` table (9.8.7), `pipeline_idempotency_keys` (9.8.13). Остальные — отложить |

#### Предложенные новые рекомендации

**Не использовать `lorisleiva/laravel-actions` или durable workflow** — увеличивает зависимости без сильной выгоды. Native `Bus::chain` + `Bus::batch` + saga log table — достаточный паттерн.

**`enforceMorphMap` обязателен** для polymorphic Issue (см. 9.8.6) — это pre-condition любого queued environment с морфо-связями.

**`InsightPromptBuilder` — эталон Phase 4** для остальных промптов. Не переписывать.

---

### 9.10 Pattern wins to preserve (не сломать рефакторингом)

Хорошие паттерны, которые надо явно зафиксировать:

| Паттерн | Файл | Почему важно |
|---|---|---|
| `IssueMergeService::applyDecisions` fallback-on-data-loss | `app/Services/IssueMergeService.php:149-154` | Если LLM забыл вернуть decision для item — создаём issue по-умолчанию (не теряем). Тест на это есть в плане; не сломать «строгим режимом» |
| `MeetingReviewService::buildHistory` — feedback loop через 5 предыдущих review | `app/Services/Meeting/MeetingReviewService.php:135-167` | Архитектурно делает review эволюционным, не изолированным. При распиле сервиса — сохранить |
| `DecisionAuthorResolver::resolve` — explainable matching через `matched_by` | `app/Services/Decisions/DecisionAuthorResolver.php:19-55` | Каскад фоллбэков с пометкой «по какому правилу нашли» — debug-friendly |
| `InsightPromptBuilder` — эталон промптов | `app/Services/Insight/InsightPromptBuilder.php` | Единственная правильная инкапсуляция. Использовать как образец для Phase 4 |
| `ResolvesTeamContexts` trait | `app/Services/Decisions/ResolvesTeamContexts.php` | Корректное переиспользование. **НО** связан с C-6 — если C-6 фиксят, trait умирает |
| `CalendarEventOrganizationResolver::resolve` чистый `?array` контракт | `app/Services/CalendarEventOrganizationResolver.php` | Простой и используется правильно |
| `ParseTranscriptJob` единственная точка `TranscriptParsed::dispatch` | `app/Jobs/ParseTranscriptJob.php:66` | Входная семантика правильная — диспатч из правильного места |

---

### 9.11 Quick wins (P0 — делать сразу, до Phase 0)

Однострочные/малострочные правки, которые **уменьшают боль здесь и сейчас**:

| # | Что | Файл:строка | Эффект |
|---|---|---|---:|
| Q1 | `->with('participant')` в `TranscriptBuilderService::build()` | [`app/Services/Followup/TranscriptBuilderService.php:11`](../app/Services/Followup/TranscriptBuilderService.php) | -1.5–3 sec wall-clock на встречу (5x speedup) |
| Q2 | Удалить `Log::info('messages', $messages)` | [`app/Services/Followup/FollowupService.php:97`](../app/Services/Followup/FollowupService.php) | -десятки KB логов на каждый followup (M-2) |
| Q3 | `OpenRouterClient::chat` — `static` → instance | `app/Services/OpenRouterClient.php:18` + 2 callers | Разблокирует тесты (C-5) |
| Q4 | `Decision::issues()` — `withTimestamps([...])` → `withPivot('created_at')` | `app/Models/Decision.php` | Чинит relation, разблокирует чистый eloquent |
| Q5 | `InsightExtractionService::persist` — `delete()` перед `create()` | `app/Services/Insight/InsightExtractionService.php:159-189` | Идемпотентность Insight (C-3) |
| Q6 | Удалить `app/Listeners/DispatchAgentTasksForIssues.php` | (удаление файла) | Снимает H-8 |
| Q7 | Удалить роут `POST /calendar-events/{id}/tasks/generate` | `routes/api.php:170` | Снимает 90% C-1 (никто не зовёт из фронта) |
| Q8 | `DB::transaction` вокруг `IssueMergeService::applyDecisions` | `app/Services/IssueMergeService.php:108` | Снимает C-4 для главного места |
| Q9 | `ParseTranscriptJob` — download → parse → транзакция | `app/Jobs/ParseTranscriptJob.php` | Закрывает риск полной потери транскрипта |
| ~~Q10~~ | ~~`enforceMorphMap`~~ — **отложено в Phase 1**: требует data-миграции `sourceable_type`/`notifiable_type` (FQCN → short keys) и SQL-аудита перед применением. Не однострочник, переносится в фазу с миграциями | `app/Providers/AppServiceProvider.php:42` | (отложено) |
| Q11 | Передать `$issues`+`$seriesEventIds` параметрами в `collectStructuredData` | `app/Services/Agenda/AgendaService.php:172, 352` | Убирает 2 дубликата запросов |

**Time estimate:** Q1-Q11 — суммарно ~1 рабочий день. Реальная боль уменьшается значительно ещё до начала Phase 0.

---

### 9.12 Релевантные источники (внешние)

Все ссылки актуальны на 2026-05-08:

#### Laravel queues / events
- [Laravel 12.x Events](https://laravel.com/docs/12.x/events)
- [Laravel 12.x Queues](https://laravel.com/docs/12.x/queues)
- [Laravel 12.x Mocking](https://laravel.com/docs/12.x/mocking)
- [Laravel 10.30: dispatchAfterCommit](https://laravel-news.com/laravel-10-30-0)
- [ThrottlesExceptions::failWhen()](https://laravel-news.com/throttlesexceptions-failwhen)
- [WithoutOverlapping pitfalls (AndrewRMinion)](https://andrewrminion.com/2025/08/laravel-withoutoverlapping-job-middleware-and-unexpected-maxattemptsexceededexception-errors/)
- [Bus::fake testing limitations (Laravel issue #34912)](https://github.com/laravel/framework/issues/34912)
- [ShouldBeUniqueUntilProcessing pitfall (issue #47761)](https://github.com/laravel/framework/issues/47761)
- [Race condition: dispatch saves uncommitted models (issue #29710)](https://github.com/laravel/framework/issues/29710)

#### LLM error handling
- [OpenAI Cookbook: handle rate limits](https://developers.openai.com/cookbook/examples/how_to_handle_rate_limits)
- [Retry mechanisms in Laravel (Ahmed Shamim)](https://ahmedshamim.com/posts/retry-mechanisms-in-laravel)
- [gregpriday/laravel-retry — retry strategies](https://github.com/gregpriday/laravel-retry)

#### Saga / Pipeline
- [Implementing Saga Pattern in Laravel (JustSteveKing)](https://www.juststeveking.com/articles/implementing-the-saga-pattern-laravel/)
- [Laravel Pipelines guide (Honeybadger)](https://www.honeybadger.io/blog/laravel-pipeline/)
- [Laravel Workflow GitHub](https://github.com/laravel-workflow/laravel-workflow)
- [Saga Pattern Demystified (ByteByteGo)](https://blog.bytebytego.com/p/saga-pattern-demystified-orchestration)
- [Building Multi-Agent Workflows with Laravel AI SDK](https://laravel.com/blog/building-multi-agent-workflows-with-the-laravel-ai-sdk)

#### Idempotency
- [Idempotency in Laravel 12 (Medium)](https://medium.com/@aiman.asfia/idempotency-in-laravel-12-2025-the-complete-guide-that-will-save-you-from-double-charges-3-am-0135d93f6dea)
- [Laravel Queues Under the Hood (Wendell Adriel)](https://wendelladriel.com/blog/laravel-queues-under-the-hood)

#### Observability
- [Sentry Laravel Queues instrumentation](https://docs.sentry.io/platforms/php/guides/laravel/tracing/instrumentation/queues-module/)
- [Tracking queued job chains in Laravel (Inside Rafter)](https://blog.rafter.app/tracking-queued-job-chains-in-laravel/)
- [Failed Job Handling: DLQ, alerting (Queuewatch)](https://queuewatch.io/blog/failed-job-handling-retry-policies-dead-letter-queues-manual-intervention-and-alerting-systems)

---

### 9.13 Сводка институциональных learnings (из memory)

Из `learnings-researcher` — релевантные прошлые решения:

| Категория | Что | Применимость |
|---|---|---|
| **LLM robustness** | gemini-3-pro иногда префиксирует JSON текстом; `preg_match('/\{[\s\S]*\}/s', ...)` fallback | Используется в 12 местах (см. H-13). Унифицировать в helper в Phase 1 |
| **Mass assignment** | `User::$fillable` без `is_demo` тихо блокировал `create` | При работе с любыми новыми полями — `forceFill` или явный `fillable` |
| **Pivot relation bug** | `Decision::issues()` сломан через `withTimestamps([...])` | Q4 quick win |
| **Race conditions** | `Participant::firstOrCreate(['profile_id' => null])` коллапсил по неполному ключу | При любом `firstOrCreate` — расширять search conditions |
| **Idempotency holes** | `InsightExtractionService` удваивает items при retry | Q5 quick win |
| **Queue sync mode** | timeout оставляет БД в mid-state | Phase 1: failed() handler'ы должны откатывать частичные состояния |
| **Schema YAGNI** | Не добавлять nullable колонок «на потом» | Применять к ADR-9 миграциям — только то, что нужно сейчас |
| **User-Team paths** | 4 разных пути разрешения участников документированы в CLAUDE.md | Подтверждает H-4 (унифицирующий resolver нужен) |

---

### 9.14 Открытые вопросы — обновлённые

К списку из раздела 8 добавляется:

#### 8.11 (новое) Decision::issues() — фикс сейчас или отложить?

Q4 — однострочный фикс. После него можно убрать workaround `DB::table('decision_issue')` в `VerifyMeetingArtifactsJob`. **Делать в pre-Phase или в Phase 1?**

#### 8.12 (новое) Demo как «детерминированная полоса»

architecture-strategist отметил: `MeetingTaskService::extract` имеет детерминированное поведение для повторяемых демо-прогонов. `IssueMergeService` использует LLM, а в demo LLM не детерминирован → demo-выводы будут разными в разных запусках, и тесты demo развалятся, если переключим на единый pipeline. **Без LLM-моков в demo унификация невозможна.**

#### 8.13 (новое) `ParticipantProfileMatchingService` в production

В production он **не вызывается**. Это значит:
- В проде `participant.profile_id` ≈ всегда null
- `IssueMergeService::resolveAssigneeId` опирается на нечёткий `nameMatches` через team
- Это серьёзнее, чем «дубликат путей» — assignee матчинг в проде неточен

**Вопрос:** включать ли `ParticipantProfileMatchingService` в production-флоу как первый шаг до `IssueExtractionService`?

#### 8.14 (новое) Cost-control как KPI

После 9.6 (cost ≈ $0.73/встречу) — **зафиксировать его как метрику Phase 4**:
- Текущий cost = $X/мес
- Target после Phase 4 = $X×0.5/мес
- Без этого Phase 4 невозможно измерить, и mergeable LLM calls могут превратиться в self-justifying refactor

#### 8.15 (новое) `MeetingTaskStatus` enum в `Issue` model

`Issue::status` хранит значения из `MeetingTaskStatus`, но модель называется `Issue`. Это **семантически грязно**:
- Фронт работает с `Issue` (`getMeetingTasks` API возвращает `MeetingTask[]` тип, но он = модель `Issue`)
- В коде статусы зовутся `MeetingTaskStatus::OPEN`, но имеют отношение к `Issue`

**Вопрос:** переименовать `MeetingTaskStatus` → `IssueStatus`, или оставить (миграция API-схемы фронта)? Связан с L-5.

---

## Приложение A: Файлы по разделам

### Listeners (17)

```
app/Listeners/
├── DetectRepeatedDiscussions.php           [sync, transcript]
├── DispatchAgentTasksForIssues.php         [sync, no-op — H-8]
├── ExtractDecisionsAfterSummary.php        [sync, transcript]
├── GenerateFollowup.php                    [sync, transcript]
├── GenerateMeetingReview.php               [sync, transcript]
├── GenerateMeetingSummary.php              [sync, transcript]
├── GenerateUpcomingAgenda.php              [sync, transcript]
├── LinkDecisionsAfterTasksExtracted.php    [sync, demo path]
├── NotifyCalendarOwnerAboutCreatedTasks.php [sync, demo path]
├── RescheduleBot.php                       [sync, calendar event change — out of scope]
├── SendMeetingReviewNotification.php       [sync, transcript]
├── SendMeetingSummaryNotification.php      [sync, transcript]
├── SendMeetingTasksNotification.php        [sync, demo path]
├── UpdateMeetingSeriesState.php            [sync, dispatch-only]
└── Insight/
    ├── ExtractInsightItemsListener.php     [queued]
    ├── UpdateInsightProfilesListener.php   [queued]
    └── UpdateInsightRelationshipsListener.php [queued]
```

### Jobs (в скоупе)

```
app/Jobs/
├── ParseTranscriptJob.php
├── GenerateFollowupJob.php
├── RegenerateFollowupJob.php
├── ExtractIssuesFromTranscriptJob.php
├── VerifyMeetingArtifactsJob.php
├── GenerateUpcomingAgendaJob.php
├── GenerateAgendaJob.php
├── UpdateMeetingSeriesStateJob.php
├── DetectRepeatedDiscussionsJob.php       (использование?)
├── SendAgendaNotificationsJob.php
└── SendDecisionFollowupsJob.php
```

### Services (в скоупе)

```
app/Services/
├── CalendarEventOrganizationResolver.php
├── IssueExtractionService.php
├── IssueMergeService.php
├── OpenRouterClient.php                   (static)
├── Agenda/
│   ├── AgendaContext.php
│   ├── AgendaService.php                  (1255 строк — H-1)
│   ├── MeetingSeriesStateService.php
│   ├── PreviousMeetingResolver.php
│   └── UpcomingAgendaService.php
├── Decisions/
│   ├── DecisionAuthorResolver.php
│   ├── DecisionFollowupNotifier.php
│   ├── ExtractDecisionsService.php
│   ├── ExtractKeyPointsService.php        (использование?)
│   ├── LinkDecisionsToIssuesService.php   (только demo — H-9)
│   └── ResolvesTeamContexts.php           (trait)
├── Followup/
│   ├── FollowupArtifactStateService.php
│   ├── FollowupPromptInterface.php
│   ├── FollowupPromptRegistry.php
│   ├── FollowupService.php
│   ├── Prompts/
│   │   └── SharedStayfittV1Prompt.php
│   └── TranscriptBuilderService.php       (L-1: не там лежит)
├── Insight/
│   ├── InsightEvolutionService.php
│   ├── InsightExtractionService.php
│   ├── InsightMaintenanceService.php
│   ├── InsightPromptBuilder.php
│   ├── InsightRelationshipService.php
│   ├── InsightRetrievalService.php
│   ├── InsightService.php
│   └── InsightTelegramService.php
├── Issue/
│   └── IncompleteIssuesNotifier.php
└── Meeting/
    ├── DetectRepeatedDiscussionsService.php
    ├── MeetingContextService.php           (today-flow)
    ├── MeetingReviewService.php
    ├── MeetingSummaryService.php
    ├── MeetingTaskService.php              (старый путь — C-1)
    └── ParticipantProfileMatchingService.php (только demo)
```

### Прочее

```
resources/views/prompts/methodology_prompt.blade.php   (Followup prompt)
config/ai.php                                          (LLM models)
bootstrap/app.php                                      (scheduler + middleware)
app/Providers/AppServiceProvider.php                   (DI bindings — нет EventServiceProvider)
```

---

## Приложение B: Что из ADR-эскизов сделать НЕЛЬЗЯ без миграций

- ADR-9 (idempotency) — миграции на `decision_issue` (PK + unique).
- ADR-7 (observability) — новая таблица `transcript_pipeline_runs`.
- ADR-1 (pipeline) — статусные поля у CalendarEvent (опционально: `pipeline_status`).

Все эти миграции — отдельная сессия.

---

## Приложение C: Что я не успел проверить (TODO для следующего захода)

- `app/Services/Decisions/ExtractKeyPointsService.php` — используется ли вообще?
- `app/Services/Insight/InsightMaintenanceService.php`, `InsightTelegramService.php`, `InsightRetrievalService.php` — кто и где их использует?
- `app/Jobs/DetectRepeatedDiscussionsJob.php` — есть и как **Job**, и как **Service**. Job диспатчится откуда?
- Точное место диспатча `IssuesExtracted` — это `IssueExtractionService` или `ExtractIssuesFromTranscriptJob`?
- `MeetingSeriesState::buildSeriesIdentifier` — реальная логика? title или recurrence?
- Реальное поведение `Decision::issues()` (баг сломан как именно)?
- Все ли listeners на `IssuesExtracted`/`MeetingTasksExtracted`/`InsightItemsExtracted` обнаружены?

Эти вопросы — для следующей итерации, перед началом Phase 0. Без ответов план Phase 0 рискует не покрыть нужные регрессии.
