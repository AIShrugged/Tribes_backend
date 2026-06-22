# Second Brain sidecar (pilot)

Autonomous "second brain" for ONE organization. Runs Claude Code as a long-lived
interactive session driven by the built-in `/loop dynamic` skill — the loop lives
INSIDE Claude (self-paced). Reaches the HR backend over MCP (`http://nginx/mcp`)
and GitHub via `gh`. Suggest-only by default — it never closes tasks itself; it
writes findings to a volume and creates `[BRAIN]` issue suggestions.

## How it works

- `entrypoint.sh` launches an interactive `claude` session inside a pseudo-TTY
  (`expect` → `loop-session.exp`) and sends `/loop dynamic <prompt>`. Claude then
  self-paces the loop (1 min–1 h between passes; it may stop on its own). Contract
  lives in `CLAUDE.md`; the per-pass task in `brain-prompt.md`.
- A **watchdog** relaunches the session if it exits — this also covers the
  documented **7-day expiry** of `/loop` scheduled tasks.
- `ship-events.sh` tails Claude's session transcript
  (`/state/claude/projects/*/*.jsonl`) and POSTs each reasoning step / tool call /
  result to the backend → table `brain_events`.
- Cross-cycle memory lives in the `second-brain-state` volume (`/state`):
  `seen.json` (dedupe keys) + `findings-<ts>.md` (reports) + `brain.log` + the
  Claude session (`/state/claude`).
- Tenant isolation: the MCP token belongs to a service user that is a manager of
  exactly one organization, and every MCP tool scopes by the authenticated user
  (`InteractsWithMcpTenant`). The route also requires the `mcp` token ability.

> Caveats (this is a hack, not an officially supported pattern): running `/loop`
> unattended needs the PTY trick above; `/loop` tasks auto-expire after 7 days
> (handled by the watchdog); and unattended runs use
> `--permission-mode bypassPermissions`, so tool gating is best-effort (suggest-only
> is also enforced via `CLAUDE.md`). The simpler bash-wrapper alternative
> (`claude -p` re-invoked per cycle) is preserved at `loop.sh.stashed`.

## Setup

1. Provision the scoped MCP token for the target organization:

   ```bash
   docker compose exec backend php artisan brain:issue-token <ORG_ID>
   # prints TRIBESMCP_TOKEN (shown once)
   ```

2. Authenticate Claude. Two options — pick ONE:

   **(a) Claude Pro/Max subscription (no API key — recommended for the pilot).**
   On a machine logged into your Claude account, generate a long-lived (~1 year)
   token and copy it:

   ```bash
   claude setup-token   # opens browser login, prints a CLAUDE_CODE_OAUTH_TOKEN
   ```

   **(b) Anthropic API key** (pay-as-you-go; required for the productized,
   customer-facing version per Anthropic's terms).

3. Put credentials in your environment / `.env` (compose reads these):

   ```env
   # ONE of these:
   CLAUDE_CODE_OAUTH_TOKEN=<from `claude setup-token`>   # uses your subscription
   # ANTHROPIC_API_KEY=sk-ant-...                        # alternative (API billing)

   TRIBESMCP_TOKEN=<token from step 1>     # determines the organization (its service user)
   BRAIN_GITHUB_TOKEN=ghp_...            # optional: read PRs/code for the org
   # optional tuning:
   BRAIN_MIN_DELAY=1800                  # 30m floor
   BRAIN_MAX_DELAY=86400                 # 24h ceiling
   BRAIN_ALLOW_STATUS_WRITES=false       # keep false for suggest-only
   ```

   > Note: if `ANTHROPIC_API_KEY` is set it takes precedence over the
   > subscription token, so leave it unset when using option (a). Anthropic's
   > terms allow subscription use for personal/dev headless automation; a
   > product sold to end-customers must use the API / Agent SDK (commercial terms).

4. Build & run (the service is behind the `brain` compose profile):

   ```bash
   docker compose --profile brain up -d --build second-brain
   docker compose logs -f second-brain
   ```

5. Inspect findings + reasoning log:

   ```bash
   # files in the container volume
   docker compose exec second-brain sh -c 'ls -la /state && cat /state/findings-*.md'
   ```

   Every cycle's full transcript (reasoning, tool calls, tool results, summary)
   is also posted to the backend and stored in the `brain_events` table:

   ```bash
   # via API (org manager auth), grouped by run_uuid:
   curl -s "http://localhost/api/v1/brain/events" -H "Authorization: Bearer <manager-token>"
   ```
   Each row has `type` (reasoning | thinking | tool_call | tool_result |
   cycle_summary | cycle_start), `tool_name`, `content`, `payload`, `run_uuid`,
   `seq`. This is the persisted "reasoning of the second brain".

## Safety

- `update_task_status` is disallowed at the runner level unless
  `BRAIN_ALLOW_STATUS_WRITES=true`. Even then it is server-side scoped to the
  org and reversible (status change, not delete).
- People-profiling/PII tools are NOT exposed by the MCP server to this client.
- One sidecar = one organization. For another org, provision a separate token and
  run a separate `second-brain` instance (distinct container name + volume).

## Notes / first run

- Headless permission flags (`--allowedTools` / `--strict-mcp-config`) are set in
  `loop.sh`. If a future Claude Code version changes MCP permission handling,
  adjust the flags there.
- `gh` uses `GITHUB_TOKEN`. If the org has no connected repos, the code step is
  skipped by the agent.
