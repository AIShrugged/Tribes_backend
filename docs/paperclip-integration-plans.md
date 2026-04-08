# Paperclip Integration Plans for WandaAsk Backend

## Scope

This document proposes three pragmatic ways to integrate Paperclip into agent runs in this backend repository (`AIShrugged/WandaAsk_backend`), with increasing depth:

1. Adapter mode (fastest, lowest risk)
2. Hybrid mirrored execution (balanced)
3. Paperclip-first orchestration (most strategic)

## Current Runtime Baseline

From code inspection, current agent execution already has strong orchestration primitives:

- `IssueController::dispatch` -> `IssueAgentService` / `IssueAgentFlowService`
- `AgentTaskSchedulerService` enqueues `RunAgentTaskJob`
- `RunAgentTaskJob` executes either:
  - inline (`InlineAgentTaskExecutor` + `AgentService`)
  - isolated (`IsolatedAgentTaskExecutor` + sandbox container)
- internal host-mediated sandbox bridge:
  - `POST /api/v1/internal/agent-task-runs/{run}/tool-calls`
  - `POST /api/v1/internal/agent-task-runs/{run}/llm-completions`

Relevant files:

- `app/Services/IssueAgentService.php`
- `app/Services/IssueAgentFlowService.php`
- `app/Services/AgentTaskSchedulerService.php`
- `app/Jobs/RunAgentTaskJob.php`
- `app/Services/IsolatedAgentTaskExecutor.php`
- `app/Http/Controllers/API/v1/SandboxToolGatewayController.php`
- `config/agent.php`

## Plan 1: Adapter Mode (Fast, Low Risk)

### Goal

Keep WandaAsk as the source of truth for `issues`/`agent_tasks`, but delegate selected runs to Paperclip as an external execution provider.

### Design

- Add Paperclip provider config (`config/paperclip.php`) and `.env` keys:
  - `PAPERCLIP_API_URL`
  - `PAPERCLIP_API_KEY`
  - `PAPERCLIP_ENABLED`
- Add execution provider flag in task metadata:
  - `metadata.execution_provider = native|paperclip`
- In `RunAgentTaskJob`, branch before executor selection:
  - `native` -> existing inline/isolated path
  - `paperclip` -> `PaperclipRunExecutor`
- `PaperclipRunExecutor` responsibilities:
  - create/checkout remote issue
  - submit prompt + context payload
  - poll until completion (bounded timeout/backoff)
  - map remote status/output into local `AgentTaskRun`

### Security

- Store API key only in server env/secrets (never in task payload).
- Strict outbound allowlist for Paperclip host when isolated tasks invoke network.
- Add request idempotency key: `agent_task_run_id`.

### Performance

- Keep queue topology unchanged.
- Polling backoff (for example 1s -> 2s -> 5s -> 10s max).
- Reuse existing retry semantics in `RunAgentTaskJob`.

### Maintainability

- Minimal code surface change.
- Easy rollback: switch provider flag back to `native`.

### Expected Effort

- 2-4 backend days including tests.

## Plan 2: Hybrid Mirrored Execution (Balanced)

### Goal

Run bi-directional sync between local issues and Paperclip issues for traceability and gradual migration.

### Design

- Add linkage columns:
  - `issues.paperclip_issue_id` (nullable, indexed)
  - `agent_task_runs.paperclip_run_id` (nullable, indexed)
- Create `PaperclipSyncService` with outbox pattern:
  - write local domain event -> enqueue sync job
  - deliver to Paperclip with idempotency key
- Webhook endpoint for inbound Paperclip events:
  - run started/completed/failed
  - comments/annotations
- Apply status mapping table:
  - Paperclip -> local `Issue` and `AgentTaskRunStatus`

### Security

- Verify webhook signature (HMAC with rotation support).
- Reject stale webhook timestamps.
- Enforce replay protection via event id dedupe table.

### Performance

- Event-driven updates reduce polling cost.
- Outbox + background sync avoids blocking request path.

### Maintainability

- Better observability and auditability across systems.
- Preserves local fallback during migration.

### Expected Effort

- 1-2 weeks backend + migration + integration tests.

## Plan 3: Paperclip-First Orchestration (Strategic)

### Goal

Move agent orchestration ownership to Paperclip, keep WandaAsk as domain API, workspace/tool host, and product UI.

### Design

- Paperclip becomes run scheduler and state authority for long-running agent tasks.
- WandaAsk exposes narrowly scoped internal endpoints for:
  - workspace read/write
  - issue/agent-task state transitions
  - optional tool execution gateway
- Replace local dispatch trigger for selected flows:
  - `IssueController::dispatch` creates Paperclip execution request
  - local `agent_tasks:dispatch` remains only for legacy/feature-flag paths
- Persist minimal projection locally:
  - status, timestamps, output summary, external ids

### Security

- Service-to-service auth (short-lived JWT or mTLS).
- Least-privilege scopes for Paperclip service credentials.
- Network segmentation for internal endpoints.

### Performance

- Removes duplicated scheduling loops.
- Scales orchestration independently from app API workers.
- Requires careful SLO and timeout contracts between systems.

### Maintainability

- Clean responsibility split long term.
- Highest migration complexity and operational change.

### Expected Effort

- 3-6 weeks phased rollout.

## Recommended Rollout

1. Start with Plan 1 under feature flag by organization/team.
2. Add Plan 2 sync primitives once first production traffic is stable.
3. Adopt Plan 3 only if Paperclip becomes strategic control plane for most agent workloads.

## Suggested Implementation Backlog

1. Add `config/paperclip.php` and env keys.
2. Implement `PaperclipClient` with retry + idempotency headers.
3. Add `PaperclipRunExecutor` and provider branch in `RunAgentTaskJob`.
4. Add feature flag and per-task provider selection.
5. Add integration tests for success/failure/timeout mapping.
6. Add metrics: provider latency, error rate, retries, status drift.

## Acceptance Criteria

- Existing native runs remain unchanged when provider is `native`.
- Paperclip-backed runs update local run status deterministically.
- Retries are idempotent and do not duplicate remote executions.
- Secrets never appear in logs, run metadata, or artifacts.
