#!/usr/bin/env bash

app_log_known_noise_regex() {
  cat <<'REGEX'
ERROR - .*--> 404 Page Not Found: Azenvnet/index|ERROR - .*--> Severity: Warning --> unlink\(.*/storage/cache/rate_limit_key_[^)]*\): No such file or directory .*/system/libraries/Cache/drivers/Cache_file\.php 279
REGEX
}

app_log_filter_actionable_file() {
  local input_file="$1"
  local output_file="$2"
  local custom_ignore_regex="${3:-}"
  local tmp_file

  tmp_file="$(mktemp)"

  if [[ -n "$custom_ignore_regex" ]]; then
    grep -Ev "$custom_ignore_regex" "$input_file" > "$tmp_file" || true
  else
    cp "$input_file" "$tmp_file"
  fi

  grep -Ev "$(app_log_known_noise_regex)" "$tmp_file" > "$output_file" || true
  rm -f "$tmp_file"
}

app_log_error_like_regex() {
  cat <<'REGEX'
^(ERROR|CRITICAL)[[:space:]-]|^(Fatal error|Uncaught)|^PHP (Fatal error|Parse error|Recoverable fatal error)
REGEX
}

app_log_extract_error_like_file() {
  local input_file="$1"
  local output_file="$2"

  grep -Eh "$(app_log_error_like_regex)" "$input_file" > "$output_file" 2>/dev/null || true
}

app_log_count_error_like_file() {
  local input_file="$1"

  grep -Eh "$(app_log_error_like_regex)" "$input_file" 2>/dev/null \
    | wc -l \
    | awk '{print $1}'
}
