# Async Telegram Runtime

## Overview

Telegram agent processing is asynchronous and coalesced.

Unlike web chat, Telegram does not expose a polling status endpoint. The runtime accepts webhook events, batches burst user messages into one agent turn, runs the agent in the background, and posts the final answer back to Telegram.

Runtime persistence now uses the shared channel bus tables:

- `channel_conversations`
- `channel_messages`
- `channel_identities`

The old `telegram_chat_messages` table is retained only as legacy storage.

---

## Execution Flow

### 1. Webhook receives a Telegram message

Entry point:

```text
POST /api/v1/telegram/webhook
```

This route is intentionally hidden from generated public API docs.

The webhook handler:

1. Reads the incoming Telegram update.
2. Skips non-text/system messages.
3. Resolves or creates `TelegramUser`.
4. Persists the incoming message through `ChannelBus`.
5. Ignores group messages that do not mention the bot.
6. Validates whitelist access if configured.
7. Schedules background branch processing with a coalescing delay.

Relevant class:

- `App\Http\Controllers\API\v1\TelegramBotController`

---

### 2. Coalescing window

Telegram users often send multiple short messages in a row. To avoid running the agent on each fragment separately, the webhook does not immediately execute the agent.

Instead it dispatches:

```text
ProcessTelegramBranchJob::dispatch(...)->delay(now()->addSeconds(config('agent.telegram.coalesce_window_seconds')))
```

Default config:

```php
'telegram' => [
    'coalesce_window_seconds' => 4,
],
```

During this window, multiple pending user messages from the same chat/thread are combined into one agent turn.

---

### 3. Branch -> worker pipeline

Current Telegram runtime pipeline:

```text
TelegramBotController
  -> ProcessTelegramBranchJob
    -> TelegramMessageCoalescer::claimPendingBatch(...)
      -> ProcessTelegramWorkerJob
        -> AgentService::run(...)
        -> Telegram Bot API sendMessage(...)
```

`ProcessTelegramBranchJob` is an orchestration seam. It claims a batch and only dispatches the worker when there is a valid linked application user.

---

## Coalescing Semantics

`TelegramMessageCoalescer` groups messages by:

- `telegram_chat_id`
- `message_thread_id` for forum/topics support

Only user messages that are:

- not yet coalesced
- not yet responded to

are included in a batch.

When a batch is claimed:

- all included rows receive the same `agent_batch_uuid`
- `coalesced_at` is set
- content is merged into a numbered list

Example merged prompt payload:

```text
1. First message
2. Second message
3. Third message
```

This merged text becomes the single user turn passed into `AgentService::run(...)`.

---

## Delivery Model

Telegram does not currently expose a separate run-status API.

Instead, the final status is observable through side effects:

- successful run -> bot sends a reply to Telegram and persists an assistant `TelegramChatMessage`
- failed run -> worker logs the error and releases the batch for retry/reprocessing

That means Telegram is effectively "push-only" from the user perspective.

---

## Persistence Model

Incoming user and assistant messages are stored in `channel_messages`.

Telegram routing metadata lives on the linked `channel_conversations` row:

- `telegram_chat_id`
- `message_thread_id`

External authors live in `channel_identities`.

For Telegram user messages this identity stores:

- `channel_type = telegram`
- `external_id = telegram_user_id`
- linked `user_id` when the Telegram account is connected to an application user
- username/display name metadata

Batch lifecycle fields:

- `agent_batch_uuid`
- `coalesced_at`
- `responded_at`

Meaning:

- no batch UUID + no timestamps -> fresh pending message
- `agent_batch_uuid` + `coalesced_at` -> claimed for processing
- `responded_at` -> response already produced for this batch

Assistant replies are also persisted to `channel_messages` with:

- `role = assistant`
- `agent_batch_uuid` set to the batch that produced the response
- `responded_at` set

---

## Mention and Access Rules

Group chats:

- the bot only responds when mentioned
- the mention is stripped before sending content to the agent

Private chats:

- all text messages are eligible for processing

Access behavior:

- Incoming Telegram users are not gated by a bot-level allowlist

---

## Stop Command

Special command:

```text
/stop
```

Behavior:

1. Calls `AgentService::requestStop($user->id)` if a linked app user exists.
2. Sends an acknowledgement message back to Telegram.
3. Persists that acknowledgement as an assistant `TelegramChatMessage`.

This is a best-effort interruption mechanism for an already running agent loop.

---

## Error Handling

Webhook behavior:

- always returns HTTP 200 to Telegram
- logs SDK/runtime errors internally
- avoids Telegram retries caused by application exceptions

Worker behavior:

- logs the error
- releases the batch by clearing `agent_batch_uuid` and `coalesced_at`
- rethrows the exception so queue retry policy can handle it

---

## Key Differences From Web Chat

Web chat:

- returns a queued assistant placeholder immediately
- exposes `GET /api/v1/chats/{chat}/runs/{runUuid}`
- UI can poll a concrete run

Telegram:

- returns nothing user-visible over HTTP webhook
- coalesces burst messages before execution
- pushes the final answer directly into Telegram
- has no separate run-status endpoint today

---

## Relevant Classes

- `app/Http/Controllers/API/v1/TelegramBotController.php`
- `app/Jobs/ProcessTelegramBranchJob.php`
- `app/Jobs/ProcessTelegramWorkerJob.php`
- `app/Services/Agent/TelegramMessageCoalescer.php`
- `app/Services/Channel/ChannelBus.php`
- `app/Models/ChannelMessage.php`

---

## Tests

Current direct coverage:

- `tests/Feature/TelegramMessageCoalescerTest.php`

Run in Docker:

```bash
docker compose exec -T backend php artisan test tests/Feature/TelegramMessageCoalescerTest.php
docker compose exec -T backend php artisan test
```
