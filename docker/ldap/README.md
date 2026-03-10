# Local LDAP Contract Fixtures

This directory contains the versioned LDAP fixture contract for local development.

- `seed/*.ldif` is the canonical source for the deterministic local directory state.
- `vegardit/*.ldif` contains the first-launch bootstrap fixtures for the parallel replacement spike.
- Runtime-generated OpenLDAP state under `docker/openldap/slapd/*` stays gitignored.
- Runtime-generated parallel candidate state under `docker/openldap-parallel/*` stays gitignored.
- `bash ./scripts/ldap/reset_directory.sh` recreates the local directory from these fixtures.
- `bash ./scripts/ldap/smoke.sh` verifies the expected bind and search contract.

Current contract:

- Base DN: `dc=example,dc=org`
- Admin bind: `cn=admin,dc=example,dc=org` / `admin`
- Readonly bind: `cn=user,dc=example,dc=org` / `password`
- Seeded users live under `ou=people,dc=example,dc=org`
- Expected app-facing attributes: `cn`, `sn`, `givenName`, `mail`, `uid`, `telephoneNumber`, `dn`
