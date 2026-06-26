#!/bin/bash
#
# SessionStart hook for Claude Code on the web.
#
# Prepares the container so the test suite and linter run without false reds:
#  1. PHP dependencies (composer) — the guest consultation tests and everything
#     else need vendor/ present.
#  2. A running Redis server — the ephemeral guest consultation store (ADR-0003)
#     and its tests talk to Redis directly. Without it those tests error out.
#
# Idempotent and non-interactive. Scoped to the remote (web) environment so it
# never touches a local developer machine.
set -euo pipefail

# Only run in Claude Code on the web; locally the developer manages services.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-.}"

# 1. PHP dependencies (vendor/ is gitignored, so a fresh clone has none).
#    Use `install` (not `ci`-style) so the post-hook container cache is reused.
if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist --no-progress
fi

# 2. Redis — start it only if installed and not already responding.
if command -v redis-server >/dev/null 2>&1; then
  if ! redis-cli ping >/dev/null 2>&1; then
    redis-server --daemonize yes --save '' --appendonly no
  fi
else
  echo "session-start: redis-server not found — guest consultation tests will fail until Redis is available." >&2
fi
