# IDM-MVP

Identity Management prototype with a PHP backend, React dashboard, SQLite source-of-truth, and OpenLDAP target directory.

Core flow:

- source users live in SQLite table `source_users`
- provisioning syncs source users into LDAP
- reconciliation detects LDAP drift and LDAP-only accounts
- sensitive updates can require approval
- dashboard authenticates against LDAP and enforces RBAC permissions

## What This Project Does

- **Provisioning**: imports CSV into SQLite and syncs records into LDAP
- **Reconciliation**: compares source vs LDAP, quarantines LDAP-only users, remediates selected drift
- **Approvals**: queues restricted edits (for example `labeledURI` user self-edits) for admin decision
- **RBAC UI/API**: permissions are derived from LDAP group membership
- **Audit + Metrics**: API actions and security-relevant events are logged and surfaced in dashboard

## Repository Layout

- `backend/` PHP API (`/api/*`) and LDAP/domain logic
- `dashboard/` React + Vite front-end
- `storage/` SQLite DB files, CSV seeds/uploads, runtime markers
- `ops/` LDAP/bootstrap/nginx operational scripts
- `tests/` smoke test(s)
- `docker-compose.yml` containerized full stack (LDAP + backend + dashboard + nginx + bootstrap)

## Data Model And Mapping

Canonical source row:

`user,password,httpUrl`

Optional CSV / SQLite columns (same header names as LDAP attributes):

- `mail` -> LDAP `mail`
- `telephoneNumber` -> LDAP `telephoneNumber`

LDAP mapping:

- `user` -> `uid`
- `password` -> `userPassword` (applied via backend LDAP operations)
- `httpUrl` -> `labeledURI` (stored as column `http_url` in SQLite)

Policy highlights:

- SQLite source is authoritative for mapped attributes.
- URL policy accepts valid `http://` or `https://` values.
- Reconcile can detect `labeledURI`, `mail`, and `telephoneNumber` drift and remediate from source when applicable (including valid URL for `labeledURI`).
- LDAP users absent from source are added to quarantine group (`cn=quarantine,...`).

## Architecture

Backend follows layered structure under `backend/src`:

- `Presentation/Http`
- `Application`
- `Domain`
- `Infrastructure`
- `Shared`

See `backend/ARCHITECTURE.md` for dependency direction and conventions.

## Prerequisites

For local non-Docker run:

- PHP 8+
- Node.js 18+ and npm
- OpenLDAP server/tools (`ldapadd`, `ldapmodify`, `ldapsearch`, `ldapwhoami`, `slappasswd`)

Docker run needs:

- Docker + Docker Compose

## Environment

### Backend (`backend/.env`)

`start-backend.sh` auto-loads `backend/.env` if present.

Common variables:

- `LDAP_URI` (optional; single LDAP server override)
- `LDAP_BASE_DN` (default `dc=example,dc=com`)
- `LDAP_ADMIN_DN` (default `cn=admin,dc=example,dc=com`)
- `LDAP_ADMIN_PASSWORD` (default `123`)
- `ADMIN_USERNAME` (default `alphaadmin`)
- `RBAC_BOOTSTRAP_ADMIN` (default `true`)
- `BACKEND_PORT` (default `8080`)
- `CSV_SOURCE_PATH` (default `../storage/csv/source.csv`)
- `LDAP_NETWORK_TIMEOUT_SEC` (optional bind/network timeout)
- `RECONCILE_PASSWORD_VERIFY_DELAY_US` (optional reconcile throttle)
- `RECONCILE_SYNC_PASSWORDS` (optional default reconcile behavior)

### Dashboard (`dashboard/.env` or shell env)

- `VITE_API_BASE` (default `http://127.0.0.1:8080`)
- `VITE_ADMIN_USERNAME` (used by admin-focused UI helpers)

## Quick Start (Local)

1. **Initialize LDAP + source data (recommended clean start):**

   ```bash
   ./ops/bootstrap_fresh_ldap_idm.sh
   ```

2. **Start backend API:**

   ```bash
   ./start-backend.sh
   ```

   Backend serves on `http://127.0.0.1:8080`.

3. **Start dashboard (new terminal):**

   ```bash
   ./start-dashboard.sh
   ```

   Dashboard (Vite dev) defaults to `http://127.0.0.1:5173`.

4. **Health check:**

   ```bash
   curl http://127.0.0.1:8080/api/health
   ```

### Optional Local Helpers

- Scheduler loop:

  ```bash
  ./backend/run-scheduler.sh
  ```

