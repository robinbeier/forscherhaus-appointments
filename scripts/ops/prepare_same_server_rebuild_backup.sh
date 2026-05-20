#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" == "Darwin" ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
PROD_SSH_TARGET="${PROD_SSH_TARGET:-root@188.245.244.123}"
REMOTE_WORK_DIR="${REMOTE_WORK_DIR:-/root/rebuild-prewipe-backup-${STAMP}}"
LOCAL_BACKUP_ROOT="${LOCAL_BACKUP_ROOT:-${HOME}/Documents/forscherhaus-ops-secure/same-server-rebuild/${STAMP}}"
DB_NAME="${DB_NAME:-easyappointments}"
APP_CONFIG_PATH="${APP_CONFIG_PATH:-/var/www/html/easyappointments/application/config/config.php}"
PROD_SERVICE_HEALTH_UNITS="${PROD_SERVICE_HEALTH_UNITS:-apache2 php8.5-fpm mariadb docker fail2ban fh-pdf-renderer}"
EXECUTE=0
KEEP_REMOTE=0

usage() {
    cat <<'USAGE'
Usage:
  bash ./scripts/ops/prepare_same_server_rebuild_backup.sh [options]

Default mode is dry-run. Pass --execute only immediately before the provider
wipe, after the provider snapshot has completed.

Options:
  --execute                       Create and download the backup artifacts.
  --prod-ssh-target TARGET        SSH target. Default: root@188.245.244.123
  --remote-work-dir PATH          Temporary remote staging dir.
  --local-backup-root PATH        Secure local destination dir.
  --db-name NAME                  Database name. Default: easyappointments
  --app-config-path PATH          Remote app config path to include.
  --keep-remote                   Keep the temporary remote staging dir.
  -h, --help                      Show this help.

Artifacts:
  db/<db>.sql.gz                  Fresh database dump.
  host-config.tar.gz              Host-local config archive.
  meta/inventory.txt              Non-secret service/package inventory.
  meta/checksums.sha256           SHA256 for downloaded artifacts.

Do not store the output directory in Git, and do not paste artifact contents
into docs, chat, Linear, or screenshots.
USAGE
}

log() {
    printf '[same-server-backup] %s\n' "$*"
}

die() {
    printf '[same-server-backup] ERROR: %s\n' "$*" >&2
    exit 1
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --execute)
                EXECUTE=1
                shift
                ;;
            --prod-ssh-target)
                PROD_SSH_TARGET="$2"
                shift 2
                ;;
            --remote-work-dir)
                REMOTE_WORK_DIR="$2"
                shift 2
                ;;
            --local-backup-root)
                LOCAL_BACKUP_ROOT="$2"
                shift 2
                ;;
            --db-name)
                DB_NAME="$2"
                shift 2
                ;;
            --app-config-path)
                APP_CONFIG_PATH="$2"
                shift 2
                ;;
            --keep-remote)
                KEEP_REMOTE=1
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

ensure_identifier() {
    [[ "$1" =~ ^[A-Za-z0-9_]+$ ]] || die "Invalid SQL identifier: $1"
}

ensure_safe_local_destination() {
    local repo_real
    local dest_parent
    local dest_parent_real

    repo_real="$(cd "${REPO_ROOT}" && pwd -P)"
    dest_parent="$(dirname "${LOCAL_BACKUP_ROOT}")"

    if [[ "${EXECUTE}" == "1" ]]; then
        mkdir -p "${dest_parent}"
        dest_parent_real="$(cd "${dest_parent}" && pwd -P)"
    elif [[ -d "${dest_parent}" ]]; then
        dest_parent_real="$(cd "${dest_parent}" && pwd -P)"
    else
        dest_parent_real="${dest_parent}"
    fi

    case "${dest_parent_real}/" in
        "${repo_real}/"*)
            die "Refusing to place sensitive backups inside the Git repository: ${LOCAL_BACKUP_ROOT}"
            ;;
    esac
}

