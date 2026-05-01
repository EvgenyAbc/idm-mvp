#!/usr/bin/env bash
# Exercise source-table vs LDAP labeledURI drift (see --help). Requires ldapmodify; sources backend/.env when present.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="${ROOT_DIR}/backend/.env"
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi

LDAP_BASE_DN="${LDAP_BASE_DN:-dc=example,dc=com}"
LDAP_ADMIN_DN="${LDAP_ADMIN_DN:-cn=admin,dc=example,dc=com}"
LDAP_ADMIN_PASSWORD="${LDAP_ADMIN_PASSWORD:-123}"
CSV_FILE="${CSV_FILE:-${ROOT_DIR}/storage/csv/source.csv}"
API_URL="${API_URL:-http://127.0.0.1:8080}"
DEFAULT_UID="${DEFAULT_UID:-jdoe}"
DEFAULT_DRIFT_URI="${DEFAULT_DRIFT_URI:-https://drift.example.com/wrong-labeledURI}"

http_url_from_csv() {
  local uid="$1"
  if [[ ! -r "$CSV_FILE" ]]; then
    echo "CSV not readable: $CSV_FILE" >&2
    exit 1
  fi
  awk -F',' -v u="$uid" '
    NR == 1 { next }
    $1 == u {
      gsub(/\r/, "", $3)
      print $3
      exit
    }
  ' "$CSV_FILE"
}

ldap_replace_labeled_uri() {
  local uid="$1"
  local uri="$2"
  local dn="uid=${uid},ou=People,${LDAP_BASE_DN}"
  echo "DN: $dn"
  ldapmodify -x -D "$LDAP_ADMIN_DN" -w "$LDAP_ADMIN_PASSWORD" <<EOF
dn: ${dn}
changetype: modify
replace: labeledURI
labeledURI: ${uri}
EOF
  echo "OK: labeledURI updated in LDAP."
}

cmd_drift() {
  local uid="${1:-$DEFAULT_UID}"
  local wrong="${2:-$DEFAULT_DRIFT_URI}"
  echo "Applying drift for uid=$uid -> labeledURI=$wrong (CSV should disagree)."
  ldap_replace_labeled_uri "$uid" "$wrong"
  echo
  echo "Next: run reconciliation from the dashboard, or:"
  echo "  export IDM_TOKEN=\"<paste idm_token from browser localStorage>\""
  echo "  $0 reconcile"
}

cmd_restore() {
  local uid="${1:-$DEFAULT_UID}"
  local url
  url="$(http_url_from_csv "$uid")"
  if [[ -z "$url" ]]; then
    echo "No row for user '$uid' in $CSV_FILE" >&2
    exit 1
  fi
  echo "Restoring uid=$uid labeledURI from CSV: $url"
  ldap_replace_labeled_uri "$uid" "$url"
}

pretty_json() {
  local raw="$1"
  if command -v jq &>/dev/null; then
    echo "$raw" | jq .
  elif command -v python3 &>/dev/null; then
    echo "$raw" | python3 -m json.tool
  else
    printf '%s\n' "$raw"
  fi
}

cmd_reconcile() {
  local token="${IDM_TOKEN:-}"
  if [[ -z "$token" ]]; then
    echo "Set IDM_TOKEN to a JWT (Dashboard -> Application -> Local Storage -> idm_token)." >&2
    exit 1
  fi
  echo "POST ${API_URL}/api/reconcile/run"
  local resp
  resp="$(curl -sS -X POST "${API_URL}/api/reconcile/run" \
    -H "Authorization: Bearer ${token}" \
    -H "Content-Type: application/json" \
    -d '{}')"
  pretty_json "$resp"
}

usage() {
  cat <<'USAGE'
Exercise source-table vs LDAP labeledURI drift for reconciliation testing.

Usage:
  reconciliation_drift_test.sh drift [uid] [wrong_labeled_uri]
  reconciliation_drift_test.sh restore [uid]
  reconciliation_drift_test.sh reconcile

Env: LDAP_BASE_DN, LDAP_ADMIN_DN, LDAP_ADMIN_PASSWORD, CSV_FILE, API_URL, IDM_TOKEN (reconcile).
Loads backend/.env when present.
USAGE
}

main() {
  local sub="${1:-}"
  shift || true
  case "$sub" in
    drift)   cmd_drift "$@" ;;
    restore) cmd_restore "$@" ;;
    reconcile) cmd_reconcile ;;
    ""|-h|--help|help) usage; exit 0 ;;
    *)
      echo "Unknown command: $sub" >&2
      usage >&2
      exit 1
      ;;
  esac
}

main "$@"
