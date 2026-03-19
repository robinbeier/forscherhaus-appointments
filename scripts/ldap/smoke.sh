#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

LDAP_ADMIN_DN="${LDAP_ADMIN_DN:-cn=admin,dc=example,dc=org}"
LDAP_ADMIN_PASSWORD="${LDAP_ADMIN_PASSWORD:-admin}"
LDAP_READONLY_DN="${LDAP_READONLY_DN:-cn=user,dc=example,dc=org}"
LDAP_READONLY_PASSWORD="${LDAP_READONLY_PASSWORD:-password}"
LDAP_BASE_DN="${LDAP_BASE_DN:-dc=example,dc=org}"
LDAP_URI="${LDAP_URI:-ldap://localhost:389}"
LDAP_COMPOSE_PROJECT_NAME="${LDAP_COMPOSE_PROJECT_NAME:-}"
LDAP_SERVICE_NAME="${LDAP_SERVICE_NAME:-openldap}"
LDAP_EXPECTED_UID="${LDAP_EXPECTED_UID:-ada}"
LDAP_EXPECTED_GIVEN_NAME="${LDAP_EXPECTED_GIVEN_NAME:-Ada}"
LDAP_EXPECTED_SN="${LDAP_EXPECTED_SN:-Lovelace}"
LDAP_EXPECTED_MAIL="${LDAP_EXPECTED_MAIL:-ada.lovelace@example.org}"
LDAP_EXPECTED_PHONE_NUMBER="${LDAP_EXPECTED_PHONE_NUMBER:-+49 30 1234567}"

compose_cmd=(docker compose)

if [[ -n "${LDAP_COMPOSE_PROJECT_NAME}" ]]; then
    compose_cmd+=(-p "${LDAP_COMPOSE_PROJECT_NAME}")
fi

compose() {
    (
        cd "${REPO_ROOT}"
        "${compose_cmd[@]}" "$@"
    )
}

ensure_ldap_service_exists() {
    local services
    local services_stderr
    local exit_code

    services_stderr="$(mktemp)"

    if services="$(compose config --services 2>"${services_stderr}")"; then
        rm -f "${services_stderr}"
    else
        exit_code=$?
        echo "[FAIL] Failed to inspect compose services:" >&2
        cat "${services_stderr}" >&2
        rm -f "${services_stderr}"
        return "${exit_code}"
    fi

    if ! grep -Fxq "${LDAP_SERVICE_NAME}" <<<"${services}"; then
        echo "[FAIL] Unknown LDAP service: ${LDAP_SERVICE_NAME}" >&2
        echo "Available services:" >&2
        printf '%s\n' "${services}" >&2
        return 1
    fi
}

run_ldap() {
    compose exec -T "${LDAP_SERVICE_NAME}" "$@"
}

wait_for_openldap() {
    local attempt

    for attempt in $(seq 1 60); do
        if run_ldap ldapwhoami -x -H "${LDAP_URI}" -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" >/dev/null 2>&1; then
            # Some images expose a temporary init slapd before their final runtime restart.
            sleep 2

            if run_ldap ldapwhoami -x -H "${LDAP_URI}" -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" >/dev/null 2>&1; then
                return 0
            fi
        fi

        sleep 1
    done

    echo "[FAIL] LDAP service ${LDAP_SERVICE_NAME} readiness timeout" >&2

    return 1
}

assert_output_contains() {
    local haystack="$1"
    local needle="$2"
    local label="$3"

    if ! grep -Fq "${needle}" <<<"${haystack}"; then
        echo "[FAIL] ${label}: expected '${needle}'" >&2
        echo "${haystack}" >&2
        exit 1
    fi

    echo "[PASS] ${label}"
}

main() {
    local admin_whoami
    local readonly_whoami
    local base_search
    local user_search

    ensure_ldap_service_exists

    compose up -d "${LDAP_SERVICE_NAME}" >/dev/null
    wait_for_openldap

    admin_whoami="$(run_ldap ldapwhoami -x -H "${LDAP_URI}" -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}")"
    assert_output_contains "${admin_whoami}" "dn:${LDAP_ADMIN_DN}" "admin bind"

    readonly_whoami="$(run_ldap ldapwhoami -x -H "${LDAP_URI}" -D "${LDAP_READONLY_DN}" -w "${LDAP_READONLY_PASSWORD}")"
    assert_output_contains "${readonly_whoami}" "dn:${LDAP_READONLY_DN}" "readonly bind"

    base_search="$(run_ldap ldapsearch -x -H "${LDAP_URI}" -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -b "${LDAP_BASE_DN}" -s base -LLL)"
    assert_output_contains "${base_search}" "dn: ${LDAP_BASE_DN}" "base search dn"
    assert_output_contains "${base_search}" "dc: example" "base search dc"

    user_search="$(run_ldap ldapsearch -x -H "${LDAP_URI}" -D "${LDAP_ADMIN_DN}" -w "${LDAP_ADMIN_PASSWORD}" -b "${LDAP_BASE_DN}" "(uid=${LDAP_EXPECTED_UID})" cn sn givenName mail uid telephoneNumber dn -LLL)"
    assert_output_contains "${user_search}" "dn: uid=${LDAP_EXPECTED_UID},ou=people,${LDAP_BASE_DN}" "seeded user dn"
    assert_output_contains "${user_search}" "cn: ${LDAP_EXPECTED_UID}" "seeded user cn"
    assert_output_contains "${user_search}" "givenName: ${LDAP_EXPECTED_GIVEN_NAME}" "seeded user givenName"
    assert_output_contains "${user_search}" "sn: ${LDAP_EXPECTED_SN}" "seeded user sn"
    assert_output_contains "${user_search}" "mail: ${LDAP_EXPECTED_MAIL}" "seeded user mail"
    assert_output_contains "${user_search}" "uid: ${LDAP_EXPECTED_UID}" "seeded user uid"
    assert_output_contains "${user_search}" "telephoneNumber: ${LDAP_EXPECTED_PHONE_NUMBER}" "seeded user telephoneNumber"

    echo "[PASS] LDAP smoke completed."
}

main "$@"
