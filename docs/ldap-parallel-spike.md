# LDAP Parallel Replacement Spike

This document captures the `ROB-92` parallel replacement validation that was promoted to the default helper stack in
`ROB-93`.

## Candidate

- Preferred replacement image: `vegardit/openldap@sha256:efa7cff027fa2ac503b073161849f93e4ccaaa1b9ac0a9788814865032952564`
- Scope posture at validation time: parallel-only, no cutover of the default `openldap` hostname or `389` host port

The image documentation states that first-launch configuration comes from `LDAP_INIT_*` environment variables and that
custom bootstrap LDIFs can be mounted at `/opt/ldifs/init_org_tree.ldif` and `/opt/ldifs/init_org_entries.ldif`.
Source: [vegardit/docker-openldap README](https://github.com/vegardit/docker-openldap)

## Parallel Stack Contract

| Contract element | Pre-cutover legacy stack | Validated replacement |
| --- | --- | --- |
| Compose service | `openldap` | `openldap-parallel` during validation, `openldap` after `ROB-93` |
| Image | `osixia/openldap:1.5.0` | `vegardit/openldap@sha256:efa7cff027fa2ac503b073161849f93e4ccaaa1b9ac0a9788814865032952564` |
| Host ports | `389`, `636` | `1389`, `1636` |
| Runtime state path | `docker/openldap-legacy/slapd/{database,config}` | `docker/openldap/{var,etc}` after cutover |
| Seed/bootstrap source | `docker/ldap/seed/*.ldif` via `ldapadd` after start | `docker/ldap/vegardit/init_org_{tree,entries}.ldif` on first launch |
| Admin bind DN | `cn=admin,dc=example,dc=org` | `cn=admin,dc=example,dc=org` |
| Readonly bind DN | `cn=user,dc=example,dc=org` | `cn=user,dc=example,dc=org` |
| Searchable fixture entry | `uid=ada,ou=people,dc=example,dc=org` | `uid=ada,ou=people,dc=example,dc=org` |
| Expected app-facing attrs | `cn`, `sn`, `givenName`, `mail`, `uid`, `telephoneNumber`, `dn` | same |

Observed runtime nuance:

- `vegardit/openldap` exposes a temporary init `slapd` before the final runtime `slapd` restart. The shared LDAP helper scripts therefore wait for two consecutive successful binds before treating the service as ready.

## Local Commands

Current default reset command after the cutover:

```bash
bash ./scripts/ldap/reset_directory.sh
```

Current default smoke command:

```bash
bash ./scripts/ldap/smoke.sh
```

Legacy fallback reference path after the cutover:

```bash
docker compose --profile ldap-legacy up -d openldap-legacy
LDAP_SERVICE_NAME=openldap-legacy bash ./scripts/ldap/reset_directory.sh
LDAP_SERVICE_NAME=openldap-legacy bash ./scripts/ldap/smoke.sh
```

Historical validation host-port check during the parallel phase:

```bash
ldapsearch -x -H ldap://127.0.0.1:1389 \
  -D 'cn=admin,dc=example,dc=org' -w admin \
  -b 'dc=example,dc=org' '(uid=ada)' cn uid mail -LLL
```

The original parallel-only service from `ROB-92` has now been folded into the default `openldap` path. The documented
rollback/reference path is the profiled legacy stack above.

Host-port caveat during the validation phase:

- `1389` and `1636` are intentionally separate from the legacy stack, but they are still host-global. Only one parallel replacement stack can bind them on the same machine at a time.
