#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" == "Darwin" ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)

PROD_SSH_TARGET="${PROD_SSH_TARGET:-root@booking-server}"
REMOTE_BACKUP_DIR=''
REMOTE_BACKUP_ROOT='/root/backups/easyappointments'
LOCAL_DOWNLOAD_ROOT="${LOCAL_DOWNLOAD_ROOT:-/tmp}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-easyappointments}"
START_FULL_STACK="${START_FULL_STACK:-1}"
LOCAL_PHP_IMAGE_ID=''
LOCAL_PDF_IMAGE_ID=''

usage() {
    cat <<'USAGE'
Usage:
  bash ./scripts/import_prod_backup.sh [options]

Options:
  --prod-ssh-target TARGET         SSH target for production access.
                                   Default: root@booking-server (Tailscale)
  --remote-backup-dir PATH         Reuse an existing remote backup directory instead of creating a new one.
  --local-download-root PATH       Local directory for the downloaded dump and metadata.
                                   Default: /tmp
  --local-db-name NAME             Local MySQL database name to recreate/import.
                                   Default: easyappointments
  --core-services-only             Start only mysql/php-fpm/nginx after import.
  -h, --help                       Show this help.

Environment:
  COMPOSE_PROJECT_NAME             Optional Docker Compose project override.
USAGE
}

log() {
    printf '[import-prod-backup] %s\n' "$*"
}

