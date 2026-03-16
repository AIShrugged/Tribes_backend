# Agent Runtime Architecture

## Purpose

This document describes the current agent runtime as a system, not as isolated features.

It ties together:

- web chat async execution
- Telegram async execution
- unified channel bus
- branch -> worker orchestration
- model routing
- context compaction
- testing strategy

Use this as the top-level entry point before diving into the channel-specific docs.

---

## Core Design Goal

The project no longer runs the agent as a single synchronous request loop tied directly to the user-facing transport.

Instead, the runtime is split into:

- transport layer
- orchestration layer
- execution layer
- supporting runtime services

This keeps user-facing entrypoints responsive while still allowing long-running agent/tool execution.

---

## Runtime Layers

### 1. Transport layer

Transport adapters receive input from different channels:

- web chat HTTP API
- Telegram webhook

Their job is to:

- validate access
- persist inbound messages
- schedule background work
- expose channel-appropriate response behavior

They should not do heavy agent execution inline.

---

### 2. Orchestration layer

This layer decides how an incoming turn becomes executable work.

Current orchestration objects:

- `ProcessChatBranchJob`
- `ProcessTelegramBranchJob`

The branch jobs are intentionally small. They exist as a seam between:

- transport-specific ingestion
- execution-specific worker logic

This allows runtime policy to evolve later without rewriting public entrypoints.

---

### 3. Execution layer

Execution happens in background workers:

- `ProcessChatWorkerJob`
- `ProcessTelegramWorkerJob`

These jobs are responsible for:

- loading the correct user/chat context
- registering channel-specific tools
- invoking `AgentService::run(...)`
- persisting or delivering the final result

This is where actual agent reasoning and tool loops happen.

---

### 4. Supporting runtime services

Shared runtime behavior lives outside transport/workers:

- `AgentService`
- `AgentModelRouter`
- `ConversationCompactionService`
- `ConversationCompactionSnapshotService`
- `MemoryService`
- `ChannelBus`
- `ChannelRuntimeService`
- `ChannelDeliveryRegistry`
- `ToolRegistry`

This is where channel-independent agent mechanics are implemented.

---

## AgentService Responsibilities

`AgentService` is the core execution engine.

Its main responsibilities are:

- register default tools
- load memory context
- compact old history
- build the system prompt
- run the LLM/tool loop
- enforce token budget protections
- validate tool outputs
- stop on explicit user interrupt or loop limits

It is channel-agnostic. Channel-specific behavior is injected through:

- `AgentRunOptions`
- per-channel tool registration
- channel name for memory resolution
- conversation key for runtime scoping

---

## Request Lifecycle By Channel

## Unified Channel Bus

Both web chat and Telegram now persist runtime messages through the same storage layer:

- `channel_conversations`
- `channel_messages`
- `channel_identities`

`ChannelBus` is the write/read boundary for this layer.

Message authors are modeled separately through `channel_identities`, so `channel_messages` does not carry transport-specific author fields like Telegram IDs.

`ChannelRuntimeService` is the orchestration boundary above storage:

- transport adapters call it to ingest inbound messages
- branch jobs call it to turn queued work into worker jobs
- workers call it to deliver final output through channel-specific delivery adapters

Current mapping:

- web chat conversation -> one `channel_conversations` row per `chats.id`
- Telegram conversation -> one `channel_conversations` row per `telegram_chat_id + message_thread_id`

Old rows from:

- `chat_messages`
- `telegram_chat_messages`

are backfilled into `channel_messages` by migration and no longer act as the runtime source of truth.

### Web chat

Flow:

```text
POST /api/v1/chats/{chat}/messages
  -> persist user message
  -> create assistant placeholder (queued)
  -> dispatch ProcessChatBranchJob
  -> ProcessChatWorkerJob
  -> AgentService::run(...)
  -> update assistant message to completed/failed
```

User-facing behavior:

- request returns immediately
- client receives `agent_run_uuid`
- client can poll `GET /api/v1/chats/{chat}/runs/{runUuid}`

Detailed doc:

- `docs/async-chat-runtime.md`

---

