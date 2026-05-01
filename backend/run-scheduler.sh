#!/usr/bin/env bash
set -euo pipefail

API_URL="${API_URL:-http://127.0.0.1:8080/api/provision/run-poll}"
INTERVAL_SECONDS="${INTERVAL_SECONDS:-60}"

echo "Starting SQLite-source scheduler every ${INTERVAL_SECONDS}s"
while true; do
  curl -sS -X POST "$API_URL" \
    -H 'Content-Type: application/json' \
    -d '{}' >/dev/null || true
  sleep "$INTERVAL_SECONDS"
done
