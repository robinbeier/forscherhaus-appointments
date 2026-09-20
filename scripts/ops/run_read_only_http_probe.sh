#!/usr/bin/env bash
set -Eeuo pipefail
set +x

# Read-only anonymous booking-download contract. Capabilities are generated
# privately and are never printed, persisted, or included in the receipt.
readonly PROBE='anonymous_booking_download_capabilities'
readonly PROD_ORIGIN='https://dasforscherhaus-leg.de'
BASE_URL="${READ_ONLY_PROBE_BASE_URL:-${PROD_ORIGIN}}"
TARGET_CLASS='unapproved'
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
REDIRECT_RESULT='malformed'
ICS_HEADER_RESULT='malformed'

clear_observations() {
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
    REDIRECT_RESULT='malformed'
    ICS_HEADER_RESULT='malformed'
}

emit_receipt() {
    [[ "${RECEIPT_EMITTED}" == '1' ]] && return
    RECEIPT_EMITTED=1
    printf '{"schema":"read_only_probe.v1","probe":"%s","target_class":"%s","outcome":"%s","exit_code":%s,"checks":{"modern_confirmation_redirect":%s,"legacy_confirmation_redirect":%s,"modern_ics_missing":%s,"legacy_ics_missing":%s,"modern_ics_headers_safe":%s,"legacy_ics_headers_safe":%s},"redirect_class":{"modern":"%s","legacy":"%s"},"ics_header_class":{"modern":"%s","legacy":"%s"},"check_count":6,"cleanup":"not_applicable"}\n' \
        "${PROBE}" "${TARGET_CLASS}" "${OUTCOME}" "${EXIT_CODE}" \
        "${CHECK_MODERN_CONFIRMATION}" "${CHECK_LEGACY_CONFIRMATION}" \
        "${CHECK_MODERN_ICS_MISSING}" "${CHECK_LEGACY_ICS_MISSING}" \
        "${CHECK_MODERN_ICS_HEADERS}" "${CHECK_LEGACY_ICS_HEADERS}" \
        "${REDIRECT_MODERN}" "${REDIRECT_LEGACY}" \
        "${ICS_HEADERS_MODERN}" "${ICS_HEADERS_LEGACY}"
}

cleanup_temp_files() {
    local cleanup_status=0
    if ((${#TEMP_FILES[@]} > 0)); then
        for file in "${TEMP_FILES[@]}"; do
            rm -f -- "${file}" 2>/dev/null || cleanup_status=1
        done
    fi
    return "${cleanup_status}"
}

finish() {
    local status=$?
    local cleanup_status=0
    trap - EXIT HUP INT TERM
    if [[ "${RECEIPT_EMITTED}" == '0' ]]; then
        case "${status}" in
            21) OUTCOME='environment_failed'; EXIT_CODE=21; clear_observations ;;
            20) OUTCOME='application_failed'; EXIT_CODE=20 ;;
            70) OUTCOME='unknown'; EXIT_CODE=70; clear_observations ;;
            *)
                if [[ "${status}" != '0' && "${EXIT_CODE}" == '0' ]]; then
                    OUTCOME='unknown'
                    EXIT_CODE=70
                    clear_observations
                fi
                ;;
        esac
        cleanup_temp_files || cleanup_status=$?
        if [[ "${cleanup_status}" != '0' ]]; then
            OUTCOME='environment_failed'
            EXIT_CODE=21
            clear_observations
        fi
        if ! emit_receipt 2>/dev/null; then
            # A closed/unwritable stdout cannot carry a valid receipt. The
            # temporary evidence is already removed; fail closed without a
            # second write attempt that could obscure the original failure.
            EXIT_CODE=70
        fi
    else
        cleanup_temp_files || true
    fi
    exit "${EXIT_CODE}"
}
trap finish EXIT
trap 'OUTCOME=unknown; EXIT_CODE=70; exit 70' HUP INT TERM

die_environment() { OUTCOME='environment_failed'; EXIT_CODE=21; exit 21; }
die_application() { OUTCOME='application_failed'; EXIT_CODE=20; exit 20; }
die_unknown() { OUTCOME='unknown'; EXIT_CODE=70; exit 70; }

if [[ "${BASE_URL}" == "${PROD_ORIGIN}" ]]; then
    TARGET_CLASS='production'
