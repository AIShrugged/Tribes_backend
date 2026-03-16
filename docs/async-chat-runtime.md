# Async Chat Runtime

## Overview

Wanda chat message processing is asynchronous.

When a client sends a message, the API:

1. Persists the user message.
2. Creates a placeholder assistant message with status `queued`.
3. Returns that assistant message immediately.
4. Dispatches background jobs that execute the agent loop.

This keeps the HTTP request fast and makes long-running agent/tool execution pollable.

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

---

### 2. Branch -> worker dispatch

Current web chat pipeline:

```text
WandaBotService
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
- `completed`: final assistant content written successfully
- `failed`: worker failed, `error_message` is populated

Current progress mapping is intentionally coarse:

- `queued` -> `5`
- `processing` -> `50`
- `completed` -> `100`
- `failed` -> `100`

This is a UI hint, not a strict workflow engine.

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
5. Stop polling when `status` becomes `completed` or `failed`
6. Optionally refresh the full chat message list after completion

---

## Test Strategy

Relevant feature coverage lives in:

- `tests/Feature/ChatMessageControllerTest.php`
- `tests/Feature/ChatAgentServiceTest.php`

Run in Docker:

```bash
docker compose exec -T backend php artisan test tests/Feature/ChatMessageControllerTest.php
docker compose exec -T backend php artisan test
```

LLM calls are globally mocked in tests through `tests/TestCase.php`, except tests that explicitly disable the global fake and define their own HTTP fixtures.
