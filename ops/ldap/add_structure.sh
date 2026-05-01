#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LDAP_BASE_DN="${LDAP_BASE_DN:-dc=example,dc=com}"
LDAP_ADMIN_DN="${LDAP_ADMIN_DN:-cn=admin,${LDAP_BASE_DN}}"
LDAP_ADMIN_PASSWORD="${LDAP_ADMIN_PASSWORD:-123}"

ldapadd -x -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -f "${SCRIPT_DIR}/structure.ldif"