die() {
    printf '[import-prod-backup] ERROR: %s\n' "$*" >&2
    exit 1
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

require_local_commands() {
    require_cmd ssh
    require_cmd scp
    require_cmd docker
    require_cmd tar
    require_cmd gunzip
    require_cmd curl
    require_cmd python3
}

compose() {
    (
        cd "${REPO_ROOT}"
        docker compose -f "${REPO_ROOT}/docker-compose.yml" "$@"
    )
}

preflight_local_runtime() {
    local mysql_image='mysql:8.4.8@sha256:2952e3be7807f06fc18de50b3ea1a632d5c70d63482ff7d7376fe3aa8999babf'
    local nginx_image='nginx:1.29.6-alpine@sha256:f46cb72c7df02710e693e863a983ac42f6a9579058a59a35f1ae36c9958e4ce0'
    local phpmyadmin_image='phpmyadmin:5.2.3@sha256:e4b3eb5305cd42d21512bb55def2bab52625d56df927ad4ee1864a9354c84d40'
    local swagger_image='swaggerapi/swagger-ui:v5.32.0@sha256:641afb45f0f14167da03659c220eff40c5aab231337ed2bad494802737d4e2ae'
    local openldap_image='vegardit/openldap@sha256:efa7cff027fa2ac503b073161849f93e4ccaaa1b9ac0a9788814865032952564'
    local php_cli_image='php:8.4.27-cli-bookworm@sha256:b3e907a1108629c562c4bb313ce3449e34d49991f6bb2487913f6146f47200fe'
    local php_container pdf_container

    log 'Checking approved local runtime images before contacting production'
    docker image inspect "${mysql_image}" >/dev/null 2>&1 || die "Missing or unapproved local image: ${mysql_image}"
    docker image inspect "${nginx_image}" >/dev/null 2>&1 || die "Missing or unapproved local image: ${nginx_image}"
    if [[ "${START_FULL_STACK}" == '1' ]]; then
        docker image inspect "${phpmyadmin_image}" >/dev/null 2>&1 || die "Missing or unapproved local image: ${phpmyadmin_image}"
        docker image inspect "${swagger_image}" >/dev/null 2>&1 || die "Missing or unapproved local image: ${swagger_image}"
        docker image inspect "${openldap_image}" >/dev/null 2>&1 || die "Missing or unapproved local image: ${openldap_image}"
        docker image inspect "${php_cli_image}" >/dev/null 2>&1 || die "Missing or unapproved local image: ${php_cli_image}"
        compose build --pull=false pdf-renderer >/dev/null || die 'Could not build pdf-renderer from pinned, verified inputs.'
        compose create --pull never --no-build pdf-renderer >/dev/null || die 'Could not materialize the preflight pdf-renderer image.'
    fi
    compose build --pull=false php-fpm >/dev/null || die 'Could not build php-fpm from pinned, verified inputs.'
    compose create --pull never --no-build php-fpm >/dev/null || die 'Could not materialize the preflight php-fpm image.'
    php_container="$(compose ps --all -q php-fpm)"
    [[ -n "${php_container}" ]] || die 'Could not identify the preflight php-fpm container.'
    LOCAL_PHP_IMAGE_ID="$(docker inspect --format '{{.Image}}' "${php_container}")"
    [[ "${LOCAL_PHP_IMAGE_ID}" =~ ^sha256:[0-9a-f]{64}$ ]] || die 'Preflight php-fpm image identity is invalid.'
    docker image inspect "${LOCAL_PHP_IMAGE_ID}" >/dev/null 2>&1 || die 'Preflight php-fpm image disappeared.'
    if [[ "${START_FULL_STACK}" == '1' ]]; then
        pdf_container="$(compose ps --all -q pdf-renderer)"
        [[ -n "${pdf_container}" ]] || die 'Could not identify the preflight pdf-renderer container.'
        LOCAL_PDF_IMAGE_ID="$(docker inspect --format '{{.Image}}' "${pdf_container}")"
        [[ "${LOCAL_PDF_IMAGE_ID}" =~ ^sha256:[0-9a-f]{64}$ ]] || die 'Preflight pdf-renderer image identity is invalid.'
        docker image inspect "${LOCAL_PDF_IMAGE_ID}" >/dev/null 2>&1 || die 'Preflight pdf-renderer image disappeared.'
    fi
}

ensure_identifier() {
    [[ "$1" =~ ^[A-Za-z0-9_]+$ ]] || die "Invalid SQL identifier: $1"
}

ensure_ssh_target() {
    [[ "$1" =~ ^root@[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || die "Production SSH target must be root@hostname or root@IPv4-style host."
}

ensure_remote_backup_dir() {
    local backup_stamp suffix
    [[ "$1" == "${REMOTE_BACKUP_ROOT}"/* ]] || die "Remote backup directory must be below the canonical backup root."
    suffix="${1#"${REMOTE_BACKUP_ROOT}/"}"
    [[ "$suffix" != */* ]] || die "Remote backup directory must be a direct child of the canonical backup root."
    backup_stamp="${1##*/}"
    [[ "$backup_stamp" =~ ^20[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])T([01][0-9]|2[0-3])[0-5][0-9][0-5][0-9]Z$ ]] || die "Remote backup directory must use a canonical UTC timestamp set ID."
}

validate_remote_backup_layout() {
    local remote_dir_q
    printf -v remote_dir_q '%q' "$REMOTE_BACKUP_DIR"
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "test ! -L /root && test ! -L /root/backups && test ! -L /root/backups/easyappointments && test -f /root/backups/easyappointments/last_backup_set.json && test ! -L /root/backups/easyappointments/last_backup_set.json && test -d ${remote_dir_q} && test ! -L ${remote_dir_q} && test -d ${remote_dir_q}/db && test ! -L ${remote_dir_q}/db && test -d ${remote_dir_q}/meta && test ! -L ${remote_dir_q}/meta && test -f ${remote_dir_q}/db/easyappointments.sql.gz && test ! -L ${remote_dir_q}/db/easyappointments.sql.gz && test -f ${remote_dir_q}/meta/backup.env && test ! -L ${remote_dir_q}/meta/backup.env" >/dev/null
}

ensure_paths() {
    [[ -f "${REPO_ROOT}/config.php" ]] || die "Missing local config.php in ${REPO_ROOT}"
    mkdir -p "${REPO_ROOT}/docker/mysql"
    mkdir -p "${LOCAL_DOWNLOAD_ROOT}"
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --prod-ssh-target)
                PROD_SSH_TARGET="$2"
                shift 2
                ;;
            --remote-backup-dir)
                REMOTE_BACKUP_DIR="$2"
                shift 2
                ;;
            --local-download-root)
                LOCAL_DOWNLOAD_ROOT="$2"
                shift 2
                ;;
            --local-db-name)
                LOCAL_DB_NAME="$2"
                shift 2
                ;;
            --core-services-only)
                START_FULL_STACK=0
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                die "Unknown option: $1"
                ;;
        esac
    done
    [[ "${REMOTE_BACKUP_ROOT}" == '/root/backups/easyappointments' ]] || die "Remote backup root is fixed to the canonical production path."
    [[ -n "${REMOTE_BACKUP_DIR}" ]] || die "--remote-backup-dir is required; creating a production backup is disabled."
    ensure_ssh_target "${PROD_SSH_TARGET}"
}

