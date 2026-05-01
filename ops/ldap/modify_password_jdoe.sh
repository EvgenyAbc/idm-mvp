#!/usr/bin/env bash
# Apply a new LDAP password for uid=jdoe using slappasswd + ldapmodify.
# Usage: ./modify_password_jdoe.sh 'NewPlaintextPassword'
# Admin bind password is prompted via -W (LDAP admin DN below).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADMIN_DN="${ADMIN_DN:-cn=admin,dc=example,dc=com}"
TARGET_DN="${TARGET_DN:-uid=jdoe,ou=People,dc=example,dc=com}"

if [[ "${1:-}" == "" ]]; then
  echo "usage: $0 <new-password>" >&2
  echo "optional env: ADMIN_DN (default cn=admin,dc=example,dc=com)" >&2
  echo "              TARGET_DN (default uid=jdoe,ou=People,dc=example,dc=com)" >&2
  exit 1
fi

NEW_PASSWORD="$1"
HASH="$(slappasswd -s "$NEW_PASSWORD")"

TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

cat >"$TMP" <<EOF
dn: ${TARGET_DN}
changetype: modify
replace: userPassword
userPassword: ${HASH}
EOF

ldapmodify -x -D "${ADMIN_DN}" -W -f "$TMP"
