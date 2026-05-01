#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

HTTP_PORT="${HTTP_PORT:-8088}"
LDAP_PORT="${LDAP_PORT:-3389}"
USE_DOCKER="${USE_DOCKER:-1}"
FREE_PORTS_BEFORE_INIT="${FREE_PORTS_BEFORE_INIT:-1}"
FREE_PORTS_AFTER_INIT="${FREE_PORTS_AFTER_INIT:-1}"

free_port() {
  local port="$1"
  local pids=""
  pids="$(lsof -tiTCP:${port} -sTCP:LISTEN 2>/dev/null || true)"
  if [[ -n "${pids}" ]]; then
    echo "==> Releasing busy port ${port} (pids: ${pids//$'\n'/ })"
    kill ${pids} >/dev/null 2>&1 || true
    sleep 1
  fi
}

cleanup() {
  if [[ "${FREE_PORTS_AFTER_INIT}" == "1" ]]; then
    if [[ "${USE_DOCKER}" == "1" ]]; then
      docker compose -f "${ROOT_DIR}/docker-compose.yml" down >/dev/null 2>&1 || true
    fi
    free_port "${HTTP_PORT}"
    free_port "${LDAP_PORT}"
  fi
}

trap cleanup EXIT

if [[ "${FREE_PORTS_BEFORE_INIT}" == "1" ]]; then
  if [[ "${USE_DOCKER}" == "1" ]]; then
    docker compose -f "${ROOT_DIR}/docker-compose.yml" down >/dev/null 2>&1 || true
  fi
  free_port "${HTTP_PORT}"
  free_port "${LDAP_PORT}"
fi

LDAP_BASE_DN="${LDAP_BASE_DN:-dc=example,dc=com}" \
LDAP_ADMIN_DN="${LDAP_ADMIN_DN:-cn=admin,dc=example,dc=com}" \
LDAP_ADMIN_PASSWORD="${LDAP_ADMIN_PASSWORD:-123}" \
LDAP_PORT="${LDAP_PORT}" \
LDAP_HOST_URI="${LDAP_HOST_URI:-ldap://127.0.0.1:${LDAP_PORT}}" \
USE_DOCKER="${USE_DOCKER}" \
API_BASE="${API_BASE:-http://127.0.0.1:${HTTP_PORT}}" \
CSV_PATH="${CSV_PATH:-storage/csv/bootstrap_30_users.csv}" \
RESET_LDAP="${RESET_LDAP:-1}" \
RESET_IDM_DB="${RESET_IDM_DB:-1}" \
AUTO_START_BACKEND="${AUTO_START_BACKEND:-1}" \
STOP_BACKEND_ON_DONE="${STOP_BACKEND_ON_DONE:-1}" \
"${ROOT_DIR}/ops/bootstrap_fresh_ldap_idm.sh"