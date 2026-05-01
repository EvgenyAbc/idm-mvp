#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

API_BASE="${API_BASE:-http://127.0.0.1:8080}"
LOGIN_URL="${API_BASE}/api/auth/login"
IMPORT_URL="${API_BASE}/api/provision/upload"
PROVISION_URL="${API_BASE}/api/provision/run-poll"
RECONCILE_URL="${API_BASE}/api/reconcile/run"
CSV_PATH="${CSV_PATH:-${ROOT_DIR}/storage/csv/bootstrap_30_users.csv}"
AUTH_USER="${AUTH_USER:-alphaadmin}"
AUTH_PASS="${AUTH_PASS:-123}"

if [[ ! -f "${CSV_PATH}" ]]; then
  echo "ERROR: CSV not found at ${CSV_PATH}" >&2
  exit 1
fi

echo "Logging in to IDM API as ${AUTH_USER}..."
LOGIN_RESP="$(curl -sS -X POST "${LOGIN_URL}" -H "Content-Type: application/json" --data "{\"username\":\"${AUTH_USER}\",\"password\":\"${AUTH_PASS}\"}")"
TOKEN="$(printf '%s\n' "${LOGIN_RESP}" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')"
if [[ -z "${TOKEN}" ]]; then
  echo "ERROR: IDM login failed." >&2
  echo "${LOGIN_RESP}" >&2
  exit 1
fi

AUTH_HEADER="Authorization: Bearer ${TOKEN}"

echo "Importing canonical source users from ${CSV_PATH}..."
curl -sS -X POST "${IMPORT_URL}" -H "${AUTH_HEADER}" -F "csv=@${CSV_PATH}"
echo

echo "Running provisioning from SQLite source_users..."
curl -sS -X POST "${PROVISION_URL}" \
  -H "${AUTH_HEADER}" \
  -H "Content-Type: application/json" \
  -d '{}'
echo

echo "Running reconciliation with password sync..."
curl -sS -X POST "${RECONCILE_URL}" \
  -H "${AUTH_HEADER}" \
  -H "Content-Type: application/json" \
  -d '{"syncPasswords":true}'
echo

echo "IDM source reset + sync complete."
