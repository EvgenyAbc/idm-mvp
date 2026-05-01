#!/usr/bin/env bash
# Simulate an external LDAP password change, run reconciliation against SQLite source,
# assert password drift appears, then restore the source password in LDAP.
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
LDAP_URI="${LDAP_URI:-${LDAP_CLIENT_URI:-ldap://127.0.0.1:389}}"
CSV_FILE="${CSV_FILE:-${ROOT_DIR}/storage/csv/source.csv}"
TEST_UID="${TEST_UID:-jdoe}"
EXTERNAL_PASS="${EXTERNAL_PASS:-ExternChange!$$}"

ldap_uri_flag=( -H "${LDAP_URI}" )

password_from_csv_for_uid() {
  local uid="$1"
  if [[ ! -r "$CSV_FILE" ]]; then
    echo "CSV not readable: $CSV_FILE" >&2
    exit 1
  fi
  awk -F',' -v u="$uid" '
    NR == 1 { next }
    $1 == u {
      gsub(/\r/, "", $2)
      print $2
      exit
    }
  ' "$CSV_FILE"
}

cmd_assert_drift() {
  local json drift
  json="$(php "${ROOT_DIR}/backend/tests/run_reconcile_ldap_integration.php")"
  printf '%s\n' "$json"
  if command -v jq &>/dev/null; then
    drift="$(echo "$json" | jq '.result.drift_detected')"
  else
    drift="$(echo "$json" | php -r '$j=json_decode(stream_get_contents(STDIN),true);echo (int)($j["result"]["drift_detected"]??0);')"
  fi
  if [[ "${drift:-0}" -lt 1 ]]; then
    echo "Expected reconciliation drift_detected >= 1 after external password change; got ${drift:-}" >&2
    exit 1
  fi
  echo "OK: drift_detected=${drift} (includes userPassword mismatch vs source)."
}

ldap_set_user_password_hash() {
  local uid="$1"
  local hash="$2"
  local dn="uid=${uid},ou=People,${LDAP_BASE_DN}"
  ldapmodify "${ldap_uri_flag[@]}" -x -D "$LDAP_ADMIN_DN" -w "$LDAP_ADMIN_PASSWORD" <<EOF
dn: ${dn}
changetype: modify
replace: userPassword
userPassword: ${hash}
EOF
}

cmd_external_then_reconcile() {
  local csv_pw hash_ext hash_restore
  csv_pw="$(password_from_csv_for_uid "$TEST_UID")"
  if [[ -z "$csv_pw" ]]; then
    echo "No row for uid=$TEST_UID in $CSV_FILE" >&2
    exit 1
  fi
  echo "1) Setting LDAP-only password for uid=$TEST_UID (not in CSV) ..."
  hash_ext="$(slappasswd -s "$EXTERNAL_PASS" | tr -d '\n')"
  ldap_set_user_password_hash "$TEST_UID" "$hash_ext"

  echo "2) Running reconciliation on SQLite source ..."
  cmd_assert_drift

  echo "3) Restoring LDAP password from CSV ..."
  hash_restore="$(slappasswd -s "$csv_pw" | tr -d '\n')"
  ldap_set_user_password_hash "$TEST_UID" "$hash_restore"
  echo "Restore complete. Validate with:"
  echo "  ldapwhoami ${ldap_uri_flag[*]} -x -D uid=${TEST_UID},ou=People,${LDAP_BASE_DN} -y /path/to/pwfile"
}

usage() {
  cat <<'USAGE'
Exercise external password change vs SQLite-source reconciliation.

Requires: ldapmodify, ldapwhoami, slappasswd; PHP CLI; reachable LDAP per LDAP_URI.

Environment (optional): LDAP_URI, LDAP_BASE_DN, LDAP_ADMIN_DN, LDAP_ADMIN_PASSWORD,
CSV_FILE (default repo storage/csv/source.csv), TEST_UID (default jdoe).

Usage:
  ops/test_reconcile_external_password.sh run

Steps: apply external password → run reconciliation CLI → assert drift_detected>=1 → restore source password via admin.
USAGE
}

main() {
  local sub="${1:-}"
  case "$sub" in
    run) cmd_external_then_reconcile ;;
    ""|-h|--help|help) usage ;;
    *)
      echo "Unknown command: $sub" >&2
      usage >&2
      exit 1
      ;;
  esac
}

main "$@"
