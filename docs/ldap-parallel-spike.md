# LDAP Parallel Replacement Spike

This document captures the `ROB-92` parallel replacement validation for the local LDAP helper stack.

## Candidate

- Preferred replacement image: `vegardit/openldap@sha256:efa7cff027fa2ac503b073161849f93e4ccaaa1b9ac0a9788814865032952564`
- Scope posture: parallel-only, no cutover of the default `openldap` hostname or `389` host port

The image documentation states that first-launch configuration comes from `LDAP_INIT_*` environment variables and that
custom bootstrap LDIFs can be mounted at `/opt/ldifs/init_org_tree.ldif` and `/opt/ldifs/init_org_entries.ldif`.
Source: [vegardit/docker-openldap README](https://github.com/vegardit/docker-openldap)

## Parallel Stack Contract

| Contract element | Legacy stack | Parallel candidate |
| --- | --- | --- |
| Compose service | `openldap` | `openldap-parallel` |
| Image | `osixia/openldap:1.5.0` | `vegardit/openldap@sha256:efa7cff027fa2ac503b073161849f93e4ccaaa1b9ac0a9788814865032952564` |
| Host ports | `389`, `636` | `1389`, `1636` |
| Runtime state path | `docker/openldap/slapd/{database,config}` | `docker/openldap-parallel/{var,etc}` |
| Seed/bootstrap source | `docker/ldap/seed/*.ldif` via `ldapadd` after start | `docker/ldap/vegardit/init_org_{tree,entries}.ldif` on first launch |
| Admin bind DN | `cn=admin,dc=example,dc=org` | `cn=admin,dc=example,dc=org` |
| Readonly bind DN | `cn=user,dc=example,dc=org` | `cn=user,dc=example,dc=org` |
| Searchable fixture entry | `uid=ada,ou=people,dc=example,dc=org` | `uid=ada,ou=people,dc=example,dc=org` |
| Expected app-facing attrs | `cn`, `sn`, `givenName`, `mail`, `uid`, `telephoneNumber`, `dn` | same |

Observed runtime nuance:

- `vegardit/openldap` exposes a temporary init `slapd` before the final runtime `slapd` restart. The shared LDAP helper scripts therefore wait for two consecutive successful binds before treating the service as ready.

## Local Commands

Reset the parallel candidate from a clean data path:

```bash
LDAP_SERVICE_NAME=openldap-parallel \
bash ./scripts/ldap/reset_directory.sh
```

Smoke the candidate against the same bind/search contract:

```bash
LDAP_SERVICE_NAME=openldap-parallel \
bash ./scripts/ldap/smoke.sh
```

Validate the dedicated host port explicitly:

```bash
ldapsearch -x -H ldap://127.0.0.1:1389 \
  -D 'cn=admin,dc=example,dc=org' -w admin \
  -b 'dc=example,dc=org' '(uid=ada)' cn uid mail -LLL
```

The parallel service can be started explicitly without affecting the default dev stack:

```bash
docker compose --profile ldap-parallel up -d openldap-parallel
```

Host-port caveat:

- `1389` and `1636` are intentionally separate from the legacy stack, but they are still host-global. Only one parallel replacement stack can bind them on the same machine at a time.
