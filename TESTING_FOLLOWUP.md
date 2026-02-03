# Тестирование функционала Followup

## 📝 Обзор изменений

Реализован новый функционал для Followup с рефакторингом архитектуры и добавлением Policy для контроля доступа.

### Основные изменения:

1. **Рефакторинг архитектуры Followup**
   - Переход от `participant_id + scope` к `team_id + user_id`
   - Followup теперь создаются для команды, а не для отдельного участника
   - Миграция: `database/migrations/2026_01_30_134659_refactor_followups_table.php`

2. **FollowupPolicy**
   - Файл: `app/Policies/FollowupPolicy.php`
   - Участники команды видят только свои followup'ы
   - Менеджеры организации видят все followup'ы команд своей организации

3. **Обновленная логика генерации**
   - Listener `GenerateFollowup` создает job для каждой команды пользователя
   - Job `GenerateFollowupJob` выполняет асинхронную генерацию
   - Использование методологии команды или дефолтной

4. **Интеграция с Recall webhook**
   - Полный цикл: webhook → ParseTranscript → TranscriptParsed event → GenerateFollowup jobs

## 🧪 Написанные тесты

### 1. FollowupPolicyTest (`tests/Feature/FollowupPolicyTest.php`)

Проверяет права доступа к followup'ам:

- ✅ Участник команды может видеть список своих followup'ов
- ✅ Участник команды может видеть конкретный свой followup
- ✅ Менеджер организации может видеть followup'ы команд
- ✅ Менеджер организации может видеть конкретный followup
- ✅ Пользователь без доступа не может видеть followup'ы
- ✅ Участник команды видит только свои followup'ы (не других участников)
- ✅ Менеджер видит все followup'ы команды
- ✅ Неавторизованный пользователь получает 401

### 2. FollowupGenerationTest (`tests/Feature/FollowupGenerationTest.php`)

Проверяет логику генерации followup'ов:

- ✅ TranscriptParsed событие создает job'ы для всех команд пользователя
- ✅ Событие не создает job'ы, если у пользователя нет команд
- ✅ Followup создается с правильными данными
- ✅ Статус followup'а становится `failed` при ошибке OpenRouter
- ✅ Используется дефолтная методология, если у команды нет своей
- ✅ Транскрипт формируется правильно из TranscriptEntry

### 3. RecallWebhookTest (`tests/Feature/RecallWebhookTest.php`)

Проверяет интеграцию с вебхуком Recall:

- ✅ Webhook получает `transcript.done` и создает ParseTranscriptJob
- ✅ ParseTranscriptJob создает участников и записи транскрипта
- ✅ Полный цикл от webhook до генерации followup'ов
- ✅ Обработка ошибок для неизвестного бота
- ✅ Обработка неподдерживаемых событий
- ✅ Graceful обработка ошибок Recall API

## 🚀 Запуск тестов

### Требования

Для запуска тестов необходимо установить SQLite расширение для PHP:

```bash
# Ubuntu/Debian
sudo apt-get install php8.3-sqlite3

# После установки проверьте
php -m | grep sqlite
```

### Запуск всех Feature тестов

```bash
./vendor/bin/phpunit tests/Feature --testdox
```

### Запуск конкретного теста

```bash
# Policy тесты
./vendor/bin/phpunit tests/Feature/FollowupPolicyTest.php --testdox

# Генерация тесты
./vendor/bin/phpunit tests/Feature/FollowupGenerationTest.php --testdox

# Webhook тесты
./vendor/bin/phpunit tests/Feature/RecallWebhookTest.php --testdox
```

### Запуск через Docker

Если тесты не запускаются локально, можно использовать Docker:

```bash
docker-compose exec backend php artisan test --testsuite=Feature
```

## 📊 API Endpoints для тестирования

### 1. Получить список followup'ов команды
```http
GET /api/v1/teams/{team_id}/followups
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "calendar_event_id": 5,
      "team_id": 2,
      "user_id": 1,
      "methodology_id": 1,
      "text": "{\"summary\": \"...\", \"action_items\": [...]}",
      "status": "done",
      "created_at": "2026-01-30T...",
      "updated_at": "2026-01-30T..."
    }
  ],
  "count": 1
}
```

### 2. Получить конкретный followup
```http
GET /api/v1/followups/{followup_id}
Authorization: Bearer {token}
```

### 3. Получить последний followup события
```http
GET /api/v1/calendar-events/{event_id}/followup
Authorization: Bearer {token}
```

### 4. Тестовая генерация followup (только для тестирования!)
```http
POST /api/v1/calendar-events/{event_id}/followups/generate
Authorization: Bearer {token}
```

⚠️ **Важно:** Endpoint `/followups/generate` помечен как тестовый и должен быть удален в production.

## 🔐 Логика прав доступа

### Двухуровневая защита:

**Уровень 1: Policy** - проверяет право доступа к ресурсу
```php
Gate::authorize('viewAny', [Followup::class, $team]);
```

**Уровень 2: Scope `owned()`** - фильтрует конкретные записи
```php
$followups = $team->followups()->owned(Auth::id());
```

### Scope `owned()` возвращает:

- **Для участников команды:** только их собственные followup'ы (где `user_id = Auth::id()`)
- **Для менеджеров организации:** ВСЕ followup'ы команд их организации

## 📋 Чеклист перед продакшеном

- [ ] Применить миграцию `2026_01_30_134659_refactor_followups_table.php`
- [ ] Убедиться, что все существующие followup'ы мигрированы корректно
- [ ] Удалить тестовый endpoint `/calendar-events/{event_id}/followups/generate`
- [ ] Проверить, что все тесты проходят
- [ ] Проверить права доступа в production
- [ ] Настроить queue для асинхронной генерации followup'ов
- [ ] Проверить лимиты OpenRouter API

## 🐛 Возможные проблемы и решения

### Проблема: "could not find driver (sqlite)"
**Решение:** Установите `php-sqlite3` расширение

### Проблема: Followup не генерируются
**Решение:**
1. Проверьте, что queue worker запущен: `php artisan queue:work`
2. Проверьте логи: `storage/logs/laravel.log`
3. Убедитесь, что пользователь состоит в команде

### Проблема: Пользователь не видит свои followup'ы
**Решение:**
1. Проверьте, что пользователь состоит в команде
2. Проверьте поле `user_id` в таблице `followups`
3. Убедитесь, что Policy зарегистрирован в `AuthServiceProvider`

## 📚 Дополнительная информация

### Модели и связи:

```
User
  → teams (BelongsToMany)
  → organizations (BelongsToMany, pivot: role)
  → followups (HasMany)

Team
  → organization (BelongsTo)
  → methodology (BelongsTo)
  → users (BelongsToMany)
  → followups (HasMany)

Followup
  → calendarEvent (BelongsTo)
  → team (BelongsTo)
  → user (BelongsTo)
  → methodology (BelongsTo)
```

### События и Listeners:

```
TranscriptParsed event
  → GenerateFollowup listener
    → GenerateFollowupJob (для каждой команды пользователя)
      → FollowupService::generate()
```

### Recall Webhook Flow:

```
1. Recall отправляет webhook: transcript.done
2. RecallWebhookController получает запрос
3. TranscriptDoneHandler запрашивает URL транскрипта из Recall API
4. ParseTranscriptJob скачивает и парсит транскрипт
5. TranscriptParsed event диспатчится
6. GenerateFollowup listener создает job'ы для всех команд
7. FollowupService генерирует followup через OpenRouter API
```