### Telegram

Flow:

```text
POST /api/v1/telegram/webhook
  -> persist inbound Telegram message
  -> delay ProcessTelegramBranchJob
  -> TelegramMessageCoalescer claims pending batch
  -> ProcessTelegramWorkerJob
  -> AgentService::run(...)
  -> send Telegram reply
  -> persist assistant Telegram message
```

User-facing behavior:

- webhook always returns quickly
- no polling API today
- result is pushed directly back into Telegram

Detailed doc:

- `docs/async-telegram-runtime.md`

---

## Why Branch -> Worker Exists

The split is small right now, but important.

It gives the runtime:

- a place to batch/coalesce work before execution
- a place to add retries or priority routing later
- a boundary between "accepting a request" and "running the agent"

Without this seam, transports become tightly coupled to execution policy.

---

## Model Routing

Model selection is centralized in `AgentModelRouter`.

Resolution order:

1. `Setting::get('model.<task_type>')`
2. fallback to `config('agent.models.<task_type>')`

Current task types are modeled by `AgentTaskType`.

This lets the runtime choose different models for different classes of work without hardcoding model names inside every worker or controller.

Current configured categories:

- `interactive`
- `summarization`
- `extraction`
- `background`

This is a runtime policy layer, not just a config convenience.

---

## Context Compaction

Old conversation history is compacted before execution by `ConversationCompactionService`.

Current strategy:

- keep a configured number of recent messages verbatim
- fold older turns into a deterministic textual summary
- inject that summary into the system prompt
- persist snapshot summaries per `conversationKey` for reuse across runs

Important properties:

- deterministic
- cheap
- transport-agnostic
- compatible with async workers
- incremental for growing conversations with a stable key

Current config:

```php
'compaction' => [
    'keep_recent_messages' => 8,
    'max_summary_chars' => 2500,
],
```

This is intentionally simpler than semantic summarization jobs, but it already provides a stable runtime boundary for history control.

---

## State Models

### Web chat state

Assistant `ChannelMessage` states:

- `queued`
- `processing`
- `retrying`
- `completed`
- `failed`

This state is explicit and pollable.

### Telegram batch state

Telegram currently uses batch lifecycle fields instead of a separate run-status API:

- `agent_batch_uuid`
- `coalesced_at`
- `responded_at`

This is enough for background orchestration, but not yet presented as a first-class external status contract.

---

## Runtime Identity Keys

The runtime uses stable identifiers to scope execution:

- web chat: `conversationKey = "chat:{id}"`
- Telegram: `conversationKey = "telegram:{chatId}"`
- web async polling: `agent_run_uuid`
- Telegram batching: `agent_batch_uuid`

These identifiers are important because they define the unit of:

- memory/context continuity
- polling
- batch ownership
- future caching/compaction upgrades

---

## Testing Strategy

The runtime is tested at several levels:

- feature tests for HTTP chat behavior
- feature tests for Telegram coalescing
- feature tests for agent loop behavior
- full suite execution inside Docker

LLM behavior:

- mocked globally in `tests/TestCase.php`
- selectively overridden in tests that need exact LLM/tool-loop assertions

This avoids external network dependency while preserving runtime behavior coverage.

Run everything in Docker:

```bash
docker compose exec -T backend php artisan test
```

---

## Current Strengths

- web transport is decoupled from execution
- Telegram burst messages are coalesced
- model routing is centralized
- context compaction is centralized
- agent run polling exists for web chat
- Docker-based tests are stable and LLM-independent

---

## Current Gaps

The runtime is improved, but not fully generalized yet.

Main remaining gaps:

- Telegram has no external run-status API
- retries/backoff are not surfaced as runtime metadata
- status handling still uses plain strings, not a dedicated state machine
- compaction is deterministic inline summarization, not cached semantic compaction
- branch jobs are intentionally thin and can later absorb more orchestration policy

---

## Recommended Reading Order

1. `docs/agent-runtime-architecture.md`
2. `docs/async-chat-runtime.md`
3. `docs/async-telegram-runtime.md`
4. `docs/mcp-server.md`
