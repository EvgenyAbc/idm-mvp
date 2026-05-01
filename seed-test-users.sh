#!/usr/bin/env bash
set -euo pipefail

IMPORT_URL="${IMPORT_URL:-http://127.0.0.1:8080/api/provision/upload}"
RUN_URL="${RUN_URL:-http://127.0.0.1:8080/api/provision/run-poll}"
LOGIN_URL="${LOGIN_URL:-http://127.0.0.1:8080/api/auth/login}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEFAULT_CSV_PATH="${SCRIPT_DIR}/storage/csv/bootstrap_30_users.csv"
CSV_PATH="${CSV_PATH:-$DEFAULT_CSV_PATH}"
AUTH_USER="${AUTH_USER:-alphaadmin}"
AUTH_PASS="${AUTH_PASS:-123}"

echo "Authenticating as ${AUTH_USER}"
LOGIN_RESP="$(curl -sS -X POST "$LOGIN_URL" \
  -H 'Content-Type: application/json' \
  --data "{\"username\":\"${AUTH_USER}\",\"password\":\"${AUTH_PASS}\"}")"
TOKEN="$(printf '%s\n' "$LOGIN_RESP" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')"
if [[ -z "$TOKEN" ]]; then
  echo "ERROR: login failed for ${AUTH_USER}" >&2
  echo "$LOGIN_RESP" >&2
  exit 1
fi
AUTH_HEADER="Authorization: Bearer ${TOKEN}"

echo "Importing source records from ${CSV_PATH}"
curl -sS -X POST "$IMPORT_URL" \
  -H "$AUTH_HEADER" \
  -F "csv=@${CSV_PATH}"
echo
echo "Running provisioning from SQLite source"
curl -sS -X POST "$RUN_URL" \
  -H "$AUTH_HEADER" \
  -H 'Content-Type: application/json' \
  -d '{}'
echo
echo "Done."