create_or_select_remote_backup() {
    log "Using existing remote backup directory: ${REMOTE_BACKUP_DIR}"
    ensure_remote_backup_dir "${REMOTE_BACKUP_DIR}"
    validate_remote_backup_layout
}

download_remote_backup() {
    local backup_stamp

    backup_stamp="$(basename "${REMOTE_BACKUP_DIR}")"
    # A production dump must never reuse a predictable or pre-planted path.
    # mktemp creates the directory exclusively with mode 0700; the process
    # umask keeps the downloaded files private as they are written.
    LOCAL_IMPORT_DIR="$(mktemp -d "${LOCAL_DOWNLOAD_ROOT%/}/easyappointments-prod-${backup_stamp}.XXXXXX")"
    LOCAL_DUMP_PATH="${LOCAL_IMPORT_DIR}/${LOCAL_DB_NAME}.sql.gz"
    LOCAL_META_PATH="${LOCAL_IMPORT_DIR}/backup.env"
    LOCAL_HANDOFF_PATH="${LOCAL_IMPORT_DIR}/last_backup_set.json"

    log "Downloading dump to ${LOCAL_IMPORT_DIR}"
    (
        umask 077
        scp "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}:${REMOTE_BACKUP_DIR}/db/easyappointments.sql.gz" "${LOCAL_DUMP_PATH}"
        scp "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}:${REMOTE_BACKUP_DIR}/meta/backup.env" "${LOCAL_META_PATH}"
        scp "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}:${REMOTE_BACKUP_ROOT}/last_backup_set.json" "${LOCAL_HANDOFF_PATH}"
    )
    chmod 0600 "${LOCAL_DUMP_PATH}" "${LOCAL_META_PATH}" "${LOCAL_HANDOFF_PATH}"

    [[ -s "${LOCAL_DUMP_PATH}" ]] || die "Downloaded dump is empty: ${LOCAL_DUMP_PATH}"
    [[ -s "${LOCAL_HANDOFF_PATH}" ]] || die "Downloaded backup handoff is empty: ${LOCAL_HANDOFF_PATH}"
}

verify_downloaded_backup() {
    local backup_id
    backup_id="${REMOTE_BACKUP_DIR##*/}"
    require_cmd python3
    python3 "${REPO_ROOT}/scripts/ops/libexec/backup_import_verify.py" \
        --handoff "${LOCAL_HANDOFF_PATH}" \
        --manifest "${LOCAL_META_PATH}" \
        --dump "${LOCAL_DUMP_PATH}" \
        --backup-id "${backup_id}" >/dev/null \
        || die "Downloaded backup failed canonical handoff verification."
}

backup_local_mysql_dir() {
    local backup_dir
    backup_dir="$(mktemp -d "${TMPDIR:-/tmp}/forscherhaus-local-mysql.XXXXXX")"
    LOCAL_MYSQL_BACKUP_TGZ="${backup_dir}/mysql.tgz"

    log "Saving current local MySQL data directory to ${LOCAL_MYSQL_BACKUP_TGZ}"
    (
        umask 077
        tar -czf "${LOCAL_MYSQL_BACKUP_TGZ}" -C "${REPO_ROOT}/docker" mysql
    )
}

wait_for_mysql() {
    local attempt=1
    local max_attempts=60

    until compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent >/dev/null 2>&1; do
        if [[ "${attempt}" -ge "${max_attempts}" ]]; then
            die "MySQL root readiness timed out after ${max_attempts} attempts."
        fi

        attempt=$((attempt + 1))
        sleep 2
    done

    attempt=1
    until compose exec -T mysql mysql -uuser -ppassword -e "USE ${LOCAL_DB_NAME}; SELECT 1;" >/dev/null 2>&1; do
        if [[ "${attempt}" -ge "${max_attempts}" ]]; then
            die "MySQL app-user readiness timed out after ${max_attempts} attempts."
        fi

        attempt=$((attempt + 1))
        sleep 2
    done
}

