#!/usr/bin/env bash
set -euo pipefail

LDAP_BASE_DN="${LDAP_BASE_DN:-dc=example,dc=com}"
LDAP_ADMIN_DN="${LDAP_ADMIN_DN:-cn=admin,${LDAP_BASE_DN}}"
LDAP_ADMIN_PASSWORD="${LDAP_ADMIN_PASSWORD:-123}"

GROUPS_OU="ou=Groups,${LDAP_BASE_DN}"

ensure_group() {
  local group="$1"
  local gid="$2"
  local group_dn="cn=${group},${GROUPS_OU}"

  if ldapsearch -x -LLL -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -b "${group_dn}" "(objectClass=posixGroup)" cn >/dev/null 2>&1; then
    echo "Group exists: ${group}"
    return 0
  fi

  local ldif
  ldif="$(mktemp)"
  cat >"${ldif}" <<EOF
dn: ${group_dn}
objectClass: top
objectClass: posixGroup
cn: ${group}
gidNumber: ${gid}
EOF

  ldapadd -x -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -f "${ldif}"
  rm -f "${ldif}"
  echo "Created group: ${group}"
}

add_member() {
  local group="$1"
  local username="$2"
  local group_dn="cn=${group},${GROUPS_OU}"
  local ldif
  ldif="$(mktemp)"

  cat >"${ldif}" <<EOF
dn: ${group_dn}
changetype: modify
add: memberUid
memberUid: ${username}
EOF

  if ldapmodify -x -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -f "${ldif}" >/dev/null 2>&1; then
    echo "Added ${username} to ${group}"
  else
    echo "Skipped ${username} in ${group} (already exists or cannot be added)"
  fi
  rm -f "${ldif}"
}

ensure_group "idm-ldap-viewers" 15100
ensure_group "idm-ldap-editors" 15101
ensure_group "idm-ldap-exporters" 15102
ensure_group "idm-ops-admins" 15103

# Beta defaults (adjust as needed):
# - jdoe: view/search only
# - asmith: view/search/edit
# - alphaadmin: full access
add_member "idm-ldap-viewers" "jdoe"
add_member "idm-ldap-viewers" "asmith"
add_member "idm-ldap-editors" "asmith"
add_member "idm-ldap-viewers" "alphaadmin"
add_member "idm-ldap-editors" "alphaadmin"
add_member "idm-ldap-exporters" "alphaadmin"
add_member "idm-ops-admins" "alphaadmin"

echo "RBAC LDAP groups seeded."