print_plan() {
    cat <<PLAN
[same-server-backup] Plan
  mode              : $([[ "${EXECUTE}" == "1" ]] && printf 'execute' || printf 'dry-run')
  ssh target        : ${PROD_SSH_TARGET}
  remote work dir   : ${REMOTE_WORK_DIR}
  local backup root : ${LOCAL_BACKUP_ROOT}
  database          : ${DB_NAME}
  app config path   : ${APP_CONFIG_PATH}
  remove remote dir : $([[ "${KEEP_REMOTE}" == "1" ]] && printf 'no' || printf 'yes')

This command creates a fresh DB dump and a host-config archive containing
secret-bearing operational files. The local destination must stay outside Git.
PLAN
}

create_remote_artifacts() {
    log "Creating remote backup artifacts on ${PROD_SSH_TARGET}"
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "REMOTE_WORK_DIR='${REMOTE_WORK_DIR}' DB_NAME='${DB_NAME}' APP_CONFIG_PATH='${APP_CONFIG_PATH}' PROD_SERVICE_HEALTH_UNITS='${PROD_SERVICE_HEALTH_UNITS}' bash -s" <<'REMOTE'
set -euo pipefail

mkdir -p "${REMOTE_WORK_DIR}/db" "${REMOTE_WORK_DIR}/meta"
chmod 700 "${REMOTE_WORK_DIR}"

dump_bin=""
if command -v mariadb-dump >/dev/null 2>&1; then
    dump_bin="mariadb-dump"
elif command -v mysqldump >/dev/null 2>&1; then
    dump_bin="mysqldump"
else
    echo "No mariadb-dump or mysqldump found" >&2
    exit 1
fi

"${dump_bin}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    "${DB_NAME}" | gzip -9 > "${REMOTE_WORK_DIR}/db/${DB_NAME}.sql.gz"
gzip -t "${REMOTE_WORK_DIR}/db/${DB_NAME}.sql.gz"

{
    printf 'captured_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'hostname=%s\n' "$(hostname -f 2>/dev/null || hostname)"
    printf 'kernel=%s\n' "$(uname -a)"
    printf '\n[os]\n'
    lsb_release -a 2>/dev/null || cat /etc/os-release
    printf '\n[packages]\n'
    dpkg-query -W \
        apache2 'php*' mariadb-server nodejs composer docker-ce docker.io \
        certbot python3-certbot-apache fail2ban unattended-upgrades 2>/dev/null || true
    printf '\n[enabled-units]\n'
    systemctl list-unit-files --state=enabled --no-pager 2>/dev/null || true
    printf '\n[service-health]\n'
    # Keep the unit list configurable so the inventory follows the accepted
    # production PHP-FPM unit after rebuilds without editing this script again.
    # shellcheck disable=SC2086
    systemctl is-active ${PROD_SERVICE_HEALTH_UNITS} 2>/dev/null || true
    printf '\n[apache-vhosts]\n'
    apache2ctl -S 2>&1 || true
    printf '\n[apache-modules]\n'
    apache2ctl -M 2>&1 || true
    printf '\n[docker-containers]\n'
    docker ps --format '{{.Names}} {{.Image}} {{.Status}} {{.Ports}}' 2>/dev/null || true
} > "${REMOTE_WORK_DIR}/meta/inventory.txt"

crontab -l -u root > "${REMOTE_WORK_DIR}/meta/root.crontab" 2>/dev/null || true
systemctl list-unit-files --state=enabled --no-pager > "${REMOTE_WORK_DIR}/meta/enabled-units.txt" 2>/dev/null || true

tar -czf "${REMOTE_WORK_DIR}/host-config.tar.gz" \
    --ignore-failed-read \
    /etc/fh \
    /etc/apache2/sites-available \
    /etc/apache2/sites-enabled \
    /etc/cron.d \
    /etc/systemd/system \
    "${APP_CONFIG_PATH}" \
    "${REMOTE_WORK_DIR}/meta/root.crontab" \
    "${REMOTE_WORK_DIR}/meta/enabled-units.txt"

(
    cd "${REMOTE_WORK_DIR}"
    sha256sum "db/${DB_NAME}.sql.gz" host-config.tar.gz meta/inventory.txt meta/root.crontab meta/enabled-units.txt > meta/checksums.sha256
)

chmod -R go-rwx "${REMOTE_WORK_DIR}"
REMOTE
}

