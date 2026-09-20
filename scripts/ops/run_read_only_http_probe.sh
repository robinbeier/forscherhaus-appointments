#!/usr/bin/env bash
set -Eeuo pipefail
set +x

# Read-only anonymous booking-download contract. Capabilities are generated
# privately and are never printed, persisted, or included in the receipt.
readonly PROBE='anonymous_booking_download_capabilities'
readonly PROD_ORIGIN='https://dasforscherhaus-leg.de'
BASE_URL="${READ_ONLY_PROBE_BASE_URL:-${PROD_ORIGIN}}"
OUTCOME='unknown'
EXIT_CODE=70
RECEIPT_EMITTED=0
TEMP_FILES=()

CHECK_MODERN_CONFIRMATION='false'
CHECK_LEGACY_CONFIRMATION='false'
CHECK_MODERN_ICS_MISSING='false'
CHECK_LEGACY_ICS_MISSING='false'
CHECK_MODERN_ICS_HEADERS='false'
CHECK_LEGACY_ICS_HEADERS='false'
REDIRECT_MODERN='malformed'
REDIRECT_LEGACY='malformed'
ICS_HEADERS_MODERN='malformed'
ICS_HEADERS_LEGACY='malformed'

emit_receipt() {
    [[ "${RECEIPT_EMITTED}" == '1' ]] && return
    RECEIPT_EMITTED=1
    printf '{"schema":"read_only_probe.v1","probe":"%s","outcome":"%s","exit_code":%s,"checks":{"modern_confirmation_redirect":%s,"legacy_confirmation_redirect":%s,"modern_ics_missing":%s,"legacy_ics_missing":%s,"modern_ics_headers_safe":%s,"legacy_ics_headers_safe":%s},"redirect_class":{"modern":"%s","legacy":"%s"},"ics_header_class":{"modern":"%s","legacy":"%s"},"check_count":6,"cleanup":"not_applicable"}\n' \
        "${PROBE}" "${OUTCOME}" "${EXIT_CODE}" \
        "${CHECK_MODERN_CONFIRMATION}" "${CHECK_LEGACY_CONFIRMATION}" \
        "${CHECK_MODERN_ICS_MISSING}" "${CHECK_LEGACY_ICS_MISSING}" \
        "${CHECK_MODERN_ICS_HEADERS}" "${CHECK_LEGACY_ICS_HEADERS}" \
        "${REDIRECT_MODERN}" "${REDIRECT_LEGACY}" \
        "${ICS_HEADERS_MODERN}" "${ICS_HEADERS_LEGACY}"
}

