#!/usr/bin/env bash
#
# Second-brain entrypoint: the loop lives INSIDE Claude (`/loop dynamic`), in a
# long-lived interactive session. This script only:
#   1. validates auth,
#   2. starts the brain_events shipper (tails the session transcript -> backend),
#   3. runs a watchdog that (re)launches the interactive `/loop` session via a
#      pseudo-TTY (expect), restarting it on exit / the 7-day task expiry.

set -uo pipefail

STATE_DIR=/state
LOG="$STATE_DIR/brain.log"
BRAIN_API_URL="${BRAIN_API_URL:-http://nginx/api/v1}"
RESTART_DELAY="${BRAIN_SESSION_RESTART_DELAY:-60}"

mkdir -p "$STATE_DIR"
log() { echo "[brain] $(date -u +%FT%TZ) $*" | tee -a "$LOG"; }

# --- Auth ------------------------------------------------------------------
# Prefer the Claude subscription token; unset an empty API key so it does not
# take precedence (see README).
if [ -z "${ANTHROPIC_API_KEY:-}" ]; then
  unset ANTHROPIC_API_KEY
fi
if [ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ] && [ -z "${ANTHROPIC_API_KEY:-}" ]; then
  log "FATAL: no Claude auth. Set CLAUDE_CODE_OAUTH_TOKEN (from 'claude setup-token') or ANTHROPIC_API_KEY."
  exit 1
fi
if [ -z "${TRIBESMCP_TOKEN:-}" ]; then
  log "FATAL: TRIBESMCP_TOKEN is not set"
  exit 1
fi

export BRAIN_API_URL TRIBESMCP_TOKEN

# --- Skip first-run interactive gates (onboarding/theme/trust/MCP approval) ----
# Claude's interactive TUI otherwise blocks on these; pre-seed the config so the
# session goes straight to the prompt. Idempotent merge on every start.
seed_claude_config() {
  local cfg="$CLAUDE_CONFIG_DIR/.claude.json" tmp
  mkdir -p "$CLAUDE_CONFIG_DIR"
  [ -f "$cfg" ] || echo '{}' > "$cfg"
  tmp=$(mktemp)
  if jq '. + {
        theme: "dark",
        hasCompletedOnboarding: true,
        hasCompletedProjectOnboarding: true,
        bypassPermissionsModeAccepted: true,
        hasTrustDialogAccepted: true,
        hasTrustDialogHooksAccepted: true,
        autoUpdates: false,
        enableAllProjectMcpServers: true
      }
      | .projects = ((.projects // {}) + {"/brain": ((.projects["/brain"] // {}) + {
          hasTrustDialogAccepted: true,
          hasCompletedProjectOnboarding: true,
          enabledMcpjsonServers: ["tribesmcp"],
          enableAllProjectMcpServers: true
        })})' "$cfg" > "$tmp" 2>/dev/null; then
    mv "$tmp" "$cfg"
    log "claude config seeded (onboarding/trust/mcp gates skipped)"
  else
    rm -f "$tmp"
    log "WARN: could not seed claude config"
  fi
}
seed_claude_config

# --- Permissions via settings.json (avoids bypassPermissions + its warning gate)
# Run in default mode with a broad allow list (no prompts, no hangs) and deny the
# destructive status write unless explicitly enabled (suggest-only).
seed_settings() {
  local s="$CLAUDE_CONFIG_DIR/settings.json" tmp deny='[]'
  if [ "${BRAIN_ALLOW_STATUS_WRITES:-false}" != "true" ]; then
    deny='["mcp__tribesmcp__update_task_status"]'
  fi
  [ -f "$s" ] || echo '{}' > "$s"
  tmp=$(mktemp)
  if jq --argjson deny "$deny" '. + {
        enableAllProjectMcpServers: true,
        permissions: ((.permissions // {}) + {
          allow: ["Bash","Read","Write","Edit","Glob","Grep","WebFetch","mcp__tribesmcp"],
          deny: $deny,
          defaultMode: "default"
        })
      }' "$s" > "$tmp" 2>/dev/null; then
    mv "$tmp" "$s"
    log "settings.json seeded (suggest_only=$([ "$deny" = "[]" ] && echo no || echo yes))"
  else
    rm -f "$tmp"
    log "WARN: could not seed settings.json"
  fi
}
seed_settings

# --- MCP endpoint (regenerated from env each start) -------------------------
# The compose alias `nginx` does not resolve under Coolify (the container is
# named nginx-<suffix>), so the orchestrator injects the resolved URL via
# BRAIN_MCP_URL. Falls back to the compose alias for the plain docker-compose
# setup. The Bearer placeholder stays literal — Claude Code expands it from env.
seed_mcp_config() {
  local url="${BRAIN_MCP_URL:-http://nginx/mcp}" tmp
  tmp=$(mktemp)
  if jq -n --arg url "$url" \
      '{mcpServers: {tribesmcp: {type: "http", url: $url, headers: {Authorization: "Bearer ${TRIBESMCP_TOKEN}"}}}}' \
      > "$tmp" 2>/dev/null; then
    mv "$tmp" /brain/.mcp.json
    log "mcp config seeded (url=$url)"
  else
    rm -f "$tmp"
    log "WARN: could not seed .mcp.json (keeping baked-in default)"
  fi
}
seed_mcp_config

log "second-brain (interactive /loop) starting (status_writes=${BRAIN_ALLOW_STATUS_WRITES:-false})"

# --- brain_events shipper (background) -------------------------------------
/usr/local/bin/ship-events.sh &
SHIPPER_PID=$!
# shellcheck disable=SC2064
trap "kill $SHIPPER_PID 2>/dev/null || true" EXIT

# --- Watchdog: keep the /loop session alive -------------------------------
while true; do
  log "launching interactive Claude /loop session"
  expect -f /brain/loop-session.exp >>"$LOG" 2>&1
  log "Claude /loop session ended; restarting in ${RESTART_DELAY}s"
  sleep "$RESTART_DELAY"
done
