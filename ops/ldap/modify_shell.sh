#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

ldapmodify -x -D cn=admin,dc=example,dc=com -W -f "${SCRIPT_DIR}/modify_shell.ldif"