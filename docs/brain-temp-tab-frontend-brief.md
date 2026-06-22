# Frontend brief: «Temp» tab — TribesMCP Second Brain UI

> Brief for the **frontend agent**. Build a new top-level tab **“Temp”** that surfaces the
> autonomous “second brain”: a **Suggestions inbox** (human-in-the-loop approve/reject) and a
> read-only **Reasoning log**. Backend is done and tested; this is purely UI over an existing REST API.

## 0. First, discover the existing project (do this before coding)
This brief is stack-agnostic. The frontend repo is a separate project (e.g. `spodial_hr_frontend`).
Before writing code, inspect it and **reuse existing conventions**:
- Framework (React/Vue/etc.), router, and how top-level tabs/nav items are registered → add the **“Temp”** tab the same way.
- The existing **HTTP client** and how the **Sanctum bearer token** is attached (auth is already implemented — reuse it; do NOT invent new auth).
- The **design system / component library** (cards, tables, badges, buttons, toasts, modals, empty/loading states) → reuse it; match look & feel.
- Existing list/pagination patterns (the API returns the total in an **`Items-Count` response header**).
- i18n: the product is Russian-facing — use the project’s i18n mechanism; user-facing copy in Russian.

Do not hardcode the API base URL — use the project’s configured API base (all paths below are under `/api/v1`).

## 1. What the brain is (context)
An autonomous agent runs on a loop and analyzes the org’s meetings, tasks, decisions and code.
It **never changes data directly** — it **proposes actions** (“create this issue”, “close this stale task”)
that a **manager approves or rejects**. On approve, the backend performs the action. It also records
its full **reasoning** (thoughts + tool calls) for transparency.

So the Temp tab has two areas:
1. **Предложения (Suggestions)** — the actionable inbox (primary).
2. **Журнал размышлений (Reasoning log)** — read-only transparency feed (secondary).

## 2. Access / auth
- All endpoints use the **existing logged-in user’s Sanctum token** (Bearer). Reuse the app’s auth.
- Endpoints are **manager-only**. A non-manager gets `403`. If the current user manages no org,
  show an empty/locked state: “Доступно менеджерам организации”.
- Multi-org managers: lists span all managed orgs; support an optional **organization filter**
  (`organization_id`). Single-org users: omit the param.

## 3. API contracts (exact)

All responses use the app envelope:
```jsonc
{ "success": true, "data": <payload>, "message": "…", "status": 200, "meta": {} }
```
List endpoints return `data` as an array and put the **total count in the `Items-Count` header**
(also exposed via `Access-Control-Expose-Headers`). Use it for pagination.

### 3.1 List suggestions
`GET /api/v1/brain/suggestions`
Query params (all optional): `status` (default `pending`; pass `all` for every status),
`key` (`create_issue` | `update_task_status`), `organization_id`, `per_page` (default 50, max 200), `page`.

`data[]` item:
```jsonc
{
  "id": 12,
  "organization_id": 2,
  "run_uuid": "e041de6f-…",          // which loop pass produced it (group/trace)
  "key": "create_issue",              // action type
  "title": "[BRAIN] Исправить баги подсчёта в блоках дашборда",
  "summary": "…",                     // nullable
  "reasoning": "На встрече 12.06 договорились…, но задачи нет.", // why — show it
  "evidence": { "meeting_id": 292, "decision_id": null, "issue_id": null, "quote": "…" }, // nullable object
  "confidence": 78,                   // 0–100, nullable
  "payload": { "name": "[BRAIN] …", "type": "organization", "description": "…" }, // the action intent
  "status": "pending",                // pending|applied|rejected|failed|superseded|expired
  "applied_result": null,             // after approve: see below
  "failure_reason": null,             // set when status=failed
  "dedupe_key": "lost:meeting:292:dashboard-counting-bugs",
  "created_at": "2026-06-19T20:24:00+00:00",
  "resolved_at": null,
  "applied_at": null
}
```

**`payload` by `key`** (render as a human “what will happen” preview):
- `key="create_issue"` → `{ name, type, description?, team_id?, assignee_id?, due_date?, source_type?, source_id? }`
  → preview: **«Создать задачу: “{name}” ({type})»**.
- `key="update_task_status"` → `{ issue_id, status }`
  → preview: **«Задача #{issue_id} → статус “{status}”»**.

### 3.2 Approve a suggestion
`POST /api/v1/brain/suggestions/{id}/approve` (no body)
- **200** `success:true`, `message:"Applied"`, `data` = the suggestion now `status:"applied"` with
  `applied_result`:
  - create_issue → `{ "issue_id": 962, "name": "…" }`
  - update_task_status → `{ "issue_id": 951, "old_status": "open", "new_status": "done" }`
- **422** `success:false` — the action could not be applied (stale/invalid); `data` = suggestion now
  `status:"failed"`, `failure_reason` explains why. Show the reason; the brain will re-propose a fresh one.
- **409** — already resolved (someone approved/rejected it); refresh the row.
- **403** — not a manager of that org.

