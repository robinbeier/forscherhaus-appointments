#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" == "Darwin" ]]; then
    export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
fi

SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="${PROD_SSH_TARGET:-root@188.245.244.123}"
EXPECTED_UBUNTU_VERSION="${EXPECTED_UBUNTU_VERSION:-26.04}"
MIN_MEM_MIB="${MIN_MEM_MIB:-1800}"
MIN_ROOT_FREE_GIB="${MIN_ROOT_FREE_GIB:-10}"
EXECUTE=0

usage() {
    cat <<'USAGE'
Usage:
  bash ./scripts/ops/probe_same_server_rebuild_target.sh [options]

Default mode is dry-run. Pass --execute after the provider reinstall and SSH
access are available.

Options:
  --execute                         Run the read-only SSH probe.
  --prod-ssh-target TARGET          SSH target. Default: root@188.245.244.123
  --expected-ubuntu-version VERSION Expected VERSION_ID. Default: 26.04
  -h, --help                        Show this help.

The probe prints OS/runtime/package-candidate facts only. It must not print
secret files, DB contents, Kuma DB contents, Push URLs, tokens, or config values.
USAGE
}

log() {
    printf '[same-server-probe] %s\n' "$*"
}

die() {
    printf '[same-server-probe] ERROR: %s\n' "$*" >&2
    exit 1
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
            --expected-ubuntu-version)
                EXPECTED_UBUNTU_VERSION="$2"
                shift 2
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

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

print_plan() {
    cat <<PLAN
[same-server-probe] Plan
  mode                    : $([[ "${EXECUTE}" == "1" ]] && printf 'execute' || printf 'dry-run')
  ssh target              : ${PROD_SSH_TARGET}
  expected Ubuntu version : ${EXPECTED_UBUNTU_VERSION}
  minimum memory MiB      : ${MIN_MEM_MIB}
  minimum root free GiB   : ${MIN_ROOT_FREE_GIB}

This is a read-only post-reinstall probe. It checks host facts and package
candidates before restore/deploy work starts.
PLAN
}

run_remote_probe() {
    ssh "${SSH_OPTIONS[@]}" "${PROD_SSH_TARGET}" \
        "EXPECTED_UBUNTU_VERSION='${EXPECTED_UBUNTU_VERSION}' MIN_MEM_MIB='${MIN_MEM_MIB}' MIN_ROOT_FREE_GIB='${MIN_ROOT_FREE_GIB}' bash -s" <<'REMOTE'
set -euo pipefail

section() {
    printf '\n[%s]\n' "$1"
}

warn() {
    printf 'WARN: %s\n' "$*" >&2
}

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

section os
. /etc/os-release
printf 'PRETTY_NAME=%s\n' "${PRETTY_NAME:-unknown}"
printf 'VERSION_ID=%s\n' "${VERSION_ID:-unknown}"
printf 'VERSION_CODENAME=%s\n' "${VERSION_CODENAME:-unknown}"
[[ "${VERSION_ID:-}" == "${EXPECTED_UBUNTU_VERSION}" ]] || fail "Expected Ubuntu ${EXPECTED_UBUNTU_VERSION}, got VERSION_ID=${VERSION_ID:-unknown}"

section kernel
uname -a

section resources
free -h
df -h /
swapon --show || true
mem_mib="$(awk '/MemTotal/ {printf "%d", $2 / 1024}' /proc/meminfo)"
root_free_gib="$(df -BG / | awk 'NR == 2 {gsub(/G/, "", $4); print $4}')"
printf 'MemTotalMiB=%s\n' "${mem_mib}"
printf 'RootFreeGiB=%s\n' "${root_free_gib}"
if (( mem_mib < MIN_MEM_MIB )); then
    warn "Memory below expected threshold: ${mem_mib}MiB < ${MIN_MEM_MIB}MiB"
fi
if (( root_free_gib < MIN_ROOT_FREE_GIB )); then
    warn "Root free space below expected threshold: ${root_free_gib}GiB < ${MIN_ROOT_FREE_GIB}GiB"
fi

section network
hostname -f 2>/dev/null || hostname
ip -brief addr || true
ip route || true
getent hosts archive.ubuntu.com || warn "archive.ubuntu.com does not resolve"
getent hosts security.ubuntu.com || warn "security.ubuntu.com does not resolve"

section apt-sources
find /etc/apt -maxdepth 3 -type f \( -name '*.list' -o -name '*.sources' \) -print -exec sed -n '1,160p' {} \; 2>/dev/null || true

section package-candidates
packages=(
    apache2
    mariadb-server
    php-fpm php-cli php-curl php-gd php-intl php-mbstring php-mysql php-xml php-zip php-soap php-bcmath php-readline
    php8.4-fpm php8.5-fpm
    nodejs npm composer
    docker.io docker-compose-v2 docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    certbot python3-certbot-apache fail2ban unattended-upgrades
    unzip zip rsync curl git
)
missing=0
for package_name in "${packages[@]}"; do
    candidate="$(apt-cache policy "${package_name}" 2>/dev/null | awk '/Candidate:/ {print $2; exit}')"
    installed="$(apt-cache policy "${package_name}" 2>/dev/null | awk '/Installed:/ {print $2; exit}')"
    printf '%-30s installed=%-24s candidate=%s\n' "${package_name}" "${installed:-unknown}" "${candidate:-unknown}"
    if [[ -z "${candidate}" || "${candidate}" == "(none)" ]]; then
        case "${package_name}" in
            php-readline|php8.4-fpm|php8.5-fpm|nodejs|npm|composer|docker.io|docker-compose-v2|docker-ce|docker-ce-cli|containerd.io|docker-buildx-plugin|docker-compose-plugin)
                ;;
            *)
                missing=$((missing + 1))
                ;;
        esac
    fi
done

section installed-runtime-versions
apache2 -v 2>/dev/null || true
php -v 2>/dev/null || true
mariadb --version 2>/dev/null || mysql --version 2>/dev/null || true
node --version 2>/dev/null || true
npm --version 2>/dev/null || true
composer --version 2>/dev/null || true
docker --version 2>/dev/null || true
docker compose version 2>/dev/null || true
certbot --version 2>/dev/null || true

section service-state
systemctl is-system-running 2>/dev/null || true
systemctl list-unit-files --state=enabled --no-pager 2>/dev/null || true

if (( missing > 0 )); then
    fail "${missing} required package candidates are missing"
fi

section result
printf 'probe=passed\n'
REMOTE
}

main() {
    parse_args "$@"
    require_cmd ssh
    print_plan

    if [[ "${EXECUTE}" != "1" ]]; then
        log "Dry-run only. Re-run with --execute after the 26.04 reinstall."
        exit 0
    fi

    run_remote_probe
}

main "$@"