download_artifacts() {
    log "Downloading backup artifacts to ${LOCAL_BACKUP_ROOT}"
    mkdir -p "${LOCAL_BACKUP_ROOT}/db" "${LOCAL_BACKUP_ROOT}/meta"
    chmod 700 "${LOCAL_BACKUP_ROOT}" "${LOCAL_BACKUP_ROOT}/db" "${LOCAL_BACKUP_ROOT}/meta"

    scp "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}:${REMOTE_WORK_DIR}/db/${DB_NAME}.sql.gz" "${LOCAL_BACKUP_ROOT}/db/"
    scp "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}:${REMOTE_WORK_DIR}/host-config.tar.gz" "${LOCAL_BACKUP_ROOT}/"
    scp "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}:${REMOTE_WORK_DIR}/meta/inventory.txt" "${LOCAL_BACKUP_ROOT}/meta/"
    scp "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}:${REMOTE_WORK_DIR}/meta/root.crontab" "${LOCAL_BACKUP_ROOT}/meta/"
    scp "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}:${REMOTE_WORK_DIR}/meta/enabled-units.txt" "${LOCAL_BACKUP_ROOT}/meta/"
    scp "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}:${REMOTE_WORK_DIR}/meta/checksums.sha256" "${LOCAL_BACKUP_ROOT}/meta/"

    chmod -R go-rwx "${LOCAL_BACKUP_ROOT}"
}

verify_local_artifacts() {
    log "Verifying downloaded artifacts"
    [[ -s "${LOCAL_BACKUP_ROOT}/db/${DB_NAME}.sql.gz" ]] || die "DB dump missing or empty"
    [[ -s "${LOCAL_BACKUP_ROOT}/host-config.tar.gz" ]] || die "Host config archive missing or empty"
    [[ -s "${LOCAL_BACKUP_ROOT}/meta/checksums.sha256" ]] || die "Checksum file missing or empty"

    gzip -t "${LOCAL_BACKUP_ROOT}/db/${DB_NAME}.sql.gz"
    tar -tzf "${LOCAL_BACKUP_ROOT}/host-config.tar.gz" >/dev/null
    (
        cd "${LOCAL_BACKUP_ROOT}"
        shasum -a 256 -c meta/checksums.sha256
    )
}

remove_remote_artifacts() {
    if [[ "${KEEP_REMOTE}" == "1" ]]; then
        log "Keeping remote work dir: ${REMOTE_WORK_DIR}"
        return 0
    fi

    log "Removing temporary remote work dir"
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" "rm -rf '${REMOTE_WORK_DIR}'"
}

main() {
    parse_args "$@"
    ensure_identifier "${DB_NAME}"
    ensure_safe_local_destination
    require_cmd ssh
    require_cmd scp
    require_cmd gzip
    require_cmd tar
    require_cmd shasum
    print_plan

    if [[ "${EXECUTE}" != "1" ]]; then
        log "Dry-run only. Re-run with --execute after the provider snapshot is complete."
        exit 0
    fi

    create_remote_artifacts
    download_artifacts
    verify_local_artifacts
    remove_remote_artifacts

    log "Backup complete."
    log "Local backup root: ${LOCAL_BACKUP_ROOT}"
    log "Record this path and the checksums, but do not commit the artifacts."
}

main "$@"