### 3.3 Reject a suggestion
`POST /api/v1/brain/suggestions/{id}/reject` (no body)
- **200** `data` = suggestion `status:"rejected"`. (A rejected proposal is NOT re-created by the brain.)
- **409** if already resolved.

### 3.4 Reasoning log (read-only)
`GET /api/v1/brain/events`
Query: `organization_id`, `run_uuid` (filter to one loop pass), `type`, `per_page` (default 100, max 500), `page`.

`data[]` item:
```jsonc
{
  "id": 5012,
  "organization_id": 2,
  "run_uuid": "e041de6f-…",
  "seq": 7,
  "type": "tool_call",   // reasoning | thinking | tool_call | tool_result | cycle_summary | cycle_start
  "tool_name": "mcp__tribesmcp__get_open_issues", // for tool_call/tool_result, else null
  "content": "…",        // text for reasoning/thinking/cycle_summary/tool_result
  "payload": { "stale_days": 7 }, // tool args / metadata, nullable
  "occurred_at": null,
  "created_at": "2026-06-19T20:23:19+00:00"
}
```
Render grouped by `run_uuid` (one group = one loop pass), ordered by `seq`/`created_at`, with type icons:
🧠 reasoning · 💡 thinking · → tool_call (`tool_name`) · ✓ tool_result · ✅ cycle_summary · ▶ cycle_start.

## 4. UI structure

### Tab: “Temp” → two sub-views (sub-tabs or segmented control)
**A) Предложения (default)**
- Header: count of pending; filter by `status` (Ожидают / Применённые / Отклонённые / Все) and by `key`.
- A list of **suggestion cards**. Each card:
  - Badge for `key` (Создание задачи / Смена статуса) + a status badge (pending/applied/rejected/failed).
  - **Title** + a **“what will happen” preview** built from `payload` (section 3.1).
  - Collapsible **reasoning** + **evidence** (link `meeting_id`/`issue_id`/`decision_id` to existing app pages if routes exist) + confidence as a small meter/percent.
  - For `pending`: **[Подтвердить]** (primary) and **[Отклонить]** (secondary).
  - For `applied`: show result (e.g. link to created issue `applied_result.issue_id`).
  - For `failed`: show `failure_reason` in a warning style.
- **Confirm on Approve** (modal/inline “Применить предложение? Будет создана задача …”). Reject can be one-click or with a quick confirm.
- After approve/reject: update the row from the response (`data`), move it out of the “pending” filter, toast success/error. Handle 409 by refetching.

**B) Журнал размышлений**
- Optional run filter (dropdown of recent `run_uuid`s, derived from the events list).
- Timeline grouped by `run_uuid` with the type icons above; tool_call shows `tool_name` + `payload`, tool_result shows truncated `content`. Read-only. Good for “что мозг сейчас делает/сделал”.

### States to cover
- Loading skeletons; empty states (“Пока нет предложений” / “Журнал пуст”); error with retry; 403 locked state.
- Pagination via `page`/`per_page` + `Items-Count` header (load-more or pager — match the app’s pattern).

## 5. UX details
- Status badges (suggested colors): pending = neutral/blue, applied = green, rejected = grey, failed = red, expired/superseded = muted.
- `confidence`: small bar or “78%”.
- `[BRAIN]` prefix in titles is expected — you may strip it for display and show a small “Brain” chip instead.
- Don’t block the whole list while approving one card — per-card pending/disabled state.
- Polling/refresh: a manual “Обновить” is enough; optional light auto-refresh (e.g. every 30–60s) on the pending list.

## 6. Hard “do nots”
- **Do NOT** call the MCP server or create issues directly from the frontend. The ONLY way to create an
  issue/change a status from this tab is **approving a suggestion** (`/approve`). The backend performs the action.
- Don’t add new auth; reuse the app’s session/token.
- Don’t assume the total count is in the body — it’s in the **`Items-Count` header**.

## 7. Acceptance criteria
1. New top-level **“Temp”** tab, manager-gated (non-managers see a locked/empty state).
2. **Предложения**: pending suggestions render with title, action preview, reasoning, evidence, confidence; filters work.
3. **Подтвердить** calls `/approve` → on 200 the card shows `applied` + result (issue link); on 422 shows `failure_reason`.
4. **Отклонить** calls `/reject` → card shows `rejected`.
5. **Журнал размышлений** lists `brain/events` grouped by `run_uuid` with type icons; read-only.
6. Loading/empty/error/403 states handled; pagination via `Items-Count`.
7. Copy in Russian; matches the existing design system; reuses the app’s HTTP/auth layer.

## 8. Quick manual test (backend side)
After a manager logs in (token), the same calls work via curl for sanity:
```bash
curl -s "$API/api/v1/brain/suggestions?status=pending" -H "Authorization: Bearer <manager-token>"
curl -s -X POST "$API/api/v1/brain/suggestions/<id>/approve" -H "Authorization: Bearer <manager-token>"
curl -s "$API/api/v1/brain/events?per_page=50" -H "Authorization: Bearer <manager-token>"
```
