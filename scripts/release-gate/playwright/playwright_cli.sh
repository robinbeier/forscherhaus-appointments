#!/usr/bin/env bash
set -euo pipefail

if ! command -v npx >/dev/null 2>&1; then
  echo "Error: npx is required but not found on PATH." >&2
  exit 1
fi

default_browser() {
  echo "firefox"
}

normalize_browser() {
  local browser="${1:-}"

  browser="$(printf '%s' "${browser}" | tr '[:upper:]' '[:lower:]')"
  browser="${browser#"${browser%%[![:space:]]*}"}"
  browser="${browser%"${browser##*[![:space:]]}"}"

  if [[ -z "${browser}" ]]; then
    default_browser
    return
  fi

  echo "${browser}"
}

playwright_browser="$(normalize_browser "${PLAYWRIGHT_MCP_BROWSER:-}")"

playwright_cli_package="@playwright/cli@0.1.1"
playwright_runtime_package="${PLAYWRIGHT_RUNTIME_PACKAGE:-playwright@1.59.0-alpha-1771104257000}"

playwright_cli_cmd=(npx --yes --package "${playwright_cli_package}" playwright-cli)
playwright_ready_dir="${PLAYWRIGHT_MCP_READY_DIR:-/tmp/playwright-cli}"

run_playwright_install() {
  npx --yes --package "${playwright_runtime_package}" playwright "$@"
}

resolve_playwright_ready_marker() {
  local version
  local cli_marker

  version="$(run_playwright_install --version | tr ' ' '-')"
  version="${version//[^A-Za-z0-9._-]/_}"
  cli_marker="${playwright_cli_package//[^A-Za-z0-9._-]/_}"

  echo "${playwright_ready_dir}/${playwright_browser}-${cli_marker}-${version}.ready"
}

ensure_browser_installed() {
  local ready_marker

  ready_marker="$(resolve_playwright_ready_marker)"

  if [[ -f "${ready_marker}" ]]; then
    return
  fi

  mkdir -p "${playwright_ready_dir}"

  if [[ "$(uname -s)" == "Linux" ]]; then
    DEBIAN_FRONTEND=noninteractive run_playwright_install install --with-deps "${playwright_browser}"
  else
    run_playwright_install install "${playwright_browser}"
  fi

  touch "${ready_marker}"
}

has_session_flag="false"
install_command_requested="false"
help_command_requested="false"
for arg in "$@"; do
  case "$arg" in
    -s|-s=*|--session|--session=*)
      has_session_flag="true"
      ;;
    install-browser)
      install_command_requested="true"
      ;;
    --help|-h|help|--version|version)
      help_command_requested="true"
      ;;
  esac
done

cmd=("${playwright_cli_cmd[@]}")
if [[ "${has_session_flag}" != "true" && -n "${PLAYWRIGHT_CLI_SESSION:-}" ]]; then
  cmd+=(--session "${PLAYWRIGHT_CLI_SESSION}")
fi
cmd+=("$@")

if [[ "${install_command_requested}" == "true" ]]; then
  ensure_browser_installed
  exit 0
fi

if [[ "${help_command_requested}" != "true" ]]; then
  ensure_browser_installed
fi

exec "${cmd[@]}"