finish() {
    local status=$?
    trap - EXIT HUP INT TERM
    if [[ "${RECEIPT_EMITTED}" == '0' ]]; then
        if [[ "${status}" != '0' && "${EXIT_CODE}" == '0' ]]; then
            OUTCOME='unknown'
            EXIT_CODE=70
        fi
        emit_receipt
    fi
    if ((${#TEMP_FILES[@]} > 0)); then
        for file in "${TEMP_FILES[@]}"; do
            rm -f -- "${file}" 2>/dev/null || true
        done
    fi
    exit "${EXIT_CODE}"
}
trap finish EXIT
trap 'OUTCOME=unknown; EXIT_CODE=70; exit 70' HUP INT TERM

die_environment() { OUTCOME='environment_failed'; EXIT_CODE=21; exit 21; }
die_application() { OUTCOME='application_failed'; EXIT_CODE=20; exit 20; }
die_unknown() { OUTCOME='unknown'; EXIT_CODE=70; exit 70; }

if [[ "${BASE_URL}" != "${PROD_ORIGIN}" && ! "${BASE_URL}" =~ ^http://127\.0\.0\.1:[1-9][0-9]*$ ]]; then
    die_unknown
fi
for command_name in curl od tr mktemp awk; do
    command -v "${command_name}" >/dev/null 2>&1 || die_environment
done

modern_capability="$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')" || die_environment
legacy_capability="$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')" || die_environment
[[ "${modern_capability}" =~ ^[0-9a-f]{64}$ ]] || die_environment
[[ "${legacy_capability}" =~ ^[0-9a-f]{12}$ ]] || die_environment

request() {
    local route="$1"
    local result_name="$2"
    local header_file
    local http_status
    local curl_status
    header_file="$(mktemp)" || die_environment
    TEMP_FILES+=("${header_file}")
    set +e
    http_status="$(curl --silent --show-error --max-time 15 --dump-header "${header_file}" --output /dev/null --write-out '%{http_code}' "${BASE_URL}/index.php/${route}" 2>/dev/null)"
    curl_status=$?
    set -e
    [[ "${curl_status}" == '0' ]] || die_environment
    [[ "${http_status}" =~ ^[1-5][0-9]{2}$ ]] || die_unknown
    printf -v "${result_name}_status" '%s' "${http_status}"
    printf -v "${result_name}_headers" '%s' "${header_file}"
}

redirect_class() {
    local header_file="$1"
    local location
    local status="$2"
    if [[ "${status}" != '307' ]]; then
        printf 'unexpected'
        return
    fi
    location="$(awk 'tolower($1)=="location:" {sub(/^[^:]*:[[:space:]]*/, ""); print; exit}' "${header_file}")" || die_environment
    location="${location//$'\r'/}"
    if [[ "${location}" == */appointments || "${location}" == */appointments/ || "${location}" == */appointments\?* || "${location}" == */appointments#* ]]; then
        printf 'appointments'
    elif [[ -z "${location}" ]]; then
        printf 'missing'
    else
        printf 'unexpected'
    fi
}

ics_header_class() {
    local header_file="$1"
    local status="$2"
    local has_calendar='false'
    local has_disposition='false'
    [[ "${status}" == '404' ]] || { printf 'malformed'; return; }
    if awk 'tolower($1)=="content-type:" && tolower($0) ~ /text\/calendar/ {found=1} END {exit !found}' "${header_file}"; then
        has_calendar='true'
    fi
    if awk 'tolower($1)=="content-disposition:" {found=1} END {exit !found}' "${header_file}"; then
        has_disposition='true'
    fi
    if [[ "${has_calendar}" == 'false' && "${has_disposition}" == 'false' ]]; then
        printf 'not_calendar_no_disposition'
    elif [[ "${has_calendar}" == 'true' ]]; then
        printf 'calendar'
    else
        printf 'disposition'
    fi
}

request "booking_confirmation/of/${modern_capability}" modern_confirmation
request "booking_confirmation/of/${legacy_capability}" legacy_confirmation
request "appointments/ics/${modern_capability}" modern_ics
request "appointments/ics/${legacy_capability}" legacy_ics

REDIRECT_MODERN="$(redirect_class "${modern_confirmation_headers}" "${modern_confirmation_status}")"
REDIRECT_LEGACY="$(redirect_class "${legacy_confirmation_headers}" "${legacy_confirmation_status}")"
ICS_HEADERS_MODERN="$(ics_header_class "${modern_ics_headers}" "${modern_ics_status}")"
ICS_HEADERS_LEGACY="$(ics_header_class "${legacy_ics_headers}" "${legacy_ics_status}")"

[[ "${REDIRECT_MODERN}" == 'appointments' ]] && CHECK_MODERN_CONFIRMATION='true'
[[ "${REDIRECT_LEGACY}" == 'appointments' ]] && CHECK_LEGACY_CONFIRMATION='true'
[[ "${modern_ics_status}" == '404' ]] && CHECK_MODERN_ICS_MISSING='true'
[[ "${legacy_ics_status}" == '404' ]] && CHECK_LEGACY_ICS_MISSING='true'
[[ "${ICS_HEADERS_MODERN}" == 'not_calendar_no_disposition' ]] && CHECK_MODERN_ICS_HEADERS='true'
[[ "${ICS_HEADERS_LEGACY}" == 'not_calendar_no_disposition' ]] && CHECK_LEGACY_ICS_HEADERS='true'

if [[ "${CHECK_MODERN_CONFIRMATION}" == 'true' && "${CHECK_LEGACY_CONFIRMATION}" == 'true' && \
    "${CHECK_MODERN_ICS_MISSING}" == 'true' && "${CHECK_LEGACY_ICS_MISSING}" == 'true' && \
    "${CHECK_MODERN_ICS_HEADERS}" == 'true' && "${CHECK_LEGACY_ICS_HEADERS}" == 'true' ]]; then
    OUTCOME='passed'
    EXIT_CODE=0
else
    die_application
fi
exit 0
