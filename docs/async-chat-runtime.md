# Async Chat Runtime

## Overview

Tribes chat message processing is asynchronous.

When a client sends a message, the API:

1. Persists the user message.
2. Creates a placeholder assistant message with status `queued`.
3. Returns that assistant message immediately.
4. Dispatches background jobs that execute the agent loop.

This keeps the HTTP request fast and makes long-running agent/tool execution pollable.

Under the hood, chat runtime messages now live in the shared channel bus tables:

- `channel_conversations`
- `channel_messages`

The legacy `chat_messages` table is no longer the runtime source of truth.

Longer chat history is also compacted through snapshot-backed summaries keyed by conversation, so repeated runs do not have to rebuild the full older-history summary every time.

---

## Execution Flow

### 1. Send message

Endpoint:

```text
POST /api/v1/chats/{chat}/messages
```

Response shape:

```json
{
  "success": true,
  "data": {
    "id": 12,
    "chat_id": 1,
    "role": "assistant",
    "status": "queued",
    "content": "Processing...",
    "followup_data": null,
    "error_message": null,
    "agent_run_uuid": "3f7d1a53-4d74-4f59-9e75-1f3d4e5e2c11",
    "completed_at": null,
    "created_at": "2026-03-16T10:00:00.000000Z"
  },
  "message": "Success",
  "status": 200,
  "meta": []
}
```

Important fields:

- `status`: initial assistant state, starts as `queued`
- `agent_run_uuid`: stable run identifier for polling
- `content`: placeholder text until the worker completes
- `current_attempt`: starts at `0`, increments when a worker attempt starts
- `max_attempts`: configured retry ceiling for this run

---

### 2. Branch -> worker dispatch

Current web chat pipeline:

```text
WandaBotService
  -> ChannelBus
  -> ProcessChatBranchJob
    -> ProcessChatWorkerJob
      -> AgentService::run(...)
```

`ProcessChatBranchJob` is intentionally thin. It exists as a scheduling/orchestration seam so branching strategy can evolve later without changing the API contract.

---

### 3. Poll run status

Endpoint:

```text
GET /api/v1/chats/{chat}/runs/{runUuid}
```

Response shape:

```json
{
  "success": true,
  "data": {
    "agent_run_uuid": "3f7d1a53-4d74-4f59-9e75-1f3d4e5e2c11",
    "chat_id": 1,
    "message_id": 12,
    "status": "processing",
    "progress_percent": 50,
    "current_step_label": "Generating response",
    "error_message": null,
    "completed_at": null,
    "message": {
      "id": 12,
      "role": "assistant",
      "content": "Processing...",
      "created_at": "2026-03-16T10:00:00.000000Z"
    }
  },
  "message": "Success",
  "status": 200,
  "meta": []
}
```

If the run UUID does not exist or does not belong to the chat, the API returns:

```json
{
  "success": false,
  "data": null,
  "message": "Run not found",
  "status": 404,
  "meta": []
}
```

---

## Status Model

Assistant messages currently use these states:

- `queued`: message accepted, worker not started yet
- `processing`: worker is running the agent loop
- `retrying`: a previous attempt failed and the job is waiting for another attempt
- `completed`: final assistant content written successfully
- `failed`: worker failed, `error_message` is populated

Current progress mapping is intentionally coarse:

- `queued` -> `5`
- `processing` -> `50`
- `retrying` -> `25`
- `completed` -> `100`
- `failed` -> `100`

This is a UI hint, not a strict workflow engine.

Additional retry metadata exposed by the run-status endpoint:

- `current_attempt`
- `max_attempts`
- `next_retry_at`
- `failure_code`

These fields let the UI distinguish:

- first execution
- transient failure with another retry scheduled
- terminal failure

---

## Important Implementation Detail

`agent_run_uuid` must remain stable across the whole lifecycle of the assistant response.

The placeholder assistant message is created with a UUID at request time, and the worker must reuse that same UUID when switching the message from `queued` to `processing`. If the worker generates a new UUID, client polling breaks.

---

## Frontend Integration

Recommended client flow:

1. `POST /api/v1/chats/{chat}/messages`
2. Render returned assistant message immediately
3. Store `agent_run_uuid`
4. Poll `GET /api/v1/chats/{chat}/runs/{runUuid}` every 2-3 seconds
5. If `status` becomes `retrying`, keep polling and optionally show a retry indicator
6. Stop polling when `status` becomes `completed` or `failed`
7. Optionally refresh the full chat message list after completion

---

## Test Strategy

Relevant feature coverage lives in:

- `tests/Feature/ChatMessageControllerTest.php`
- `tests/Feature/ChatAgentServiceTest.php`
- `tests/Unit/ChatMessageStateTest.php`

Run in Docker:

```bash
docker compose exec -T backend php artisan test tests/Feature/ChatMessageControllerTest.php
docker compose exec -T backend php artisan test
```

LLM calls are globally mocked in tests through `tests/TestCase.php`, except tests that explicitly disable the global fake and define their own HTTP fixtures.
