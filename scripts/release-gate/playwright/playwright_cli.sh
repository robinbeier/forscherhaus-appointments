#!/usr/bin/env bash
set -euo pipefail

if ! command -v npx >/dev/null 2>&1; then
  echo "Error: npx is required but not found on PATH." >&2
  exit 1
fi

default_browser() {
  echo "firefox"
}

playwright_browser="${PLAYWRIGHT_MCP_BROWSER:-$(default_browser)}"

playwright_cli_cmd=(npx --yes --package @playwright/cli playwright-cli)
playwright_install_cmd=(npx --yes --package @playwright/cli playwright)
playwright_ready_dir="${PLAYWRIGHT_MCP_READY_DIR:-/tmp/playwright-cli}"

resolve_playwright_ready_marker() {
  local version

  version="$("${playwright_install_cmd[@]}" --version | tr ' ' '-')"
  version="${version//[^A-Za-z0-9._-]/_}"

  echo "${playwright_ready_dir}/${playwright_browser}-${version}.ready"
}

ensure_browser_installed() {
  local ready_marker

  ready_marker="$(resolve_playwright_ready_marker)"

  if [[ -f "${ready_marker}" ]]; then
    return
  fi

  mkdir -p "${playwright_ready_dir}"

  if [[ "$(uname -s)" == "Linux" ]]; then
    DEBIAN_FRONTEND=noninteractive "${playwright_install_cmd[@]}" install --with-deps "${playwright_browser}"
  else
    "${playwright_install_cmd[@]}" install "${playwright_browser}"
  fi

  touch "${ready_marker}"
}

has_session_flag="false"
install_command_requested="false"
for arg in "$@"; do
  case "$arg" in
    --session|--session=*)
      has_session_flag="true"
      break
      ;;
    install-browser)
      install_command_requested="true"
      ;;
  esac
done

cmd=("${playwright_cli_cmd[@]}")
if [[ "${has_session_flag}" != "true" && -n "${PLAYWRIGHT_CLI_SESSION:-}" ]]; then
  cmd+=(--session "${PLAYWRIGHT_CLI_SESSION}")
fi
cmd+=("$@")

if [[ "${install_command_requested}" != "true" ]]; then
  ensure_browser_installed
fi

exec "${cmd[@]}"
