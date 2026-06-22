# Research: OpenViking — Context Database для ИИ-агентов

**Дата**: 17.04.2026  
**Источник**: https://github.com/volcengine/OpenViking  
**Контекст**: TribesMCP Backend — оценка как замены/дополнения к текущей памяти агентов

---

## Что такое OpenViking

OpenViking — open-source **контекстная база данных** для ИИ-агентов от ByteDance/Volcengine. Открыт в январе 2026, 22.5k звёзд, 1.6k форков на момент ресерча.

**Главная идея**: вместо плоского вектор-поиска (классический RAG) — **файловая система** для контекста. Память, ресурсы и навыки агента хранятся как файлы и папки под протоколом `viking://`, с иерархическим доступом.

**Лицензия**: AGPLv3 (основной код), Apache 2.0 (CLI + примеры).

---

## Проблемы, которые решает

| Проблема | Классический RAG | OpenViking |
|----------|-----------------|------------|
| Фрагментированный контекст | Разные векторные БД | Единая файловая система `viking://` |
| Расход токенов | Всё в контекст сразу | L0/L1/L2 — загрузка по требованию |
| Плохой retrieval | Flat cosine similarity | Directory recursive retrieval |
| Непрозрачность поиска | Чёрный ящик | Полная траектория поиска |
| Память не развивается | Статические записи | Автоматическая консолидация после сессии |

---

## Архитектура

### Виртуальная файловая система

```
viking://
├── resources/         # Внешние ресурсы: документы, репо, веб-страницы
├── user/              # Данные пользователя
│   └── memories/      # Предпочтения, привычки, личные данные
└── agent/             # Данные агента
    ├── skills/        # Навыки и инструкции
    └── memories/      # Память о задачах, tips, паттерны
```

Каждый "файл" автоматически получает три уровня:
- **L0 (`.abstract`)**: одно предложение, ~100 токенов
- **L1 (`.overview`)**: ключевая информация, ~2k токенов  
- **L2**: полный контент, загружается по требованию

### Механизм поиска (Directory Recursive Retrieval)

1. Анализ намерения → несколько поисковых условий
2. Векторный поиск → определяет релевантную директорию
3. Уточнение внутри директории
4. Рекурсивный drill-down в поддиректории
5. Агрегация результатов

Вся траектория поиска сохраняется — можно отладить, почему агент нашёл именно это.

---

## Технический стек

- **Python 84%**, Rust 5.7% (CLI), C++ 4.4%
- **Python 3.10+**, Go 1.22+, GCC 9+ / Clang 11+
- Поддержка VLM: OpenAI, Anthropic (через LiteLLM), DeepSeek, Gemini, Ollama, Volcengine
- Поддержка Embedding: OpenAI, Jina, Voyage, Gemini, MiniMax, Volcengine

---

## HTTP API (интеграция из PHP)

Сервер запускается на `http://localhost:1933`. Все endpoints — JSON REST.

### Базовые endpoints

```
GET  /health                          → {"status": "ok"}

POST /api/v1/resources                → Добавить ресурс/память
     Body: {"path": "https://example.com/doc.md"}

GET  /api/v1/fs/ls?uri=viking://...   → Список файлов в директории

POST /api/v1/search/find              → Семантический поиск
     Body: {"query": "предпочтения пользователя по встречам"}

POST /api/v1/resources/temp_upload    → Загрузить локальный файл
```

### Аутентификация

Два уровня ключей:
- `user_key` — обычный доступ к данным (рекомендуется)
- `root_key` — административные операции

Передача: параметр `api_key` или заголовки:
```
X-OpenViking-Account: tenant-name
X-OpenViking-User: username
```

### Четыре инструмента агента (из плагина)

| Инструмент | Описание | Параметры |
|-----------|----------|-----------|
| `memsearch` | Семантический поиск | query, target_uri, mode (auto/fast/deep), limit, score_threshold |
| `memread` | Чтение конкретного URI | uri, level (abstract/overview/read/auto) |
| `membrowse` | Навигация по файловой системе | uri, view (list/tree/stat) |
| `memcommit` | Триггер консолидации памяти | — (фоновая задача) |

