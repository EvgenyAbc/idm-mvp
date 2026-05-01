#!/usr/bin/env bash
set -euo pipefail

API_BASE="${API_BASE:-http://127.0.0.1:8080}"
RUN_PROVISION=0
RUN_RECONCILE=0
SYNC_PASSWORDS=0
AUTH_USER="${AUTH_USER:-}"
AUTH_PASS="${AUTH_PASS:-}"

usage() {
  cat <<'EOF'
Usage:
  ./check-ldap-login.sh <username> <password> [options]

Options:
  --api-base <url>      Override API base URL (default: http://127.0.0.1:8080)
  --run-provision       Trigger /api/provision/run-poll before login check
  --run-reconcile       Trigger /api/reconcile/run before login check
  --sync-passwords      With --run-reconcile, send {"syncPasswords":true}
  --auth-user <user>    Login user for privileged pre-steps (or env AUTH_USER)
  --auth-pass <pass>    Login password for privileged pre-steps (or env AUTH_PASS)
  -h, --help            Show this help

Examples:
  ./check-ldap-login.sh qwer 1233
  ./check-ldap-login.sh qwer 1233 --run-provision
  ./check-ldap-login.sh qwer 1233 --run-reconcile --sync-passwords
  ./check-ldap-login.sh qwer 1233 --run-reconcile --sync-passwords --auth-user alphaadmin --auth-pass 123
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

if [[ $# -lt 2 ]]; then
  usage
  exit 1
fi

USERNAME="$1"
PASSWORD="$2"
shift 2

while [[ $# -gt 0 ]]; do
  case "$1" in
    --api-base)
      API_BASE="${2:-}"
      shift 2
      ;;
    --run-provision)
      RUN_PROVISION=1
      shift
      ;;
    --run-reconcile)
      RUN_RECONCILE=1
      shift
      ;;
    --sync-passwords)
      SYNC_PASSWORDS=1
      shift
      ;;
    --auth-user)
      AUTH_USER="${2:-}"
      shift 2
      ;;
    --auth-pass)
      AUTH_PASS="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

call_json() {
  local method="$1"
  local path="$2"
  local body="${3:-'{}'}"
  local auth_header="${4:-}"
  curl -sS -i -X "$method" \
    -H "Content-Type: application/json" \
    ${auth_header:+-H "$auth_header"} \
    --data "$body" \
    "${API_BASE}${path}"
}

extract_status() {
  local resp="$1"
  printf '%s\n' "$resp" | awk 'NR==1 {print $2}'
}

extract_body() {
  local resp="$1"
  printf '%s\n' "$resp" | sed '1,/^\r$/d'
}

AUTH_HEADER=""
if [[ "$RUN_PROVISION" -eq 1 || "$RUN_RECONCILE" -eq 1 ]]; then
  if [[ -z "$AUTH_USER" || -z "$AUTH_PASS" ]]; then
    echo "ERROR: --run-provision/--run-reconcile require --auth-user/--auth-pass (or AUTH_USER/AUTH_PASS env)." >&2
    exit 1
  fi
  LOGIN_RESP="$(call_json POST "/api/auth/login" "{\"username\":\"${AUTH_USER}\",\"password\":\"${AUTH_PASS}\"}")"
  LOGIN_STATUS="$(extract_status "$LOGIN_RESP")"
  LOGIN_BODY="$(extract_body "$LOGIN_RESP")"
  if [[ "$LOGIN_STATUS" != "200" ]]; then
    echo "ERROR: failed to login auth user '${AUTH_USER}' (status ${LOGIN_STATUS})." >&2
    echo "$LOGIN_BODY" >&2
    exit 1
  fi
  TOKEN="$(printf '%s\n' "$LOGIN_BODY" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')"
  if [[ -z "$TOKEN" ]]; then
    echo "ERROR: auth token not found in login response." >&2
    echo "$LOGIN_BODY" >&2
    exit 1
  fi
  AUTH_HEADER="Authorization: Bearer ${TOKEN}"
fi

if [[ "$RUN_PROVISION" -eq 1 ]]; then
  echo "==> Running provisioning..."
  call_json POST "/api/provision/run-poll" '{}' "$AUTH_HEADER" | sed -n '1,16p'
  echo
fi

if [[ "$RUN_RECONCILE" -eq 1 ]]; then
  echo "==> Running reconcile..."
  if [[ "$SYNC_PASSWORDS" -eq 1 ]]; then
    call_json POST "/api/reconcile/run" '{"syncPasswords":true}' "$AUTH_HEADER" | sed -n '1,20p'
  else
    call_json POST "/api/reconcile/run" '{}' "$AUTH_HEADER" | sed -n '1,20p'
  fi
  echo
fi

echo "==> Checking LDAP login for user: ${USERNAME}"
RESP="$(call_json POST "/api/auth/login" "{\"username\":\"${USERNAME}\",\"password\":\"${PASSWORD}\"}")"
STATUS="$(extract_status "$RESP")"
BODY="$(extract_body "$RESP")"

echo "HTTP status: ${STATUS}"
echo "Response body: ${BODY}"

if [[ "$STATUS" == "200" ]]; then
  echo "OK: LDAP credentials are valid."
  exit 0
fi

echo "FAIL: LDAP credentials are not valid (or API failed)."
exit 2
