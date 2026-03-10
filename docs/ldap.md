# LDAP

This page documents the deterministic local LDAP contract that is used to test LDAP search, import, and SSO flows in
Easy!Appointments.

> Note: This guide refers to the available Docker development configuration using docker-compose.yml

## Canonical Local Workflow

The canonical local workflow is seed-driven, not phpLDAPadmin-driven:

```bash
docker compose up -d openldap phpldapadmin
bash ./scripts/ldap/reset_directory.sh
bash ./scripts/ldap/smoke.sh
```

The versioned LDAP fixture lives in `docker/ldap/seed`, while the generated OpenLDAP runtime state under
`docker/openldap/slapd/*` remains local-only and disposable.

For the parallel replacement candidate introduced in `ROB-92`, see [LDAP parallel replacement spike](ldap-parallel-spike.md).

By default, OpenLDAP is configured to run on `localhost:389`, so it can be accessed on the host machine from this
address.

In the internal Docker Compose network the equivalent address would be openldap:389. 

Similarly, phpLDAPadmin is accessible in the host environment via `localhost:8200`, so it can be accessed on the host
machine from this address.

phpLDAPadmin is optional and should be treated as an inspection/debugging tool. The seeded CLI workflow is the source
of truth for local LDAP state.

## Local Bind Contract

Once the local Docker environment is running, you can log in to phpLDAPadmin with the default admin user, by using the
following credentials:

- URL: http://localhost:8200
- User DN: cn=admin,dc=example,dc=org
- Password: admin

The deterministic readonly bind used by the local smoke is:

- User DN: `cn=user,dc=example,dc=org`
- Password: `password`

The seeded people entries live below `ou=people,dc=example,dc=org`.

## Expected App-Facing Attributes

The local fixture keeps the current app contract explicit. Search/import/SSO flows are expected to find these
attributes on seeded entries:

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

For this you will need to go to the Backend > Settings > Integrations > LDAP and enable the integration from there. 

This settings page also requires some configuration for Easy!Appointments to be able to connect to the server and pull
the right information.

#### Host 

The server host address, provide "openldap" for Docker or your own host or IP.

#### Port

The server port number, provider 389 for Docker or your own server port value.

#### User DN 

Enter the admin user domain (DN) value which is `cn=admin,dc=example,dc=org` for Docker or set your own server value.

#### Password

Enter the admin user password value which is `admin` for Docker or set your own server value.

#### Base DN

Enter the base domain (DN) value, provide `dc=example,dc=org` for Docker or a custom value as defined on your server.

#### Filter 

This field has a default value which will work in most cases and will allow you to filter the right records out of the
LDAP directory. Change this if you need to filter the directory in a different way. The filter will interpolate the
{{KEYWORD}} string with the actual keyword value for filtering the LDAP entries.

#### Field Mapping

In order to save some time while importing user records, you can define the field mapping so that the required values 
are prefilled within the LDAP import modal. This is a key-value json object.

## Importing Users

After the configuration of LDAP is complete, you can use the "search" function of the same settings page to look for
users (just submitting queries using the form).

If the filter was right, you will be getting LDAP entries displayed on screen that can be imported into Easy!Appointments.

Clicking the "Import" button a modal with a user form will be opened, allowing you to import this entry and change their
information before doing that. 

Backend users (admin/secretary/provider) require a local password before being imported. If you do not plan to use the
local log in credentials then set a long random value instead.

Make sure that the "LDAP DN" value is correct and points to the right entry on LDAP. Also the username will be used to
detect the user, so you should keep the local username-aligned attribute deterministic. In the shipped fixture both
`cn` and `uid` are `ada`.

## LDAP SSO

While SSO with LDAP on Easy!Appointments, the username will need to be matched for the SSO to work. Also, the LDAP DN
value must be valid and point to the right record.

The LDAP DN is a value that can also be manually set, either from the UI or from the API.

If LDAP is enabled, Easy!Appointments will first try to check for the local credentials, but if this does not work, then
the app will try to check the password against the LDAP entry and log the user in.

*This document applies to Easy!Appointments v1.5.2.*

[Back](readme.md)