### Пример вызова из PHP (Laravel HTTP client)

```php
// Поиск памяти
$response = Http::withHeaders(['api_key' => config('openviking.key')])
    ->post('http://localhost:1933/api/v1/search/find', [
        'query' => $userMessage,
        'target_uri' => "viking://user/{$userId}/memories/",
        'limit' => 6,
        'score_threshold' => 0.15,
    ]);

// Добавление памяти после встречи
Http::withHeaders(['api_key' => config('openviking.key')])
    ->post('http://localhost:1933/api/v1/resources', [
        'path' => "viking://user/{$userId}/memories/meeting_{$meetingId}",
        'content' => $extractedFacts,
    ]);

// Консолидация после сессии (вызывать в конце диалога)
Http::withHeaders(['api_key' => config('openviking.key')])
    ->post('http://localhost:1933/api/v1/session/commit');
```

---

## Автоматическая эволюция памяти

В конце каждой сессии вызывается `memcommit` → фоновый процесс:
1. Анализирует результаты выполнения задачи и обратную связь пользователя
2. Извлекает ключевые факты через LLM
3. Обновляет `viking://user/{id}/memories/` (предпочтения)
4. Обновляет `viking://agent/memories/` (операционные паттерны)

Агент буквально "умнеет" с каждым использованием — без ручной разметки.

---

## Benchmarks

Тест на датасете **LoCoMo10** (долгосрочные диалоги):

| Подход | Task Completion | Token Cost vs baseline |
|--------|----------------|----------------------|
| Baseline (without memory) | 0% | — |
| Native memory (raw history) | ~35% | baseline |
| LanceDB (vector-only) | ~40% | ~baseline |
| **OpenViking** | **49-52%** | **-83% до -91%** |

**Ключевой результат**: +43-49% к completion rate при экономии 83-91% токенов.

---

## Сравнение с Mem0 и Zep

| Параметр | OpenViking | Mem0 (self-hosted) | Zep |
|----------|-----------|-------------------|-----|
| **Парадигма** | Файловая система | Key-value + graph | Temporal graph |
| **Retrieval** | Иерархический (dir recursive) | Hybrid (vector+exact) | Graph + vector |
| **Token savings** | 83-91% | ~80% | н/д |
| **Benchmark** | 49-52% (LoCoMo10) | 49% (LongMemEval) | 63.8% (LongMemEval) |
| **Автоматическая консолидация** | Да (memcommit) | Да (LLM extraction) | Да |
| **Temporal reasoning** | Нет | Слабый | Сильный |
| **Наблюдаемость** | Высокая (траектория) | Низкая | Средняя |
| **PHP интеграция** | REST API | REST API | REST API |
| **Лицензия** | AGPLv3 | Apache 2.0 | Проприетарная |
| **Сложность развёртывания** | Средняя (Python + зависимости) | Высокая (3 контейнера) | Средняя |
| **Зрелость** | Новый (янв 2026) | Зрелый | Зрелый |

**Важно про лицензию**: AGPLv3 означает, что если OpenViking интегрирован в сервер как компонент — код вашего сервера тоже должен быть открыт. Для SaaS нужна коммерческая лицензия.

---

## Установка и развёртывание

```bash
# Python пакет
pip install openviking --upgrade

# С VikingBot-агентом
pip install "openviking[bot]"

# Опциональный Rust CLI
cargo install --git https://github.com/volcengine/OpenViking ov_cli

# Конфигурация
mkdir ~/.openviking
cat > ~/.openviking/ov.conf << EOF
{
  "storage": {"workspace": "/data/openviking"},
  "embedding": {
    "dense": {
      "provider": "openai",
      "api_base": "https://api.openai.com/v1",
      "api_key": "sk-...",
      "model": "text-embedding-3-small",
      "dimension": 1536
    }
  },
  "vlm": {
    "provider": "litellm",
    "api_base": "https://openrouter.ai/api/v1",
    "api_key": "...",
    "model": "anthropic/claude-3-5-sonnet"
  }
}
EOF

# Запуск сервера
openviking-server
# → слушает http://0.0.0.0:1933
```

