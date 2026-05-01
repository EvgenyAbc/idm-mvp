# How To Run IDM Alpha

## 1) Prerequisites

Make sure these are installed:

- OpenLDAP client tools: `ldapadd`, `ldapmodify`, `ldapsearch`, `ldapwhoami`, `slappasswd`
- PHP 8+
- Node.js 18+ and npm
- Running LDAP server reachable from this machine

## 2) Configure Environment

Set LDAP environment variables before starting backend:

```bash
export LDAP_BASE_DN="dc=example,dc=com"
export LDAP_ADMIN_DN="cn=admin,dc=example,dc=com"
export LDAP_ADMIN_PASSWORD="123"
```

You can use values from `backend/.env.example` as a reference.

Important for LDAP Tree Explorer in dashboard:

- Tree/subtree APIs use backend bind credentials (`LDAP_ADMIN_DN` + `LDAP_ADMIN_PASSWORD`).
- If CLI `ldapsearch` works anonymously but dashboard tree is empty, verify backend env values and restart backend.

## 3) Prepare LDAP Structure (one-time)

From project root:

```bash
./ops/ldap/add_structure.sh
./ops/ldap/add_group_and_user.sh
```

## 3.1) One-Command Fresh Bootstrap (LDAP + IDM, 30 users)

This is the recommended setup for a clean LDAP installation. It will:
- reset LDAP users/groups (under `ou=People` and POSIX groups under `ou=Groups`),
- seed canonical users from `storage/csv/bootstrap_30_users.csv`,
- reset IDM `source_users` via import of the same CSV,
- run provisioning and reconciliation (`syncPasswords=true`),
- verify LDAP count and IDM count are both 30.

```bash
./ops/bootstrap_fresh_ldap_idm.sh
```

Optional overrides:

```bash
LDAP_BASE_DN="dc=example,dc=com" \
LDAP_ADMIN_DN="cn=admin,dc=example,dc=com" \
LDAP_ADMIN_PASSWORD="123" \
API_BASE="http://127.0.0.1:8080" \
CSV_PATH="storage/csv/bootstrap_30_users.csv" \
RESET_LDAP=1 \
RESET_IDM_DB=1 \
AUTO_START_BACKEND=1 \
./ops/bootstrap_fresh_ldap_idm.sh
```

## 4) Start Backend API

From project root:

```bash
./start-backend.sh
```

Backend URL: `http://127.0.0.1:8080`

Health check:

```bash
curl http://127.0.0.1:8080/api/health
```

## 5) Start React Dashboard

Open a second terminal in project root:

```bash
./start-dashboard.sh
```

Dashboard URL (default Vite): `http://127.0.0.1:5173`

Login uses LDAP user credentials.

## 6) Import Source Data (Optional)

The canonical bootstrap file is:

`storage/csv/bootstrap_30_users.csv`

Import it into SQLite source_users:

```bash
curl -X POST http://127.0.0.1:8080/api/provision/upload \
  -F "csv=@storage/csv/bootstrap_30_users.csv"
```

## 7) Run Provisioning / Reconciliation

Run provisioning from SQLite source:

```bash
curl -X POST http://127.0.0.1:8080/api/provision/run-poll \
  -H 'Content-Type: application/json' \
  -d '{}'
```

```bash
curl -X POST http://127.0.0.1:8080/api/reconcile/run \
  -H 'Content-Type: application/json' \
  -d '{}'
```

The JSON response includes counts such as `drift_detected`, `drift_remediated`, and `drift_skipped_invalid_csv`: reconciliation audits when LDAP `labeledURI` differs from source `httpUrl` for the same user, overwrites LDAP from source when the URL policy allows (see README), and still quarantines LDAP-only users missing from source.

## 8) Optional Scheduler Mode

In another terminal:

```bash
./backend/run-scheduler.sh
```

Optional overrides:

```bash
API_URL="http://127.0.0.1:8080/api/provision/run-poll" \
INTERVAL_SECONDS=60 \
./backend/run-scheduler.sh
```

## 9) Smoke Test

```bash
php tests/alpha_smoke.php
```

If SQLite PDO driver is missing, the smoke test reports `SKIPPED`.

## 10) Seed Test Users + Quick Login

Seed pre-defined test users for dashboard quick login buttons:

```bash
./seed-test-users.sh
```

This imports CSV file `storage/csv/bootstrap_30_users.csv` into SQLite source and then runs `POST /api/provision/run-poll`.

Default quick login users in dashboard:

- `jdoe` / `123`
- `asmith` / `123`
- `alphaadmin` / `123`

## 11) Seed 500 E-commerce Users, OUs, and Roles

Seed a larger LDAP dataset with:

- 12 business units (OUs) under `ou=People`
- 12 role groups under `ou=Groups`
- 500 users (`ecom0001` ... `ecom0500`) distributed across units/roles