elif [[ "${BASE_URL}" =~ ^http://127\.0\.0\.1:[1-9][0-9]*$ ]]; then
    TARGET_CLASS='local'
else
    die_unknown
fi
for command_name in curl od tr mktemp awk rm; do
    command -v "${command_name}" >/dev/null 2>&1 || die_environment
done

modern_capability="$(od -An -N32 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n' 2>/dev/null)" || die_environment
legacy_capability="$(od -An -N6 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n' 2>/dev/null)" || die_environment
[[ "${modern_capability}" =~ ^[0-9a-f]{64}$ ]] || die_environment
[[ "${legacy_capability}" =~ ^[0-9a-f]{12}$ ]] || die_environment

request() {
    local route="$1"
    local result_name="$2"
    local header_file
    local http_status
    local curl_status
    header_file="$(mktemp 2>/dev/null)" || die_environment
    TEMP_FILES+=("${header_file}")
    set +e
    http_status="$(curl --disable --config /dev/null --request GET --retry 0 --max-redirs 0 --silent --show-error --max-time 15 --dump-header "${header_file}" --output /dev/null --write-out '%{http_code}' "${BASE_URL}/index.php/${route}" 2>/dev/null)"
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
        REDIRECT_RESULT='unexpected'
        return
    fi
    local summary
    local blocks
    local locations
    local folded
    local malformed
    summary="$(awk '
        tolower($0) ~ /^http\/[0-9.]+[[:space:]]/ {blocks++; in_headers=1; locations=0; folded=0; malformed=0; location=""; next}
        blocks > 0 && in_headers && ($0 == "" || $0 == "\r") {in_headers=0; next}
        blocks > 0 && in_headers && /^[ \t]/ {folded=1}
        blocks > 0 && in_headers && tolower($0) ~ /^location[ \t]+:/ {malformed=1}
        blocks > 0 && in_headers && tolower($0) ~ /^location:[[:space:]]*/ {locations++; location=$0; sub(/^[^:]*:[[:space:]]*/, "", location)}
        END {printf "%d|%d|%d|%d|%s", blocks, locations, folded, malformed, location}
    ' "${header_file}" 2>/dev/null)" || return 1
    IFS='|' read -r blocks locations folded malformed location <<< "${summary}"
    location="${location//$'\r'/}"
    if [[ "${blocks}" == '0' ]]; then
        REDIRECT_RESULT='malformed'
        return
    fi
    if [[ "${locations}" == '0' ]]; then
        REDIRECT_RESULT='missing'
        return
    fi
    if [[ "${locations}" != '1' ]]; then
        REDIRECT_RESULT='malformed'
        return
    fi
    if [[ "${folded}" == '1' || "${malformed}" == '1' ]]; then
        REDIRECT_RESULT='malformed'
        return
    fi
    case "${location}" in
        /appointments|/appointments/|/index.php/appointments|/index.php/appointments/|\
            "${BASE_URL}/appointments"|"${BASE_URL}/appointments/"|\
            "${BASE_URL}/index.php/appointments"|"${BASE_URL}/index.php/appointments/")
            REDIRECT_RESULT='appointments'
            ;;
        *) REDIRECT_RESULT='unexpected' ;;
    esac
}

ics_header_class() {
    local header_file="$1"
    local status="$2"
    local has_calendar='false'
    local has_disposition='false'
    [[ "${status}" == '404' ]] || { ICS_HEADER_RESULT='malformed'; return; }
    local summary
    local blocks
    local calendar_match
    local disposition_match
    local folded
    local malformed
    summary="$(awk '
        tolower($0) ~ /^http\/[0-9.]+[[:space:]]/ {blocks++; in_headers=1; calendar=0; disposition=0; folded=0; malformed=0; next}
        blocks > 0 && in_headers && ($0 == "" || $0 == "\r") {in_headers=0; next}
        blocks > 0 && in_headers && /^[ \t]/ {folded=1}
        blocks > 0 && in_headers && tolower($0) ~ /^content-type[ \t]+:/ {malformed=1}
        blocks > 0 && in_headers && tolower($0) ~ /^content-disposition[ \t]+:/ {malformed=1}
        blocks > 0 && in_headers && tolower($0) ~ /^content-type:[[:space:]]*text\/calendar/ {calendar=1}
        blocks > 0 && in_headers && tolower($0) ~ /^content-disposition:/ {disposition=1}
        END {printf "%d|%d|%d|%d|%d", blocks, calendar, disposition, folded, malformed}
    ' "${header_file}" 2>/dev/null)" || return 1
    IFS='|' read -r blocks calendar_match disposition_match folded malformed <<< "${summary}"
    [[ "${blocks}" != '0' ]] || { ICS_HEADER_RESULT='malformed'; return; }
    [[ "${folded}" == '1' || "${malformed}" == '1' ]] && { ICS_HEADER_RESULT='malformed'; return; }
    if [[ "${calendar_match}" == '1' ]]; then
        has_calendar='true'
    fi
    if [[ "${disposition_match}" == '1' ]]; then
        has_disposition='true'
    fi
    if [[ "${has_calendar}" == 'false' && "${has_disposition}" == 'false' ]]; then
        ICS_HEADER_RESULT='not_calendar_no_disposition'
    elif [[ "${has_calendar}" == 'true' ]]; then
        ICS_HEADER_RESULT='calendar'
    else
        ICS_HEADER_RESULT='disposition'
    fi
}

request "booking_confirmation/of/${modern_capability}" modern_confirmation
request "booking_confirmation/of/${legacy_capability}" legacy_confirmation
request "appointments/ics/${modern_capability}" modern_ics
request "appointments/ics/${legacy_capability}" legacy_ics

redirect_class "${modern_confirmation_headers}" "${modern_confirmation_status}" || die_environment
REDIRECT_MODERN="${REDIRECT_RESULT}"
redirect_class "${legacy_confirmation_headers}" "${legacy_confirmation_status}" || die_environment
REDIRECT_LEGACY="${REDIRECT_RESULT}"
ics_header_class "${modern_ics_headers}" "${modern_ics_status}" || die_environment
ICS_HEADERS_MODERN="${ICS_HEADER_RESULT}"
ics_header_class "${legacy_ics_headers}" "${legacy_ics_status}" || die_environment
ICS_HEADERS_LEGACY="${ICS_HEADER_RESULT}"

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
