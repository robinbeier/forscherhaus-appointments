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