**Docker**: официального Docker образа в README нет — только pip install. Можно обернуть в Dockerfile самостоятельно.

---

## Плагины и интеграции

- **OpenClaw Plugin** — регистрирует OpenViking как context engine (+52% task completion)
- **OpenCode Memory Plugin** — автосинхронизация сессии + memory tools
- **Claude Code Memory Plugin** — интеграция с Claude Code (примеры в репо)

---

## Применимость к нашему проекту

### Что подходит

| Кейс | Применимость | Примечание |
|------|-------------|------------|
| Память Wanda/Telegram агента | Высокая | `memsearch` заменяет `MemoryService::composeMemoryContext()` |
| Ресурсы: база знаний HR | Высокая | `resources/` — подключить корпоративные документы, регламенты |
| Транскрипции → память | Высокая | После обработки RecallBot — `memcommit` в user-память участников |
| AgentTask контекст | Средняя | `agent/memories/` — tips по конкретным задачам |
| Трекинг кадровых изменений | Низкая | Temporal reasoning отсутствует |

### Что не подходит

- **Лицензия AGPLv3** — серьёзное ограничение для коммерческого продукта. Нужна либо коммерческая лицензия у Volcengine, либо отказ от интеграции
- **Нет Docker образа** — усложняет деплой в существующий docker-compose
- **Новый проект** (январь 2026) — возможны баги, breaking changes в API
- **Python-сервис** — дополнительная зависимость, которую нужно запустить и поддерживать

### Схема интеграции (если лицензия решена)

```
[RecallBotService] ──────────────────────────────┐
                                                   ↓
[AgentService]  →  [OpenVikingClient]  →  POST /api/v1/search/find
                         ↑                         |
[UpdateMemoryTool]  ─────┘          POST /api/v1/resources
                                    POST /api/v1/session/commit (end of session)
```

---

## Рекомендация

**Интересное решение, но рано для production**:
- AGPLv3 лицензия — блокер для SaaS без коммерческого соглашения
- Проект молодой (3 месяца), API может меняться
- Нет Docker образа из коробки

**Что взять из идей OpenViking прямо сейчас**:
1. **Иерархическая организация памяти** — структурировать `AgentMemory` по типам (user/resources/agent), а не плоско
2. **L0/L1/L2 подход** — хранить краткое резюме (summary) и полный текст отдельно, загружать сначала summary
3. **memcommit паттерн** — добавить Laravel Queue job для консолидации памяти в конце сессии (аналог уже есть в `AgentMemoryIngestionService`)

**Рекомендуемый порядок**: сначала pgvector + Mem0 (из предыдущего ресерча), позже пересмотреть OpenViking когда он достигнет v1.0 стабильности и прояснится ситуация с лицензией.

---

## Источники

- [GitHub: volcengine/OpenViking](https://github.com/volcengine/OpenViking)
- [OpenViking Official Site](https://www.openviking.ai/)
- [OpenViking Server Quickstart](https://github.com/volcengine/OpenViking/blob/main/docs/en/getting-started/03-quickstart-server.md)
- [OpenCode Memory Plugin README](https://github.com/volcengine/OpenViking/blob/main/examples/opencode-memory-plugin/README.md)
- [Integrating OpenViking into OpenClaw — AZDIGI Blog](https://azdigi.com/en/blog/tri-tue-nhan-tao/integrating-openviking-into-openclaw-upgrading-ai-agent-memory-reducing-token-costs-by-83)
- [OpenViking Explained — FAUN.dev](https://medium.com/@techlatest.net/openviking-explained-reinventing-memory-and-context-for-ai-agents-c189b2bea61b)
- [OpenViking: ByteDance's Open-Source Context Database](https://emelia.io/hub/openviking-context-database-ai-agents)
