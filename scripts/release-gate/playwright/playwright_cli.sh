#!/usr/bin/env bash
set -euo pipefail

if ! command -v npx >/dev/null 2>&1; then
  echo "Error: npx is required but not found on PATH." >&2
  exit 1
fi

default_browser() {
  echo "firefox"
}

default_playwright_cli_version() {
  echo "0.1.1"
}

default_playwright_runtime_package() {
  echo "playwright@1.59.0-alpha-1771104257000"
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

playwright_cli_name="@playwright/cli"

resolve_playwright_cli_version() {
  local version

  if ! command -v npm >/dev/null 2>&1; then
    default_playwright_cli_version
    return
  fi

  version="$(npm view "${playwright_cli_name}" version --json 2>/dev/null | tr -d '"' | tr -d '\r\n')"
  if [[ -z "${version}" || "${version}" == "null" ]]; then
    default_playwright_cli_version
    return
  fi

  echo "${version}"
}

playwright_cli_version="${PLAYWRIGHT_CLI_VERSION:-$(default_playwright_cli_version)}"
playwright_cli_package="${playwright_cli_name}@${playwright_cli_version}"

playwright_cli_cmd=(npx --yes --package "${playwright_cli_package}" playwright-cli)
playwright_ready_dir="${PLAYWRIGHT_MCP_READY_DIR:-/tmp/playwright-cli}"

resolve_playwright_runtime_package() {
  local cli_version
  local runtime_package

  if [[ -n "${PLAYWRIGHT_RUNTIME_PACKAGE:-}" ]]; then
    echo "${PLAYWRIGHT_RUNTIME_PACKAGE}"
    return
  fi

  if ! command -v npm >/dev/null 2>&1; then
    default_playwright_runtime_package
    return
  fi

  cli_version="${PLAYWRIGHT_CLI_VERSION:-${playwright_cli_version}}"

  runtime_package="$(
    npm view "${playwright_cli_name}@${cli_version}" dependencies.playwright --json 2>/dev/null \
      | tr -d '"' \
      | tr -d '\r\n'
  )"

  if [[ -z "${runtime_package}" || "${runtime_package}" == "null" ]]; then
    default_playwright_runtime_package
    return
  fi

  if [[ "${runtime_package}" != playwright@* ]]; then
    runtime_package="playwright@${runtime_package}"
  fi

  echo "${runtime_package}"
}

run_playwright_install() {
  local runtime_package
  runtime_package="$(resolve_playwright_runtime_package)"
  npx --yes --package "${runtime_package}" playwright "$@"
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
args=("$@")
for ((i=0; i<${#args[@]}; i++)); do
  arg="${args[i]}"

  case "$arg" in
    -s|--session)
      has_session_flag="true"
      ((i+=1))
      continue
      ;;
    -s=*|--session=*)
      has_session_flag="true"
      continue
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

export PLAYWRIGHT_MCP_OUTPUT_MODE="stdout"

if [[ "${install_command_requested}" == "true" ]]; then
  ensure_browser_installed
  exit 0
fi

if [[ "${help_command_requested}" != "true" ]]; then
  ensure_browser_installed
fi

exec "${cmd[@]}"
