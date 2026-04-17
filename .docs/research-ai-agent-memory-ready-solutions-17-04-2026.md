# Research: Готовые решения для памяти ИИ-агентов

**Дата**: 17.04.2026  
**Контекст**: Spodial HR Backend — Laravel 12 / PHP 8.2+, Telegram-агент, Wanda-бот, транскрипции встреч

---

## Текущее состояние памяти в проекте

Проект уже имеет **несколько систем памяти**:

| Сервис | Модель БД | Что хранит | Retrieval |
|--------|-----------|------------|-----------|
| `MemoryService` | `InsightShortTerm`, `InsightProfile` | Краткосрочная память по контексту сессии (current_projects, recent_decisions, emotional_state, general_knowledge) + communication style, goals | Exact match по profile_id |
| `AgentMemoryIngestionService` | `AgentMemory` | Факты с scope (profile / repository / task), kind, priority | LIKE-поиск по тексту |
| `AgentMemoryLookupService` | `AgentMemory` | — | LIKE-поиск + фильтры по scope |
| `UpdateMemoryTool` | `InsightShortTerm` | Агент сам записывает заметки о пользователе | n/a |

**Ключевые ограничения текущей системы:**
1. **Нет семантического поиска** — только точное совпадение или LIKE. Агент не найдёт релевантную память, если запрос сформулирован иначе, чем факт
2. **Нет embedding-векторов** — невозможно искать "похожее по смыслу"
3. **Нет консолидации** — эпизоды не превращаются в структурированные факты автоматически
4. **Транскрипции встреч не интегрированы с памятью** — RecallBot обрабатывает вебхук, но данные не попадают в AgentMemory
5. **Две разрозненные системы** — InsightShortTerm и AgentMemory существуют параллельно без единого слоя

---

## Готовые решения — детальный разбор

### Решение 1: Mem0 (рекомендуется)

**Что такое**: Open-source + cloud платформа для памяти агентов. Self-hosted через Docker (3 контейнера: FastAPI, PostgreSQL+pgvector, Neo4j для графа). YC-backed, 50k+ разработчиков.

**Как работает**:
- При добавлении текста — LLM автоматически извлекает факты, дедуплицирует, обновляет противоречия
- Хранит факты в PostgreSQL с векторными embeddings (pgvector)
- При поиске — hybrid retrieval: семантический поиск + точные фильтры по `user_id` / `agent_id`
- Автоматически "забывает" устаревшие факты

**REST API** (интеграция из PHP без SDK):
```
POST http://mem0-host:8000/memories
Body: {"messages": [{"role": "user", "content": "..."}], "user_id": "42"}

POST http://mem0-host:8000/search  
Body: {"query": "предпочтения пользователя по встречам", "user_id": "42"}

GET  http://mem0-host:8000/memories?user_id=42

DELETE http://mem0-host:8000/memories/{memory_id}
```
Auth: `X-API-Key` header.

**Docker Compose** (минимальный):
```yaml
services:
  mem0-api:
    image: mem0/mem0-api-server
    environment:
      OPENAI_API_KEY: "..."   # или можно заменить на OpenRouter
      ADMIN_API_KEY: "secret"
    ports: ["8000:8000"]
  postgres:
    image: pgvector/pgvector:pg16
  neo4j:
    image: neo4j:5
```

**Плюсы**:
- Готовый REST API — интеграция из PHP за 1 день
- Автоматическая дедупликация и разрешение противоречий через LLM
- Экономия токенов до 80% за счёт сжатия истории
- Открытый исходный код, self-hosted без vendor lock-in
- Поддерживает `user_id`, `agent_id`, `run_id` — подходит для нашей multi-tenant схемы

**Минусы**:
- Требует OpenAI API для extraction (можно заменить на совместимый endpoint)
- Слабее на temporal reasoning (факты без версионирования)
- Neo4j — тяжёлая зависимость (можно отключить граф)

**Benchmark**: 49% на LongMemEval (vs Zep 63.8%)

---

### Решение 2: Zep (Graphiti)

**Что такое**: Enterprise-grade платформа памяти на основе temporal knowledge graph. Коммерческая (open-source CE депрекирована в апреле 2025).

**Как работает**:
- Факты хранятся в графе с временны́ми метками: «Иванов работает в отделе X с 2024-01-01» → может обновиться на «с 2026-04-01 — в отделе Y»
- Комбинирует graph search + vector search для retrieval
- Отслеживает изменение фактов во времени

**REST API**:
```
POST /sessions/{session_id}/messages  — добавить историю чата
GET  /sessions/{session_id}/memory   — получить релевантный контекст
POST /graph/episodes                  — добавить эпизод
POST /graph/nodes/search              — семантический поиск в графе
```

