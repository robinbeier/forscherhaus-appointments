# LDAP

This page documents the deterministic local LDAP contract used by Easy!Appointments for LDAP login.

> Note: This guide refers to the available Docker development configuration using docker-compose.yml

## Canonical Local Workflow

The canonical local workflow is fixture-driven and does not depend on a bundled LDAP admin UI:

```bash
docker compose up -d openldap
bash ./scripts/ldap/reset_directory.sh
bash ./scripts/ldap/smoke.sh
```

The default helper stack now uses the versioned `docker/ldap/vegardit` bootstrap fixtures, while the generated runtime
state under `docker/openldap/{var,etc}` remains local-only and disposable.

By default, OpenLDAP is configured to run on `localhost:389`, so it can be accessed on the host machine from this
address. In the internal Docker Compose network the equivalent address is `openldap:389`.

The seeded CLI workflow is the source of truth for local LDAP state. The default Docker stack no longer ships
`phpLDAPadmin`, and the repo no longer carries an `openldap-legacy` fallback profile. If you need ad-hoc inspection,
use LDAP-native tooling against the deterministic fixture instead of a repo-managed sidecar or legacy image path.

## Local Bind Contract

The deterministic admin bind for local directory inspection is:

- User DN: `cn=admin,dc=example,dc=org`
- Password: `admin`

The deterministic readonly bind used by the local smoke is:

- User DN: `cn=user,dc=example,dc=org`
- Password: `password`

The seeded people entries live below `ou=people,dc=example,dc=org`.

## Local Fixture Attributes

LDAP login uses the stored `ldap_dn` on the local user record and checks the submitted password against that entry.
The standalone directory smoke additionally checks these attributes on the synthetic fixture:

- `cn`
- `sn`
- `givenName`
- `mail`
- `uid`
- `telephoneNumber`
- `dn`

The current deterministic seeded user is:

- DN: `uid=ada,ou=people,dc=example,dc=org`
- Username-relevant attributes: `cn=ada`, `uid=ada`
- Password: `ada-local-pass`

## Enabling Integration in Easy!Appointments

After making sure that the local OpenLDAP server works, Easy!Appointments will be able to connect to it.

Open Backend > Settings > Integrations > LDAP to configure the host and port and enable LDAP login.

#### Host

The server host address, provide "openldap" for Docker or your own host or IP.

#### Port

The server port number, provide 389 for Docker or your own server port value.

## LDAP SSO

For an existing local user, set the `ldap_dn` value through the supported user administration workflow so it points to
the matching directory entry. When LDAP is enabled, Easy!Appointments first checks local credentials; if that does not
match, it binds against the stored `ldap_dn` and logs the user in with the submitted LDAP password.

The local fixture uses `uid=ada,ou=people,dc=example,dc=org` for the seeded user. The LDAP settings page retains only
the integration switch, host, and port; directory inspection and user provisioning are outside that page.

[Back](readme.md)
