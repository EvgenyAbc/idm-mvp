#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

sudo ldapmodify -Y EXTERNAL -H ldapi:/// -f "${SCRIPT_DIR}/acl_update.ldif"