**Плюсы**:
- Лучший benchmark: 63.8% на LongMemEval
- Temporal reasoning — отслеживает изменение фактов со временем
- Точнее для сложных enterprise-кейсов

**Минусы**:
- Open-source версия депрекирована — только cloud или enterprise лицензия (платно)
- Сложнее в эксплуатации
- Python/TypeScript/Go SDK, PHP только через REST

**Вывод**: Оправдан, если нужен точный трекинг кадровых изменений (кто когда сменил роль/отдел). Для большинства кейсов Mem0 достаточен.

---

### Решение 3: pgvector (нативно в Laravel)

**Что такое**: PostgreSQL extension для хранения векторных embeddings. Уже поддерживается в нашей PostgreSQL.

**Как работает**:
- Добавляем колонку `embedding vector(1536)` в таблицу памяти
- При сохранении факта — генерируем embedding через OpenRouter/OpenAI
- При поиске — cosine similarity `<=>` оператор

**Миграция**:
```sql
CREATE EXTENSION IF NOT EXISTS vector;
ALTER TABLE agent_memories ADD COLUMN embedding vector(1536);
CREATE INDEX ON agent_memories USING ivfflat (embedding vector_cosine_ops);
```

**Запрос в Laravel** (через `andmunk/pgvector-laravel` или raw SQL):
```php
$embedding = $this->openRouter->embed($query);
$memories = AgentMemory::selectRaw('*, embedding <=> ? AS distance', [$embedding])
    ->where('agent_profile_id', $profileId)
    ->where('active', true)
    ->orderBy('distance')
    ->limit(10)
    ->get();
```

**Плюсы**:
- Никаких новых сервисов — только extension в уже существующей PostgreSQL
- Полный контроль над логикой
- Laravel AI SDK (v13) имеет встроенную поддержку pgvector и embeddings
- Можно комбинировать: сначала vector search, потом exact filters

**Минусы**:
- Нужно самому писать логику extraction, дедупликации, консолидации
- Нет UI для инспекции памяти
- Нет автоматического управления памятью

**Пакеты для Laravel**:
- `andmunk/pgvector-laravel` — Eloquent интеграция с pgvector
- `laravel/ai` (Laravel AI SDK, доступен с Laravel 13) — embeddings + vector stores из коробки
- `benbjurstrom/pgvector-for-laravel-scout` — интеграция с Laravel Scout

---

### Решение 4: Letta (MemGPT)

**Что такое**: Платформа для stateful-агентов с MemGPT-архитектурой. Агент сам управляет своей памятью через инструменты (`memory_replace`, `archival_memory_insert`, `archival_memory_search`).

**REST API**:
```
POST /agents                          — создать агента
POST /agents/{agent_id}/messages     — отправить сообщение
GET  /agents/{agent_id}/core-memory  — получить рабочую память
GET  /agents/{agent_id}/archival-memory/search — поиск в архивной памяти
```

**Плюсы**:
- Агент сам решает что помнить и что забыть (MemGPT-подход)
- Хорошо для долгих задач (AgentTask у нас)
- REST API, интеграция из любого языка

**Минусы**:
- Тяжёлая инфраструктура
- Агент становится "умнее", но менее предсказуемым
- Сложнее отлаживать

**Вывод**: Интересен для `AgentTaskSchedulerService`, где задачи могут быть долгими. Для Wanda/Telegram агента — избыточно.

---

## Сравнительная таблица

| Параметр | Mem0 (self-hosted) | Zep | pgvector (custom) | Letta |
|----------|-------------------|-----|-------------------|-------|
| **Интеграция из PHP** | REST API, 1-2 дня | REST API, 2-3 дня | Нативно, 3-5 дней на логику | REST API, неделя |
| **Новые сервисы** | 3 контейнера | 1+ контейнер (платно) | 0 | 2+ контейнера |
| **Семантический поиск** | Да (pgvector) | Да (graph+vector) | Да (pgvector) | Да (archival) |
| **Автоматическая дедупликация** | Да (LLM) | Да | Нет (писать самому) | Да (LLM) |
| **Temporal reasoning** | Слабый | Сильный | Нет | Средний |
| **Стоимость** | Бесплатно (self-hosted) | Платно | Бесплатно | Бесплатно (self-hosted) |
| **Benchmark LongMemEval** | 49% | 63.8% | — | — |
| **Vendor lock-in** | Нет | Частично | Нет | Нет |
| **Зрелость** | Высокая (prod) | Высокая (prod) | Зависит от реализации | Средняя |

---

## Кейсы в проекте и подходящее решение

### Кейс 1: Wanda-бот помнит пользователя между сессиями
- **Проблема**: Сейчас `InsightShortTerm` хранит заметки, но нет семантического поиска. Агент загружает ВСЕ записи, а не релевантные
- **Решение**: Mem0 как замена `MemoryService::composeMemoryContext()` — одним HTTP-запросом получаем релевантные факты для текущего вопроса
- **Реализация**: `POST /search` с query = текущее сообщение пользователя, user_id = профиль пользователя

