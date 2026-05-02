#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="${SCRIPT_DIR}"
cd "${ROOT_DIR}"

MARKER="${ROOT_DIR}/storage/.docker_first_bootstrap_done"
SQLITE_REL="${SQLITE_DB_PATH:-idm_docker.sqlite}"
if [[ "${SQLITE_REL}" == /* ]]; then
  SQLITE_DB="${SQLITE_REL}"
else
  SQLITE_DB="${ROOT_DIR}/storage/${SQLITE_REL}"
fi

if [[ "${FRESH_KEEP_LDAP_VOLUMES:-}" == "1" ]]; then
  echo "==> Stopping stack (FRESH_KEEP_LDAP_VOLUMES=1: keeping named LDAP volumes)"
  docker compose down
else
  echo "==> Stopping stack and removing compose volumes (LDAP data reset)"
  docker compose down -v
fi

echo "==> Removing bootstrap marker and Docker SQLite DB for a fresh seed"
rm -f "${MARKER}" "${SQLITE_DB}"

echo "==> Building and starting stack (dashboard image runs npm ci during build)"
docker compose up -d --build
