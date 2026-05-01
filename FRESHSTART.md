# How To Run IDM

### install node.js deps:

```bash
cd dashboard
npm i
```

### start docker:

```bash
./docker_start.sh
```

### populate data:

```bash
./ops/bootstrap_fresh_ldap_idm.sh
```

### open in browser:

http://localhost:8088
