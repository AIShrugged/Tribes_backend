#!/usr/bin/env bash
#
# Ships the second-brain's reasoning to the backend (brain_events). In the
# interactive `/loop` setup there is no stream-json stdout, so we poll Claude's
# session transcript (CLAUDE_CONFIG_DIR/projects/<slug>/<session>.jsonl) and POST
# each new reasoning step / tool call / tool result.
#
# Poll-by-line-count (not tail -F): robustly follows the *active* session even
# when it is created after the shipper starts, and persists an offset per session
# so a restart does not re-post old events.

set -uo pipefail

STATE_DIR=/state
LOG="$STATE_DIR/brain.log"
BRAIN_API_URL="${BRAIN_API_URL:-http://nginx/api/v1}"
PROJECTS_DIR="${CLAUDE_CONFIG_DIR:-/state/claude}/projects"
OFFDIR="$STATE_DIR/ship-offsets"
mkdir -p "$OFFDIR"

log() { echo "[brain][ship] $(date -u +%FT%TZ) $*" >> "$LOG"; }

line_to_events() {
  printf '%s' "$1" | jq -c '
    if .type=="assistant" then ( .message.content[]? |
        if   .type=="text"     then {type:"reasoning", content:(.text // "")}
        elif .type=="thinking" then {type:"thinking",  content:(.thinking // "")}
        elif .type=="tool_use" then {type:"tool_call", tool_name:.name, payload:.input}
        else empty end)
    elif .type=="user" then ( .message.content[]? |
        if (.type? // "")=="tool_result" then {type:"tool_result",
            content:(( if (.content|type)=="array" then (.content|map(.text? // (.|tostring))|join("\n")) else (.content|tostring) end )[0:20000]),
            payload:{tool_use_id:.tool_use_id}}
        else empty end)
    else empty end' 2>/dev/null | jq -cs '.' 2>/dev/null
}

# Print a concise, human-readable line per event to STDOUT so it shows up in
# `docker compose logs -f second-brain` (lets you watch what the brain is doing).
narrate() {
  printf '%s' "$1" | jq -r '.[] |
    if   .type=="tool_call"     then "→ tool: \(.tool_name)"
    elif .type=="tool_result"   then "   ✓ result (\((.content // "")|length) chars)"
    elif .type=="reasoning"     then "🧠 " + ((.content // "") | gsub("\n";" ") | .[0:200])
    elif .type=="thinking"      then "💡 " + ((.content // "") | gsub("\n";" ") | .[0:140])
    elif .type=="cycle_summary" then "✅ cycle: " + ((.content // "") | gsub("\n";" ") | .[0:200])
    else .type end' 2>/dev/null | while IFS= read -r l; do
      echo "[brain] $(date -u +%H:%M:%S) $l"
    done
}

post_events() {
  local run="$1" events="$2" body
  [ -z "$events" ] && return 0
  [ "$events" = "[]" ] && return 0
  body=$(jq -cn --arg run "$run" --argjson events "$events" '{run_uuid:$run, events:$events}' 2>/dev/null) || return 0
  printf '%s' "$body" | curl -s -m 30 -o /dev/null -X POST "$BRAIN_API_URL/brain/events" \
    -H "Authorization: Bearer $TRIBESMCP_TOKEN" \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    --data-binary @- || true
}

log "shipper started (poll mode); watching $PROJECTS_DIR"

CUR=""
LINES=0
RUN=""
idle=0
while true; do
  new=0
  FILE=$(ls -t "$PROJECTS_DIR"/*/*.jsonl 2>/dev/null | head -1)
  if [ -n "$FILE" ]; then
    if [ "$FILE" != "$CUR" ]; then
      CUR="$FILE"
      RUN=$(basename "$FILE" .jsonl)
      LINES=$(cat "$OFFDIR/$RUN" 2>/dev/null || echo 0)
      log "tracking session $RUN from line $LINES"
      echo "[brain] $(date -u +%H:%M:%S) tracking session $RUN"
    fi
    TOTAL=$(wc -l < "$FILE" 2>/dev/null | tr -d ' ')
    TOTAL=${TOTAL:-0}
    if [ "$TOTAL" -gt "$LINES" ]; then
      while IFS= read -r line; do
        [ -z "$line" ] && continue
        ev=$(line_to_events "$line")
        { [ -z "$ev" ] || [ "$ev" = "[]" ]; } && continue
        narrate "$ev"
        post_events "$RUN" "$ev"
      done < <(sed -n "$((LINES + 1)),${TOTAL}p" "$FILE" 2>/dev/null)
      LINES="$TOTAL"
      echo "$LINES" > "$OFFDIR/$RUN"
      new=1
    fi
  fi
  # Heartbeat when idle (~every 120s) so the log shows it's alive between passes.
  if [ "$new" -eq 1 ]; then idle=0; else idle=$((idle + 1)); fi
  if [ "$idle" -ge 40 ]; then
    echo "[brain] $(date -u +%H:%M:%S) … idle — ждёт следующего прохода /loop (session ${RUN:-none})"
    idle=0
  fi
  sleep 3
done