- Seed test users + run provisioning:

  ```bash
  ./seed-test-users.sh
  ```

- LDAP login check helper:

  ```bash
  ./check-ldap-login.sh <username> <password>
  ```

## Docker Compose Runtime

Starts LDAP, backend, dashboard build, nginx gateway, and one-shot bootstrap.

```bash
docker compose up -d --build
```

Recommended fresh start (resets LDAP volumes, SQLite, rebuilds dashboard image):

```bash
./docker_start.sh
```

Windows CMD helper:

```bat
start_docker.bat
```

Default endpoints:

- UI + API gateway: `http://127.0.0.1:8088`
- API health via gateway: `http://127.0.0.1:8088/api/health`
- LDAP from host tools: `ldap://127.0.0.1:3389`

Key compose behavior:

- backend LDAP bind target is internal `ldap://ldap:389`
- backend SQLite defaults to `storage/idm_docker.sqlite`
- the one-shot `bootstrap` service runs [`ops/bootstrap_fresh_ldap_idm.sh`](ops/bootstrap_fresh_ldap_idm.sh) against `backend` and `ldap`; by default `BOOTSTRAP_MARKER_ENABLE` is off so each bootstrap container run performs full LDAP + IDM seeding (set `BOOTSTRAP_MARKER_ENABLE=1` to skip when `storage/.docker_first_bootstrap_done` exists)

Force a fresh Docker bootstrap manually (same idea as `./docker_start.sh`):

```bash
docker compose down -v
rm -f storage/.docker_first_bootstrap_done storage/idm_docker.sqlite
docker compose up -d --build
```

## API Reference

Base URL (local): `http://127.0.0.1:8080`

Auth/session:

- `GET /api/health`
- `POST /api/auth/login`
- `GET /api/auth/me`

Provisioning/reconcile:

- `POST /api/provision/upload` (multipart field `csv`)
- `POST /api/provision/run-poll`
- `POST /api/reconcile/run` (supports body option `syncPasswords`)

Source users (SQLite):

- `GET /api/source-users`
- `POST /api/source-users`
- `PUT /api/source-users/{user}`
- `DELETE /api/source-users/{user}`

Approvals:

- `GET /api/approvals`
- `POST /api/approvals/{id}/approve`
- `POST /api/approvals/{id}/reject`

Metrics/events:

- `GET /api/metrics`

LDAP browser/search/edit/export:

- `GET /api/ldap/tree`
- `GET /api/ldap/subtree?dn=...`
- `GET /api/ldap/subtree/{encodedDn}`
- `GET /api/ldap/tree/node?dn=...`
- `GET /api/ldap/self/node`
- `GET /api/ldap/search?q=...`
- `POST /api/ldap/entry/update`
- `GET /api/ldap/export?q=...`

User operations:

- `GET /api/users`
- `POST /api/users/{user}/password`

## RBAC Model

Permissions are resolved from LDAP groups in backend `RbacService`:

- `idm-ldap-viewers` -> LDAP browse/search/view + dashboard LDAP/profile/metrics view
- `idm-ldap-editors` -> `ldap.edit`
- `idm-ldap-exporters` -> `ldap.export`
- `idm-ops-admins` -> full operational permissions (provision, reconcile, approvals, user password changes, metrics events, LDAP actions)

Bootstrap override:

- when `RBAC_BOOTSTRAP_ADMIN=true`, `ADMIN_USERNAME` receives all permissions

## Common API Calls

Login:

```bash
curl -X POST http://127.0.0.1:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"alphaadmin","password":"123"}'
```

Import source CSV:

```bash
curl -X POST http://127.0.0.1:8080/api/provision/upload \
  -F "csv=@storage/csv/bootstrap_30_users.csv"
```

Run provisioning:

```bash
curl -X POST http://127.0.0.1:8080/api/provision/run-poll \
  -H 'Content-Type: application/json' \
  -d '{}'
```

Run reconcile with password sync:

```bash
curl -X POST http://127.0.0.1:8080/api/reconcile/run \
  -H 'Content-Type: application/json' \
  -d '{"syncPasswords":true}'
```

## Testing

Smoke test:

```bash
php tests/alpha_smoke.php
```

Backend PHPUnit:

```bash
cd backend
composer test
```

Dashboard typecheck/build:

```bash
cd dashboard
npm run typecheck
npm run build
```

## Security Notes

- Current auth token is simple (`base64(username)`) and intended for alpha/dev use.
- Harden for production with real token/session security, secret management, and stricter transport controls.
