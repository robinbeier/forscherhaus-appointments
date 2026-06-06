#!/usr/bin/env bash

app_log_known_noise_regex() {
  cat <<'REGEX'
ERROR - .*--> 404 Page Not Found: (Azenvnet|Wwwgooglecom|127001:80|[Ii]ndex%2[eE]php)/index|ERROR - .*--> Severity: Warning --> unlink\(.*/storage/cache/rate_limit_key_[^)]*\): No such file or directory .*/system/libraries/Cache/drivers/Cache_file\.php 279
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

app_log_filter_since_timestamp_file() {
  local input_file="$1"
  local output_file="$2"
  local since_timestamp="$3"

  awk -v since_timestamp="$since_timestamp" '
    function entry_timestamp(line) {
      if (substr(line, 1, 8) == "ERROR - ") {
        return substr(line, 9, 19)
      }

      if (substr(line, 1, 11) == "CRITICAL - ") {
        return substr(line, 12, 19)
      }

      return ""
    }

    {
      timestamp = entry_timestamp($0)
      if (timestamp != "" && timestamp >= since_timestamp) {
        print
      }
    }
  ' "$input_file" > "$output_file" || true
}