### Кейс 2: Агент отвечает на вопросы по прошлым встречам
- **Проблема**: RecallBot получает транскрипции, но они не попадают в память агента
- **Решение**: После обработки транскрипции в `RecallBotService` — `POST /memories` в Mem0 с участниками + ключевыми фактами встречи
- **Реализация**: Новый `RecallMemoryIngestionService` или расширение существующего обработчика

### Кейс 3: Снижение контекста (стоимость токенов)
- **Проблема**: Весь `InsightShortTerm` + `InsightProfile` передаётся в каждый запрос
- **Решение**: Mem0 возвращает только релевантные факты (top-K по similarity). Экономия 60-80% токенов контекста памяти
- **Реализация**: Замена `formatContext()` на semantic search с limit=5

### Кейс 4: Трекинг кадровых изменений
- **Проблема**: Нет истории изменений (кто сменил роль, когда)
- **Решение**: Zep — temporal graph. Но сложно и дорого. **Альтернатива**: добавить `effective_from` в `AgentMemory` и логику обновления в `AgentMemoryIngestionService`
- **Рекомендация**: Zep только если это приоритет; иначе — кастомное поле в текущей таблице

### Кейс 5: AgentTask — долгосрочная память агентских задач
- **Проблема**: `AgentMemory` ищет по LIKE, не по смыслу. При большом числе memories агент получает нерелевантный контекст
- **Решение**: pgvector в текущую таблицу `agent_memories` — добавить `embedding` колонку, при сохранении генерировать embedding, при lookup — vector search
- **Реализация**: Изменить `AgentMemoryLookupService::searchAccessibleMemories()` на hybrid search

---

## Рекомендованный план интеграции

### Фаза 1 — pgvector для AgentMemory (минимум изменений, максимум ценности)
1. Добавить `pgvector` extension и колонку `embedding vector(1536)` в `agent_memories`
2. В `AgentMemoryIngestionService::ingest()` — генерировать embedding через OpenRouter
3. В `AgentMemoryLookupService::searchAccessibleMemories()` — vector search вместо LIKE при наличии `query`
4. Зависимость: `andmunk/pgvector-laravel` или raw SQL

**Оценка**: 3-4 дня, нет новых сервисов, немедленный эффект для AgentTask

### Фаза 2 — Mem0 self-hosted для Wanda/Telegram агента
1. Добавить Mem0 в `docker-compose.yml`
2. Создать `Mem0Client` (Laravel HTTP client) с методами `addMemory()`, `searchMemory()`
3. Заменить `MemoryService::composeMemoryContext()` на Mem0 search
4. При записи через `UpdateMemoryTool` — дублировать в Mem0

**Оценка**: 1 неделя, 3 новых Docker-контейнера

### Фаза 3 — Транскрипции → Mem0
1. В обработчике транскрипций RecallBot — после разбора payload извлекать ключевые факты
2. Отправлять в Mem0 с `user_id` каждого участника
3. Wanda/Telegram агент автоматически получит контекст встреч при поиске

**Оценка**: 3-5 дней

---

## Источники

- [Mem0 REST API Docs](https://docs.mem0.ai/open-source/features/rest-api)
- [Self-Hosting Mem0: Complete Docker Guide](https://mem0.ai/blog/self-host-mem0-docker)
- [mem0/mem0-api-server Docker Image](https://hub.docker.com/r/mem0/mem0-api-server)
- [Mem0 vs Zep vs LangMem Comparison 2026](https://dev.to/anajuliabit/mem0-vs-zep-vs-langmem-vs-memoclaw-ai-agent-memory-comparison-2026-1l1k)
- [Zep vs Mem0: Benchmarks and Pricing](https://atlan.com/know/zep-vs-mem0/)
- [Zep Documentation](https://help.getzep.com/)
- [Letta (MemGPT) GitHub](https://github.com/letta-ai/letta)
- [pgvector for Laravel Scout](https://benbjurstrom.com/pgvector-for-laravel-scout)
- [Building Hybrid Search with Laravel + pgvector](https://brudtkuhl.com/blog/building-hybrid-search-system-laravel-ai-postgresql/)
- [Laravel AI SDK Docs](https://laravel.com/docs/13.x/ai-sdk)
- [8 AI Agent Memory Patterns for Production](https://dev.to/dohkoai/8-ai-agent-memory-patterns-for-production-systems-beyond-basic-rag-5795)
- [Best AI Agent Memory Systems 2026](https://vectorize.io/articles/best-ai-agent-memory-systems)
- [State of AI Agent Memory 2026 — Mem0](https://mem0.ai/blog/state-of-ai-agent-memory-2026)
