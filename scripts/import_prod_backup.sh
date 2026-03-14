#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" == "Darwin" ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)

PROD_SSH_TARGET="${PROD_SSH_TARGET:-root@188.245.244.123}"
REMOTE_BACKUP_SCRIPT="${REMOTE_BACKUP_SCRIPT:-/root/backups/bin/backup_easyappointments.sh}"
REMOTE_BACKUP_DIR="${REMOTE_BACKUP_DIR:-}"
REMOTE_BACKUP_ROOT="${REMOTE_BACKUP_ROOT:-/root/backups/easyappointments}"
REMOTE_BACKUP_RETENTION="${REMOTE_BACKUP_RETENTION:-30}"
LOCAL_DOWNLOAD_ROOT="${LOCAL_DOWNLOAD_ROOT:-/tmp}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-easyappointments}"
START_FULL_STACK="${START_FULL_STACK:-1}"

usage() {
    cat <<'USAGE'
Usage:
  bash ./scripts/import_prod_backup.sh [options]

Options:
  --prod-ssh-target TARGET         SSH target for production access.
                                   Default: root@188.245.244.123
  --remote-backup-script PATH      Remote backup script to run when no backup dir is given.
                                   Default: /root/backups/bin/backup_easyappointments.sh
  --remote-backup-dir PATH         Reuse an existing remote backup directory instead of creating a new one.
  --remote-backup-root PATH        Expected remote backup root for validation.
                                   Default: /root/backups/easyappointments
  --remote-backup-retention N      Retention passed to the remote backup script.
                                   Default: 30
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

compose() {
    (
        cd "${REPO_ROOT}"
        docker compose "$@"
    )
}

ensure_identifier() {
    [[ "$1" =~ ^[A-Za-z0-9_]+$ ]] || die "Invalid SQL identifier: $1"
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
            --remote-backup-script)
                REMOTE_BACKUP_SCRIPT="$2"
                shift 2
                ;;
            --remote-backup-dir)
                REMOTE_BACKUP_DIR="$2"
                shift 2
                ;;
            --remote-backup-root)
                REMOTE_BACKUP_ROOT="$2"
                shift 2
                ;;
            --remote-backup-retention)
                REMOTE_BACKUP_RETENTION="$2"
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
}

create_or_select_remote_backup() {
    local remote_backup_output=""

    if [[ -n "${REMOTE_BACKUP_DIR}" ]]; then
        log "Using existing remote backup directory: ${REMOTE_BACKUP_DIR}"
    else
        log "Creating fresh backup on ${PROD_SSH_TARGET}"
        remote_backup_output="$(
            ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
                "${REMOTE_BACKUP_SCRIPT} --retention ${REMOTE_BACKUP_RETENTION}"
        )"
        printf '%s\n' "${remote_backup_output}"
        REMOTE_BACKUP_DIR="$(printf '%s\n' "${remote_backup_output}" | tail -n1)"
    fi

    [[ -n "${REMOTE_BACKUP_DIR}" ]] || die "Remote backup directory could not be determined."
    [[ "${REMOTE_BACKUP_DIR}" == "${REMOTE_BACKUP_ROOT}/"* ]] || die "Remote backup directory is outside ${REMOTE_BACKUP_ROOT}: ${REMOTE_BACKUP_DIR}"

    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "test -d '${REMOTE_BACKUP_DIR}'" >/dev/null
}

download_remote_backup() {
    local backup_stamp

    backup_stamp="$(basename "${REMOTE_BACKUP_DIR}")"
    LOCAL_IMPORT_DIR="${LOCAL_DOWNLOAD_ROOT}/easyappointments-prod-${backup_stamp}"
    LOCAL_DUMP_PATH="${LOCAL_IMPORT_DIR}/${LOCAL_DB_NAME}.sql.gz"
    LOCAL_META_PATH="${LOCAL_IMPORT_DIR}/backup.env"

    mkdir -p "${LOCAL_IMPORT_DIR}"

    log "Downloading dump to ${LOCAL_IMPORT_DIR}"
    scp "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}:${REMOTE_BACKUP_DIR}/db/${LOCAL_DB_NAME}.sql.gz" "${LOCAL_DUMP_PATH}"
    scp "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}:${REMOTE_BACKUP_DIR}/meta/backup.env" "${LOCAL_META_PATH}"

    [[ -s "${LOCAL_DUMP_PATH}" ]] || die "Downloaded dump is empty: ${LOCAL_DUMP_PATH}"
}

backup_local_mysql_dir() {
    LOCAL_MYSQL_BACKUP_TGZ="/tmp/forscherhaus-local-mysql-$(date +%Y%m%d-%H%M%S).tgz"

    log "Saving current local MySQL data directory to ${LOCAL_MYSQL_BACKUP_TGZ}"
    tar -czf "${LOCAL_MYSQL_BACKUP_TGZ}" -C "${REPO_ROOT}/docker" mysql
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
    compose up -d mysql php-fpm nginx
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
    compose up -d --pull never
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

    require_cmd ssh
    require_cmd scp
    require_cmd docker
    require_cmd tar
    require_cmd gunzip
    require_cmd curl
    ensure_identifier "${LOCAL_DB_NAME}"
    ensure_paths

    create_or_select_remote_backup
    download_remote_backup
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
