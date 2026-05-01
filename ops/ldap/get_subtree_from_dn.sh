#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <dn> [ldap-filter]"
  echo "Example: $0 'ou=People,dc=example,dc=com'"
  echo "Example: $0 'ou=People,dc=example,dc=com' '(uid=jdoe)'"
  exit 1
fi

DN="$1"
FILTER="${2:-"(objectClass=*)"}"

LDAP_URI="${LDAP_URI:-ldap://127.0.0.1:389}"

LDAPSEARCH_ARGS=(
  -H "$LDAP_URI"
  -LLL
  -b "$DN"
  -s sub
)

if [[ -n "${LDAP_BIND_DN:-}" ]] && [[ -n "${LDAP_BIND_PASSWORD:-}" ]]; then
  LDAPSEARCH_ARGS+=(-x -D "$LDAP_BIND_DN" -w "$LDAP_BIND_PASSWORD")
else
  LDAPSEARCH_ARGS+=(-x)
fi

ldapsearch "${LDAPSEARCH_ARGS[@]}" "$FILTER"
