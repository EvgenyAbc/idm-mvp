#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

LDAP_BASE_DN="${LDAP_BASE_DN:-dc=example,dc=com}"
LDAP_ADMIN_DN="${LDAP_ADMIN_DN:-cn=admin,${LDAP_BASE_DN}}"
LDAP_ADMIN_PASSWORD="${LDAP_ADMIN_PASSWORD:-123}"
CSV_PATH="${CSV_PATH:-${ROOT_DIR}/storage/csv/bootstrap_30_users.csv}"
RESET_LDAP="${RESET_LDAP:-1}"

PEOPLE_DN="ou=People,${LDAP_BASE_DN}"
GROUPS_DN="ou=Groups,${LDAP_BASE_DN}"
PRIMARY_GID="${PRIMARY_GID:-5000}"

require_file() {
  local path="$1"
  if [[ ! -f "${path}" ]]; then
    echo "ERROR: file not found: ${path}" >&2
    exit 1
  fi
}

ensure_structure() {
  # `ldapadd -c` can still return non-zero (notably 68 "Already exists"),
  # which would abort due to `set -e`. Treat 68 as success here because the
  # OU entries are safe/idempotent for a fresh bootstrap.
  set +e
  ldapadd -x -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -c -f "${SCRIPT_DIR}/structure.ldif" >/dev/null
  local rc=$?
  set -e
  if [[ "${rc}" != "0" && "${rc}" != "68" ]]; then
    echo "ERROR: ldapadd structure failed with exit code ${rc}" >&2
    exit "${rc}"
  fi
}

ldap_delete_if_exists() {
  local dn="$1"
  if ldapsearch -x -LLL -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -b "${dn}" -s base "(objectClass=*)" dn >/dev/null 2>&1; then
    ldapdelete -x -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" "${dn}" >/dev/null 2>&1 || true
  fi
}

reset_people_and_groups() {
  echo "Resetting existing People and posixGroup entries..."
  local user_dns
  user_dns="$(ldapsearch -x -LLL -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -b "${PEOPLE_DN}" "(uid=*)" dn | sed -n 's/^dn: //p')"
  if [[ -n "${user_dns}" ]]; then
    while IFS= read -r dn; do
      [[ -z "${dn}" ]] && continue
      ldapdelete -x -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" "${dn}" >/dev/null 2>&1 || true
    done <<< "${user_dns}"
  fi

  local group_dns
  group_dns="$(ldapsearch -x -LLL -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -b "${GROUPS_DN}" "(objectClass=posixGroup)" dn | sed -n 's/^dn: //p')"
  if [[ -n "${group_dns}" ]]; then
    while IFS= read -r dn; do
      [[ -z "${dn}" ]] && continue
      ldapdelete -x -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" "${dn}" >/dev/null 2>&1 || true
    done <<< "${group_dns}"
  fi
}

ensure_primary_group() {
  local ldif
  ldif="$(mktemp)"
  cat >"${ldif}" <<EOF
dn: cn=idm-users,${GROUPS_DN}
objectClass: top
objectClass: posixGroup
cn: idm-users
gidNumber: ${PRIMARY_GID}
description: Default POSIX group for IDM bootstrap users
EOF
  set +e
  ldapadd -x -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -c -f "${ldif}" >/dev/null
  local rc=$?
  set -e
  if [[ "${rc}" != "0" && "${rc}" != "68" ]]; then
    echo "ERROR: ldapadd primary group failed with exit code ${rc}" >&2
    exit "${rc}"
  fi
  rm -f "${ldif}"
}

add_or_update_user() {
  local uid="$1"
  local password="$2"
  local url="$3"
  local uid_number="$4"
  local mail="${5:-}"
  local tel="${6:-}"
  local dn="uid=${uid},${PEOPLE_DN}"
  local hash
  hash="$(slappasswd -s "${password}")"

  local exists_out
  exists_out="$(ldapsearch -x -LLL -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -b "${dn}" -s base '(objectClass=*)' dn 2>/dev/null || true)"
  if [[ -n "${exists_out}" && "${exists_out}" == dn:* ]]; then
    # Entry exists: update only the mapped attributes.
    local mod
    mod="$(mktemp)"
    cat >"${mod}" <<EOF
dn: ${dn}
changetype: modify
replace: userPassword
userPassword: ${hash}
-
replace: labeledURI
labeledURI: ${url}
-
replace: mail
mail: ${mail}
-
replace: telephoneNumber
telephoneNumber: ${tel}
EOF
    ldapmodify -x -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -f "${mod}" >/dev/null
    rm -f "${mod}"
    return
  fi

  # Entry missing: create it.
  local add
  add="$(mktemp)"
  cat >"${add}" <<EOF
dn: ${dn}
objectClass: inetOrgPerson
objectClass: posixAccount
objectClass: shadowAccount
uid: ${uid}
ou: People
cn: ${uid}
sn: ${uid}
uidNumber: ${uid_number}
gidNumber: ${PRIMARY_GID}
homeDirectory: /home/${uid}
loginShell: /bin/bash
userPassword: ${hash}
labeledURI: ${url}
mail: ${mail}
telephoneNumber: ${tel}
EOF
  ldapadd -x -c -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -f "${add}" >/dev/null
  rm -f "${add}"
}

seed_users_from_csv() {
  local n=0
  while IFS=',' read -r user password http_url mail tel || [[ -n "${user}" ]]; do
    user="${user//$'\r'/}"
    [[ "${user}" == "user" ]] && continue
    [[ -z "${user}" ]] && continue
    password="${password//$'\r'/}"
    http_url="${http_url//$'\r'/}"
    mail="${mail//$'\r'/}"
    tel="${tel//$'\r'/}"
    n=$((n + 1))
    add_or_update_user "${user}" "${password}" "${http_url}" "$((20000 + n))" "${mail}" "${tel}"
  done < "${CSV_PATH}"
}

require_file "${CSV_PATH}"
ensure_structure
if [[ "${RESET_LDAP}" == "1" ]]; then
  reset_people_and_groups
  ensure_structure
fi
ensure_primary_group
seed_users_from_csv
"${SCRIPT_DIR}/seed_rbac_groups.sh"

echo "LDAP bootstrap completed from ${CSV_PATH}"
