#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

LDAP_BASE_DN="${LDAP_BASE_DN:-dc=example,dc=com}"
LDAP_ADMIN_DN="${LDAP_ADMIN_DN:-cn=admin,${LDAP_BASE_DN}}"
LDAP_ADMIN_PASSWORD="${LDAP_ADMIN_PASSWORD:-123}"
CSV_PATH="${CSV_PATH:-${ROOT_DIR}/storage/csv/bootstrap_30_users.csv}"
USE_DOCKER="${USE_DOCKER:-1}" # 1 => use docker compose stack for API/LDAP
LDAP_PORT="${LDAP_PORT:-3389}" # host port mapped to LDAP container
LDAP_HOST_URI="${LDAP_HOST_URI:-ldap://127.0.0.1:${LDAP_PORT}}"
if [[ -z "${API_BASE:-}" ]]; then
  if [[ "${USE_DOCKER}" == "1" ]]; then
    API_BASE="http://127.0.0.1:8088"
  else
    API_BASE="http://127.0.0.1:8080"
  fi
fi
RESET_LDAP="${RESET_LDAP:-1}"
RESET_IDM_DB="${RESET_IDM_DB:-0}" # 1 => delete storage/idm_alpha.sqlite before IDM import
AUTO_START_BACKEND="${AUTO_START_BACKEND:-1}" # start backend if not running (required when RESET_IDM_DB=1)
STOP_BACKEND_ON_DONE="${STOP_BACKEND_ON_DONE:-0}" # 1 => stop backend if we started it
BOOTSTRAP_MARKER_ENABLE="${BOOTSTRAP_MARKER_ENABLE:-0}" # 1 => skip if marker file exists
BOOTSTRAP_MARKER_PATH="${BOOTSTRAP_MARKER_PATH:-${ROOT_DIR}/storage/.bootstrap_fresh_ldap_idm_done}"
BOOTSTRAP_DB_PATH="${BOOTSTRAP_DB_PATH:-${ROOT_DIR}/storage/idm_alpha.sqlite}" # file deleted when RESET_IDM_DB=1

health_url="${API_BASE%/}/api/health"
BACKEND_STARTED=0
DOCKER_STARTED=0

# OpenLDAP command-line tools (ldapadd/ldapsearch/...) honor LDAPURI.
export LDAPURI="${LDAP_HOST_URI}"

if [[ "${BOOTSTRAP_MARKER_ENABLE}" == "1" && -f "${BOOTSTRAP_MARKER_PATH}" ]]; then
  echo "==> Bootstrap marker exists (${BOOTSTRAP_MARKER_PATH}), skipping LDAP/IDM bootstrap."
  exit 0
fi

backend_health_code="$(curl -sS -o /dev/null -w "%{http_code}" "${health_url}" || true)"
backend_is_running=false
if [[ "${backend_health_code}" == "200" ]]; then
  backend_is_running=true
fi

if [[ "${RESET_IDM_DB}" == "1" && "${backend_is_running}" == "true" ]]; then
  echo "ERROR: RESET_IDM_DB=1 requires backend to be stopped (SQLite file in use)." >&2
  echo "Stop backend (Ctrl+C from start-backend) and rerun, or set RESET_IDM_DB=0." >&2
  exit 1
fi

