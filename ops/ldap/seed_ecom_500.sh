#!/usr/bin/env bash
set -euo pipefail

# Seeds an e-commerce-like LDAP org with:
# - Organization units
# - Role groups
# - 500 users spread across business units/roles
#
# Usage:
#   LDAP_ADMIN_PASSWORD=123 ./ops/ldap/seed_ecom_500.sh

BASE_DN="${LDAP_BASE_DN:-dc=example,dc=com}"
ADMIN_DN="${LDAP_ADMIN_DN:-cn=admin,${BASE_DN}}"
ADMIN_PASSWORD="${LDAP_ADMIN_PASSWORD:-123}"
USER_COUNT="${USER_COUNT:-500}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP_LDIF="$(mktemp)"

cleanup() {
  rm -f "${TMP_LDIF}"
}
trap cleanup EXIT

units=(
  "Executive"
  "Storefront"
  "Catalog"
  "Pricing"
  "Inventory"
  "OrderOps"
  "Fulfillment"
  "CustomerSupport"
  "Marketing"
  "Finance"
  "FraudRisk"
  "IT"
)

roles=(
  "ecom-admin"
  "shop-manager"
  "catalog-manager"
  "pricing-analyst"
  "inventory-specialist"
  "order-operator"
  "fulfillment-agent"
  "support-agent"
  "marketing-specialist"
  "finance-analyst"
  "fraud-analyst"
  "it-operator"
)

{
  # Organizational units under People.
  for unit in "${units[@]}"; do
    cat <<EOF
dn: ou=${unit},ou=People,${BASE_DN}
objectClass: organizationalUnit
ou: ${unit}

EOF
  done

  # Role groups under Groups.
  for i in "${!roles[@]}"; do
    gid=$((21000 + i))
    role="${roles[$i]}"
    cat <<EOF
dn: cn=${role},ou=Groups,${BASE_DN}
objectClass: top
objectClass: posixGroup
cn: ${role}
gidNumber: ${gid}
description: Ecommerce role ${role}

EOF
  done

  # 500 users, evenly distributed over units and roles.
  for i in $(seq 1 "${USER_COUNT}"); do
    idx=$(( (i - 1) % ${#units[@]} ))
    unit="${units[$idx]}"
    role="${roles[$idx]}"
    gid=$((21000 + idx))

    uid="$(printf "ecom%04d" "${i}")"
    givenName="User${i}"
    sn="${unit}"
    cn="${givenName} ${sn}"
    homeDir="/home/${uid}"
    userUrl="https://shop.alpha.example/users/${uid}"
    passwordHash="$(slappasswd -s "Pass!${i}")"

    cat <<EOF
dn: uid=${uid},ou=${unit},ou=People,${BASE_DN}
objectClass: inetOrgPerson
objectClass: posixAccount
objectClass: shadowAccount
uid: ${uid}
cn: ${cn}
sn: ${sn}
givenName: ${givenName}
uidNumber: $((30000 + i))
gidNumber: ${gid}
homeDirectory: ${homeDir}
loginShell: /bin/bash
userPassword: ${passwordHash}
labeledURI: ${userUrl}
ou: ${unit}
employeeType: ${role}

dn: cn=${role},ou=Groups,${BASE_DN}
changetype: modify
add: memberUid
memberUid: ${uid}

EOF
  done
} > "${TMP_LDIF}"

echo "Seeding ${USER_COUNT} e-commerce users, org units, and role groups into LDAP..."
echo "Base DN: ${BASE_DN}"

# -c continues through duplicate entries if script is re-run.
ldapadd -x -D "${ADMIN_DN}" -w "${ADMIN_PASSWORD}" -c -f "${TMP_LDIF}"

echo "Done."
