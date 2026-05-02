# How To Run IDM

### Docker (fresh LDAP volumes, rebuilt dashboard deps, automatic bootstrap)

```bash
./docker_start.sh
```

Then open **http://localhost:8088**.

Each run stops the stack, removes LDAP compose volumes (unless `FRESH_KEEP_LDAP_VOLUMES=1`), deletes the Docker SQLite DB and bootstrap marker, rebuilds images (`npm ci` in the dashboard build), and starts the stack so the one-shot bootstrap container repopulates LDAP and IDM.

### Local dashboard dev (not Docker)

Install Node dependencies once, then use your usual dev server:

```bash
cd dashboard
npm i
```