Run:

```bash
LDAP_ADMIN_PASSWORD="123" ./ops/ldap/seed_ecom_500.sh
```

Optional overrides:

```bash
LDAP_BASE_DN="dc=example,dc=com" \
LDAP_ADMIN_DN="cn=admin,dc=example,dc=com" \
LDAP_ADMIN_PASSWORD="123" \
USER_COUNT=500 \
./ops/ldap/seed_ecom_500.sh
```

Default seeded user password pattern:

- `ecom0001` -> `Pass!1`
- `ecom0002` -> `Pass!2`
- ...

## 12) Seed LDAP RBAC Groups For Browser Access (Beta)

Create RBAC groups and sample membership mapping:

```bash
LDAP_ADMIN_PASSWORD="123" ./ops/ldap/seed_rbac_groups.sh
```

Default groups:

- `idm-ldap-viewers` -> `ldap.search`, `ldap.browse`, `ldap.view_attributes`
- `idm-ldap-editors` -> `ldap.edit`
- `idm-ldap-exporters` -> `ldap.export`
- `idm-ops-admins` -> full LDAP UI (`ldap.search`, `ldap.browse`, `ldap.view_attributes`, `ldap.edit`, `ldap.export`) plus `provision.run`, `reconcile.run`, `approval.decide`, `users.password.change`, `metrics.events.view`

Default membership seeded by script:

- `jdoe` -> viewers
- `asmith` -> viewers + editors
- `alphaadmin` -> viewers + editors + exporters + ops-admins

## 13) Beta RBAC Smoke Checklist

1. Login as `jdoe`:
   - LDAP tree visible
   - LDAP search works
   - Edit/export hidden and blocked by API
2. Login as `asmith`:
   - Search + tree visible
   - Edit allowed attributes works
   - Export blocked
3. Login as `alphaadmin`:
   - Provision/reconcile actions allowed
   - Approval decisions allowed
   - Password change allowed
   - LDAP export download works
4. API checks (direct calls with token):
   - `GET /api/ldap/tree` denied for users without `ldap.browse`
   - `GET /api/ldap/subtree?dn=ou=People,dc=example,dc=com` denied for users without `ldap.browse`
   - `GET /api/ldap/subtree/ou%3DPeople%2Cdc%3Dexample%2Cdc%3Dcom` denied for users without `ldap.browse`
   - `GET /api/ldap/tree/node?dn=...` denied for users without `ldap.browse`
   - `GET /api/ldap/search?q=...` denied for users without `ldap.search`
   - `POST /api/ldap/entry/update` denied for users without `ldap.edit`
   - `GET /api/ldap/export` denied for users without `ldap.export`

## 14) Beta 0.5 Tree View Route Checks

1. Login as a user with `ldap.browse` permission (for example `jdoe`).
2. In **LDAP Directory Tree**, select any object DN.
3. Click **Node View**:
   - Node detail loads from `GET /api/ldap/tree/node?dn=...`
   - DN and attributes are shown in the Node View panel
   - Children metadata (`childrenCount`, `hasChildren`) is visible
4. From search results, click an entry:
   - selection updates
   - Node View opens for that DN
5. Switch back to **Tree** and confirm existing tree/search/edit/export behavior still works.

## 15) Docker Compose Runtime (Nginx + Backend + Dashboard + LDAP)

From project root:

```bash
docker compose up -d --build
```

Windows CMD (from project root):

```bat
start_docker.bat
```

First-run behavior:

- Docker backend DB defaults to `storage/idm_docker.sqlite` (`SQLITE_DB_PATH` override supported).
- One-shot `bootstrap` container runs after LDAP/backend health checks, seeds LDAP from `storage/csv/bootstrap_30_users.csv`, imports source users into SQLite, and then exits.
- Bootstrap writes marker `storage/.docker_first_bootstrap_done`; future `docker compose up` runs skip reseeding while marker exists.

Force a fresh bootstrap:

```bash
docker compose down -v
rm -f storage/.docker_first_bootstrap_done storage/idm_docker.sqlite
docker compose up -d --build
```

Default endpoints:

- GUI (through Nginx): `http://127.0.0.1:8088`
- API health (through Nginx): `http://127.0.0.1:8088/api/health`
- LDAP from host tools: `ldap://127.0.0.1:3389`

Useful checks:

```bash
docker compose ps
curl http://127.0.0.1:8088/api/health
ldapsearch -x -H ldap://127.0.0.1:3389 -D "cn=admin,dc=example,dc=com" -w "123" -b "dc=example,dc=com" -s base dn
```

Stop and clean runtime containers:

```bash
docker compose down
```

Override ports if needed:

```bash
HTTP_PORT=8080 LDAP_PORT=3389 docker compose up -d --build
```
