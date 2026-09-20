#!/usr/bin/env bash
set -Eeuo pipefail
set +x

# Read-only anonymous booking-download contract. Capabilities are generated
# privately and are never printed, persisted, or included in the receipt.
readonly PROBE='anonymous_booking_download_capabilities'
readonly PROD_ORIGIN='http://127.0.0.1'
readonly PROD_REDIRECT_ORIGIN='https://dasforscherhaus-leg.de'
readonly PROD_APP_ROOT='/var/www/html/easyappointments'
if [[ "${READ_ONLY_PROBE_BASE_URL+x}" == 'x' ]]; then
    BASE_URL="${READ_ONLY_PROBE_BASE_URL}"
else
    BASE_URL="${PROD_ORIGIN}"
fi
TARGET_CLASS='unapproved'
REDIRECT_ORIGIN=''
EXPECTED_RELEASE="${READ_ONLY_PROBE_EXPECTED_RELEASE:-}"
PROD_APP_IDENTITY=''
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
STATE_SESSION='unknown'
STATE_RATE_LIMIT='unknown'
STATE_APP_LOG='unknown'
CLEANUP='not_applicable'

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
    STATE_SESSION='unknown'
    STATE_RATE_LIMIT='unknown'
    STATE_APP_LOG='unknown'
}

