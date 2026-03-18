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
playwright_ready_dir="${PLAYWRIGHT_MCP_READY_DIR:-/tmp/playwright-cli}"
playwright_runtime_package="${PLAYWRIGHT_RUNTIME_PACKAGE:-}"

# The repo's gate parsers currently read sentinel JSON from stdout, so keep
# run-code output on stdout even if the host environment configures a different
# mode.
export PLAYWRIGHT_MCP_OUTPUT_MODE="stdout"

resolve_playwright_runtime_package() {
  if [[ -n "${playwright_runtime_package}" ]]; then
    echo "${playwright_runtime_package}"
    return
  fi

  local cli_version
  local runtime_package

  cli_version="$(npm view @playwright/cli version --json | tr -d '"')"
  runtime_package="$(npm view "@playwright/cli@${cli_version}" dependencies.playwright --json | tr -d '"')"

  if [[ -z "${runtime_package}" || "${runtime_package}" == "null" ]]; then
    echo "Error: could not resolve the Playwright runtime package required by @playwright/cli." >&2
    exit 1
  fi

  playwright_runtime_package="${runtime_package}"
  echo "${playwright_runtime_package}"
}

run_playwright_install() {
  local runtime_package

  runtime_package="$(resolve_playwright_runtime_package)"
  npx --yes --package "${runtime_package}" playwright "$@"
}

resolve_playwright_ready_marker() {
  local version

  version="$(run_playwright_install --version | tr ' ' '-')"
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
      break
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
  cmd+=("-s=${PLAYWRIGHT_CLI_SESSION}")
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
