#!/usr/bin/env bash

kuma_push_die() {
  printf '[!] %s\n' "$*" >&2
  exit 1
}

kuma_push_load_env_file() {
  local env_file="${KUMA_PUSH_ENV_FILE:-/root/backups/uptime-kuma-push.env}"
  [[ -f "$env_file" ]] || kuma_push_die "Missing env file: $env_file"
  # shellcheck disable=SC1090
  source "$env_file"
}

kuma_push_source_if_exists() {
  local env_file="$1"
  [[ -f "$env_file" ]] || return 0
  # shellcheck disable=SC1090
  source "$env_file"
}

kuma_push_require_env() {
  local var_name="$1"
  [[ -n "${!var_name:-}" ]] || kuma_push_die "$var_name missing in configured environment"
}

kuma_push_send() {
  local push_url="$1"
  local status="$2"
  local msg="$3"
  local ping="$4"

  curl --silent --show-error --fail \
    --connect-timeout 2 \
    --max-time 8 \
    --retry 1 \
    --retry-delay 1 \
    --retry-all-errors \
    --get "$push_url" \
    --data-urlencode "status=${status}" \
    --data-urlencode "msg=${msg}" \
    --data-urlencode "ping=${ping}" \
    >/dev/null
}

kuma_push_log() {
  printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*"
}

kuma_push_trim() {
  local text="$1"
  local max_len="${2:-180}"

  if (( ${#text} <= max_len )); then
    printf '%s' "$text"
    return 0
  fi

  printf '%s' "${text:0:max_len}"
}

kuma_push_stat_dev_inode() {
  local path="$1"

  if stat -c '%d:%i' "$path" >/dev/null 2>&1; then
    stat -c '%d:%i' "$path"
    return 0
  fi

  stat -f '%d:%i' "$path"
}

kuma_push_stat_size() {
  local path="$1"

  if stat -c '%s' "$path" >/dev/null 2>&1; then
    stat -c '%s' "$path"
    return 0
  fi

  stat -f '%z' "$path"
}

kuma_push_date_days_ago() {
  local days="$1"

  if date -u -d "-${days} days" +%F >/dev/null 2>&1; then
    date -u -d "-${days} days" +%F
    return 0
  fi

  date -u -v-"${days}"d +%F
}

kuma_push_private_error() {
  printf '[!] %s\n' "$*" >&2
  return 1
}

kuma_push_stat_metadata() {
  local path="$1"
  local owner
  local links

  if stat -c '%u %a %h' -- "$path" >/dev/null 2>&1; then
    stat -c '%u %a %h' -- "$path"
    return 0
  fi

  local raw_mode
  read -r owner raw_mode links < <(stat -f '%u %p %l' -- "$path")
  printf '%s %s %s\n' "$owner" "$(printf '%o' "$((0$raw_mode & 07777))")" "$links"
}

kuma_push_realpath_directory() {
  local path="$1"

  if realpath -e -- "$path" >/dev/null 2>&1; then
    realpath -e -- "$path"
    return 0
  fi

  (cd -P -- "$path" && pwd -P)
}

kuma_push_validate_private_ancestors() {
  local path="$1"
  local owner
  local mode

  [[ "$path" == /* && -d "$path" && ! -L "$path" ]] ||
    kuma_push_private_error "Unsafe private path parent: $path" || return 1

  while :; do
    read -r owner mode _ < <(kuma_push_stat_metadata "$path")
    if [[ "$owner" != "0" && "$owner" != "$(id -u)" ]]; then
      kuma_push_private_error "Unsafe private path ancestor owner: $path" || return 1
    fi
    if (( (0$mode & 0022) != 0 )); then
      if [[ "$path" != /tmp && "$path" != /var/tmp && "$path" != /private/tmp && "$path" != /private/var/tmp ]] || [[ "$owner" != 0 ]] || (( (0$mode & 01000) == 0 )); then
        kuma_push_private_error "Unsafe private path ancestor permissions: $path" || return 1
      fi
    fi
    [[ "$path" == / ]] && break
    path="$(dirname -- "$path")"
  done
}

kuma_push_prepare_private_directory() {
  local requested="${1:-}"
  local parent
  local leaf
  local canonical_parent
  local target
  local metadata
  local owner
  local mode

  [[ "$requested" == /* && "$requested" != */ ]] ||
    kuma_push_private_error "Unsafe private directory path: $requested" || return 1
  parent="$(dirname -- "$requested")"
  leaf="$(basename -- "$requested")"
  [[ "$leaf" != . && "$leaf" != .. && -n "$leaf" ]] ||
    kuma_push_private_error "Unsafe private directory leaf: $requested" || return 1
  canonical_parent="$(kuma_push_realpath_directory "$parent" 2>/dev/null)" ||
    kuma_push_private_error "Unsafe private directory parent: $requested" || return 1
  kuma_push_validate_private_ancestors "$canonical_parent" || return 1
  target="$canonical_parent/$leaf"

  if [[ -L "$target" ]]; then
    kuma_push_private_error "Unsafe private directory symlink: $requested" || return 1
  fi
  if [[ ! -e "$target" ]]; then
    if ! (umask 077; mkdir -m 0700 "$target"); then
      # A concurrent safe creator may win the mkdir race.  Reuse the same
      # ownership/mode checks below, but never repair an unsafe winner.
      [[ -d "$target" && ! -L "$target" ]] ||
        kuma_push_private_error "Unsafe private directory could not be created: $requested" || return 1
    fi
  fi
  [[ -d "$target" && ! -L "$target" ]] ||
    kuma_push_private_error "Unsafe private directory type: $requested" || return 1
  metadata="$(kuma_push_stat_metadata "$target")" || return 1
  read -r owner mode _ <<< "$metadata"
  [[ -d "$target" && ! -L "$target" && "$owner" == "$(id -u)" && "$mode" == 700 ]] ||
    kuma_push_private_error "Unsafe private directory ownership or mode: $requested" || return 1
  target="$(kuma_push_realpath_directory "$target" 2>/dev/null)" ||
    kuma_push_private_error "Unsafe private directory canonicalization: $requested" || return 1
  printf '%s\n' "$target"
}

kuma_push_validate_private_file() {
  local path="${1:-}"
  local metadata
  local owner
  local mode
  local links

  [[ "$path" == /* && "$path" != */ ]] ||
    kuma_push_private_error "Unsafe private file path: $path" || return 1
  if [[ ! -e "$path" && ! -L "$path" ]]; then
    return 0
  fi
  [[ ! -L "$path" ]] || kuma_push_private_error "Unsafe private file symlink: $path" || return 1
  metadata="$(kuma_push_stat_metadata "$path")" || return 1
  read -r owner mode links <<< "$metadata"
  [[ -f "$path" && ! -L "$path" && "$owner" == "$(id -u)" && "$mode" == 600 && "$links" == 1 ]] ||
    kuma_push_private_error "Unsafe private file ownership, mode, or link count: $path"
}
