# "Second brain" sidecar. Runs Claude Code as a LONG-LIVED interactive session
# driven by the built-in `/loop dynamic` skill (the loop lives inside Claude, not
# in a bash wrapper). A pseudo-TTY (expect) keeps the session alive; a watchdog
# restarts it (covers crashes and the documented 7-day task expiry); a shipper
# tails the session transcript into the backend (brain_events).
#
# NOTE: running /loop unattended in a container is a hack, not an officially
# supported pattern. The bash-wrapper alternative is kept at loop.sh.stashed.
FROM node:22-bookworm-slim

# git + gh (code analysis), jq (transcript parsing), expect (drive the TUI via PTY),
# curl/ca-certs (gh apt key + API posts), ripgrep.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git ca-certificates curl jq gnupg ripgrep expect procps \
    && curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg \
        | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg \
    && chmod go+r /usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
        > /etc/apt/sources.list.d/github-cli.list \
    && apt-get update && apt-get install -y --no-install-recommends gh \
    && rm -rf /var/lib/apt/lists/*

RUN npm install -g @anthropic-ai/claude-code

WORKDIR /brain
COPY CLAUDE.md ./CLAUDE.md
COPY .mcp.json ./.mcp.json
COPY brain-prompt.md ./brain-prompt.md
COPY loop-session.exp ./loop-session.exp
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY ship-events.sh /usr/local/bin/ship-events.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/ship-events.sh

# Run unprivileged; /state is a mounted volume for session-independent memory.
RUN useradd -m brain \
    && mkdir -p /state \
    && chown -R brain:brain /brain /state
USER brain

ENV CLAUDE_CONFIG_DIR=/state/claude \
    BRAIN_ALLOW_STATUS_WRITES=false \
    BRAIN_SESSION_RESTART_DELAY=60

CMD ["/usr/local/bin/entrypoint.sh"]