emit_receipt() {
    [[ "${RECEIPT_EMITTED}" == '1' ]] && return
    RECEIPT_EMITTED=1
    printf '{"schema":"read_only_probe.v1","probe":"%s","target_class":"%s","outcome":"%s","exit_code":%s,"checks":{"modern_confirmation_redirect":%s,"legacy_confirmation_redirect":%s,"modern_ics_missing":%s,"legacy_ics_missing":%s,"modern_ics_headers_safe":%s,"legacy_ics_headers_safe":%s},"redirect_class":{"modern":"%s","legacy":"%s"},"ics_header_class":{"modern":"%s","legacy":"%s"},"check_count":6,"state":{"session":"%s","rate_limit":"%s","app_log":"%s"},"cleanup":"%s"}\n' \
        "${PROBE}" "${TARGET_CLASS}" "${OUTCOME}" "${EXIT_CODE}" \
        "${CHECK_MODERN_CONFIRMATION}" "${CHECK_LEGACY_CONFIRMATION}" \
        "${CHECK_MODERN_ICS_MISSING}" "${CHECK_LEGACY_ICS_MISSING}" \
        "${CHECK_MODERN_ICS_HEADERS}" "${CHECK_LEGACY_ICS_HEADERS}" \
        "${REDIRECT_MODERN}" "${REDIRECT_LEGACY}" \
        "${ICS_HEADERS_MODERN}" "${ICS_HEADERS_LEGACY}" \
        "${STATE_SESSION}" "${STATE_RATE_LIMIT}" "${STATE_APP_LOG}" "${CLEANUP}"
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

verify_production_context() {
    local canonical_root
    local release_id
    local root_mode
    [[ "${EXPECTED_RELEASE}" =~ ^ea_[a-zA-Z0-9_]+$ ]] || return 1
    command -v realpath >/dev/null 2>&1 || return 1
    command -v stat >/dev/null 2>&1 || return 1
    command -v awk >/dev/null 2>&1 || return 1
    [[ -d "${PROD_APP_ROOT}" && ! -L "${PROD_APP_ROOT}" ]] || return 1
    canonical_root="$(realpath -e -- "${PROD_APP_ROOT}" 2>/dev/null)" || return 1
    [[ "${canonical_root}" == "${PROD_APP_ROOT}" ]] || return 1
    [[ "$(stat -c '%u' -- "${PROD_APP_ROOT}" 2>/dev/null)" == '0' ]] || return 1
    root_mode="$(stat -c '%a' -- "${PROD_APP_ROOT}" 2>/dev/null)" || return 1
    (( (8#${root_mode} & 8#022) == 0 )) || return 1
    [[ -z "${PROD_APP_IDENTITY}" ]] || [[ "$(stat -c '%d:%i' -- "${PROD_APP_ROOT}" 2>/dev/null)" == "${PROD_APP_IDENTITY}" ]] || return 1
    [[ -f "${PROD_APP_ROOT}/_RELEASE" && ! -L "${PROD_APP_ROOT}/_RELEASE" ]] || return 1
    [[ "$(stat -c '%u' -- "${PROD_APP_ROOT}/_RELEASE" 2>/dev/null)" == '0' ]] || return 1
    release_id="$(awk '{print $1; exit}' "${PROD_APP_ROOT}/_RELEASE" 2>/dev/null)" || return 1
    [[ "${release_id}" == "${EXPECTED_RELEASE}" ]] || return 1
    PROD_APP_IDENTITY="$(stat -c '%d:%i' -- "${PROD_APP_ROOT}" 2>/dev/null)" || return 1
}

snapshot_production_state() {
    local rate_limits
    local logs
    command -v find >/dev/null 2>&1 || return 1
    command -v sort >/dev/null 2>&1 || return 1
    command -v sha256sum >/dev/null 2>&1 || return 1
    rate_limits="$(find "${PROD_APP_ROOT}/storage/cache" -maxdepth 1 -type f \( -name 'rate_limit_key_127.0.0.1' -o -name 'rate_limit_tmp_127.0.0.1' \) -printf '%f:%s:%T@\n' 2>/dev/null | sort | sha256sum | awk '{print $1}')" || return 1
    logs="$(find "${PROD_APP_ROOT}/storage/logs" -maxdepth 1 -type f -name 'log-*.php' -printf '%f:%s:%T@\n' 2>/dev/null | sort | sha256sum | awk '{print $1}')" || return 1
    printf '%s|%s' "${rate_limits}" "${logs}"
}

if [[ "${READ_ONLY_PROBE_BASE_URL+x}" != 'x' && "${BASE_URL}" == "${PROD_ORIGIN}" ]]; then
    verify_production_context || die_unknown
    STATE_BEFORE="$(snapshot_production_state)" || die_environment
    CLEANUP='not_verified'
    TARGET_CLASS='production'
    REDIRECT_ORIGIN="${PROD_REDIRECT_ORIGIN}"
elif [[ "${BASE_URL}" =~ ^http://127\.0\.0\.1:[1-9][0-9]*$ ]]; then
    TARGET_CLASS='local'
    REDIRECT_ORIGIN="${BASE_URL}"
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
COOKIE_JAR="$(mktemp 2>/dev/null)" || die_environment
TEMP_FILES+=("${COOKIE_JAR}")

request() {
    local route="$1"
    local result_name="$2"
    local header_file
    local http_status
    local curl_status
    header_file="$(mktemp 2>/dev/null)" || die_environment
    TEMP_FILES+=("${header_file}")
    set +e
    http_status="$(curl --disable --config /dev/null --request GET --retry 0 --max-redirs 0 --silent --show-error --max-time 15 --cookie "${COOKIE_JAR}" --cookie-jar "${COOKIE_JAR}" --dump-header "${header_file}" --output /dev/null --write-out '%{http_code}' "${BASE_URL}/index.php/${route}" 2>/dev/null)"
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
        blocks > 0 && in_headers && tolower($0) ~ /^location[[:blank:]]+:/ {malformed=1}
        blocks > 0 && in_headers && tolower($0) ~ /^location:/ {
            value=$0
            sub(/\r$/, "", value)
            sub(/^[^:]*:/, "", value)
            if (value ~ /[[:cntrl:]]/) malformed=1
            locations++
            location=$0
            sub(/^[^:]*:[[:space:]]*/, "", location)
        }
        END {printf "%d|%d|%d|%d|%s", blocks, locations, folded, malformed, location}
    ' "${header_file}" 2>/dev/null)" || return 1
    IFS='|' read -r blocks locations folded malformed location <<< "${summary}"
    location="${location%$'\r'}"
    [[ "${location}" =~ [[:cntrl:]] ]] && { REDIRECT_RESULT='malformed'; return; }
    if [[ "${blocks}" == '0' ]]; then
        REDIRECT_RESULT='malformed'
        return
    fi
    if [[ "${folded}" == '1' || "${malformed}" == '1' ]]; then
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
    case "${location}" in
        /appointments|/appointments/|/index.php/appointments|/index.php/appointments/|\
            "${REDIRECT_ORIGIN}/appointments"|"${REDIRECT_ORIGIN}/appointments/"|\
            "${REDIRECT_ORIGIN}/index.php/appointments"|"${REDIRECT_ORIGIN}/index.php/appointments/")
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
        blocks > 0 && in_headers && tolower($0) ~ /^content-type[[:blank:]]+:/ {malformed=1}
        blocks > 0 && in_headers && tolower($0) ~ /^content-disposition[[:blank:]]+:/ {malformed=1}
        blocks > 0 && in_headers && tolower($0) ~ /^content-type:/ {
            value=$0
            sub(/\r$/, "", value)
            sub(/^[^:]*:/, "", value)
            if (value ~ /[[:cntrl:]]/) malformed=1
        }
        blocks > 0 && in_headers && tolower($0) ~ /^content-disposition:/ {
            value=$0
            sub(/\r$/, "", value)
            sub(/^[^:]*:/, "", value)
            if (value ~ /[[:cntrl:]]/) malformed=1
        }
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

if [[ "${TARGET_CLASS}" == 'production' ]]; then
    verify_production_context || { CLEANUP='not_verified'; die_environment; }
    STATE_AFTER="$(snapshot_production_state)" || { CLEANUP='not_verified'; die_environment; }
    before_rate="${STATE_BEFORE%%|*}"
    before_log="${STATE_BEFORE#*|}"
    after_rate="${STATE_AFTER%%|*}"
    after_log="${STATE_AFTER#*|}"
    [[ "${before_rate}" == "${after_rate}" ]] && STATE_RATE_LIMIT='unchanged' || STATE_RATE_LIMIT='changed'
    [[ "${before_log}" == "${after_log}" ]] && STATE_APP_LOG='unchanged' || STATE_APP_LOG='changed'
    probe_session_id="$(awk '$6 == "ea_session" {print $7; exit}' "${COOKIE_JAR}" 2>/dev/null)" || { CLEANUP='not_verified'; die_environment; }
    [[ "${probe_session_id}" =~ ^[0-9a-zA-Z,-]+$ ]] || { CLEANUP='not_verified'; die_environment; }
    probe_session_file="$(find "${PROD_APP_ROOT}/storage/sessions" -maxdepth 1 -type f -name "ea_session${probe_session_id}" -print -quit 2>/dev/null)" || { CLEANUP='not_verified'; die_environment; }
    [[ -z "${probe_session_file}" ]] && STATE_SESSION='unchanged' || STATE_SESSION='changed'
    [[ "${STATE_SESSION}" == 'unchanged' && "${STATE_RATE_LIMIT}" == 'unchanged' && "${STATE_APP_LOG}" == 'unchanged' ]] || {
        CLEANUP='not_verified'
        die_environment
    }
    CLEANUP='not_applicable'
else
    STATE_SESSION='not_applicable'
    STATE_RATE_LIMIT='not_applicable'
    STATE_APP_LOG='not_applicable'
fi

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