reset_and_import_local_database() {
    log "Stopping local Docker stack"
    compose down

    log "Resetting local docker/mysql contents"
    find "${REPO_ROOT}/docker/mysql" -mindepth 1 -maxdepth 1 -exec rm -rf {} +

    log "Starting core services"
    compose up -d --pull never --no-build mysql php-fpm nginx
    local running_php_image
    running_php_image="$(docker inspect --format '{{.Image}}' "$(compose ps -q php-fpm)")"
    [[ "${running_php_image}" == "${LOCAL_PHP_IMAGE_ID}" ]] || die 'php-fpm image identity changed after preflight.'
    wait_for_mysql

    log "Recreating ${LOCAL_DB_NAME} and importing dump"
    compose exec -T mysql mysql -uroot -psecret -e "DROP DATABASE IF EXISTS ${LOCAL_DB_NAME}; CREATE DATABASE ${LOCAL_DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    gunzip -c "${LOCAL_DUMP_PATH}" | compose exec -T mysql mysql -uroot -psecret "${LOCAL_DB_NAME}"

    log "Running application migrations"
    compose exec -T php-fpm php index.php console migrate
}

restart_optional_services() {
    if [[ "${START_FULL_STACK}" != "1" ]]; then
        log "Leaving only mysql/php-fpm/nginx running"
        return 0
    fi

    log "Starting remaining Docker services without pulling new images"
    compose up -d --pull never --no-build
    local running_pdf_image
    running_pdf_image="$(docker inspect --format '{{.Image}}' "$(compose ps -q pdf-renderer)")"
    [[ "${running_pdf_image}" == "${LOCAL_PDF_IMAGE_ID}" ]] || die 'pdf-renderer image identity changed after preflight.'
}

verify_local_state() {
    LOCAL_VERIFY_OUTPUT="$(
        compose exec -T mysql mysql -uroot -psecret -Nse \
            "SELECT version FROM ${LOCAL_DB_NAME}.ea_migrations; SELECT COUNT(*) FROM ${LOCAL_DB_NAME}.ea_settings; SELECT COUNT(*) FROM ${LOCAL_DB_NAME}.ea_users;"
    )"

    if [[ "$(printf '%s\n' "${LOCAL_VERIFY_OUTPUT}" | wc -l | tr -d ' ')" -ne 3 ]]; then
        die "Unexpected verification output from local MySQL."
    fi

    curl -I -fsS http://localhost >/dev/null
}

main() {
    local summary_version
    local summary_settings
    local summary_users

    parse_args "$@"

    ensure_remote_backup_dir "${REMOTE_BACKUP_DIR}"
    require_local_commands
    ensure_identifier "${LOCAL_DB_NAME}"

    # Validate the canonical Compose port exposure before touching local paths
    # or contacting production. This is intentionally before ensure_paths.
    "${REPO_ROOT}/scripts/ci/compose_sensitive_ports_smoke.sh"

    ensure_paths
    preflight_local_runtime

    create_or_select_remote_backup
    download_remote_backup
    verify_downloaded_backup
    backup_local_mysql_dir
    reset_and_import_local_database
    restart_optional_services
    verify_local_state

    summary_version="$(printf '%s\n' "${LOCAL_VERIFY_OUTPUT}" | sed -n '1p')"
    summary_settings="$(printf '%s\n' "${LOCAL_VERIFY_OUTPUT}" | sed -n '2p')"
    summary_users="$(printf '%s\n' "${LOCAL_VERIFY_OUTPUT}" | sed -n '3p')"

    echo
    log "Import complete."
    log "Remote backup: ${REMOTE_BACKUP_DIR}"
    log "Local dump: ${LOCAL_DUMP_PATH}"
    log "Local MySQL safety backup: ${LOCAL_MYSQL_BACKUP_TGZ}"
    log "Verification: version=${summary_version}, ea_settings=${summary_settings}, ea_users=${summary_users}"
}

main "$@"