if [[ "${backend_is_running}" == "false" ]]; then
  if [[ "${AUTO_START_BACKEND}" != "1" ]]; then
    echo "ERROR: backend is not running and AUTO_START_BACKEND=0. Start backend and rerun." >&2
    exit 1
  fi

  if [[ "${RESET_IDM_DB}" == "1" ]]; then
    echo "==> Resetting IDM SQLite DB: deleting ${BOOTSTRAP_DB_PATH}"
    rm -f "${BOOTSTRAP_DB_PATH}"
  fi

  if [[ "${USE_DOCKER}" == "1" ]]; then
    echo "==> Starting Docker stack (ldap + backend + dashboard + nginx)"
    docker compose -f "${ROOT_DIR}/docker-compose.yml" up -d ldap backend dashboard nginx
    DOCKER_STARTED=1
  else
    echo "==> Starting backend API (needed for IDM provisioning/reconciliation)"
    "${ROOT_DIR}/start-backend.sh" >/tmp/idm_backend_bootstrap.log 2>&1 &
    BACKEND_PID="$!"
    BACKEND_STARTED=1
  fi

  echo "==> Waiting for backend health..."
  for _i in $(seq 1 30); do
    code="$(curl -sS -o /dev/null -w "%{http_code}" "${health_url}" || true)"
    if [[ "${code}" == "200" ]]; then
      break
    fi
    sleep 1
  done

  code="$(curl -sS -o /dev/null -w "%{http_code}" "${health_url}" || true)"
  if [[ "${code}" != "200" ]]; then
    echo "ERROR: backend did not become healthy (HTTP ${code}). See /tmp/idm_backend_bootstrap.log" >&2
    exit 1
  fi
fi

echo "==> LDAP bootstrap"
LDAP_BASE_DN="${LDAP_BASE_DN}" \
LDAP_ADMIN_DN="${LDAP_ADMIN_DN}" \
LDAP_ADMIN_PASSWORD="${LDAP_ADMIN_PASSWORD}" \
CSV_PATH="${CSV_PATH}" \
RESET_LDAP="${RESET_LDAP}" \
"${ROOT_DIR}/ops/ldap/bootstrap_fresh_30.sh"

echo "==> IDM source reset + sync"
API_BASE="${API_BASE}" \
CSV_PATH="${CSV_PATH}" \
"${ROOT_DIR}/ops/idm/reset_source_and_sync_30.sh"

echo "==> Validation"
ldap_count="$(ldapsearch -x -H "${LDAP_HOST_URI}" -LLL -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -b "ou=People,${LDAP_BASE_DN}" "(uid=*)" dn | sed -n 's/^dn: //p' | wc -l | tr -d ' ')"
csv_count="$(( $(wc -l < "${CSV_PATH}") - 1 ))"

db_count="$(
ROOT_DIR_FOR_PHP="${ROOT_DIR}" php -r '
$rootDir = getenv("ROOT_DIR_FOR_PHP") ?: "";
$dbPath = getenv("SQLITE_DB_PATH") ?: "";
if ($dbPath === "") {
    $dbPath = $rootDir . "/storage/idm_alpha.sqlite";
} elseif ($dbPath[0] !== "/") {
    $dbPath = $rootDir . "/storage/" . ltrim($dbPath, "/");
}
$db = new PDO("sqlite:" . $dbPath);
$stmt = $db->query("SELECT COUNT(*) FROM source_users");
echo (string) $stmt->fetchColumn();
'
)"

echo "LDAP users count: ${ldap_count}"
echo "IDM source_users count: ${db_count}"
echo "CSV users count: ${csv_count}"

if [[ "${ldap_count}" != "30" || "${db_count}" != "30" || "${csv_count}" != "30" ]]; then
  echo "ERROR: expected 30 users in LDAP, IDM source_users, and CSV." >&2
  exit 1
fi

if [[ "${BOOTSTRAP_MARKER_ENABLE}" == "1" ]]; then
  mkdir -p "$(dirname "${BOOTSTRAP_MARKER_PATH}")"
  date -u +"%Y-%m-%dT%H:%M:%SZ" > "${BOOTSTRAP_MARKER_PATH}"
  echo "==> Wrote bootstrap marker to ${BOOTSTRAP_MARKER_PATH}"
fi

echo "Bootstrap success: LDAP and IDM are synchronized with 30 users."

if [[ "${STOP_BACKEND_ON_DONE}" == "1" && "${BACKEND_STARTED}" == "1" ]]; then
  echo "==> Stopping backend API (pid ${BACKEND_PID})"
  kill "${BACKEND_PID}" >/dev/null 2>&1 || true
fi

if [[ "${STOP_BACKEND_ON_DONE}" == "1" && "${DOCKER_STARTED}" == "1" ]]; then
  echo "==> Stopping Docker stack"
  docker compose -f "${ROOT_DIR}/docker-compose.yml" down >/dev/null 2>&1 || true
fi
