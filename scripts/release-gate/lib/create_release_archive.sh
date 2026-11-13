#!/usr/bin/env bash

# Create a release archive while using metadata flags only when the installed
# tar accepts them. Keep this helper Bash 3.2-compatible for macOS operators.
create_release_archive() {
  local stage="$1"
  local archive="$2"
  local metadata_args=()

  if tar --no-mac-metadata --version >/dev/null 2>&1; then
    metadata_args+=(--no-mac-metadata)
  fi
  if tar --no-xattrs --version >/dev/null 2>&1; then
    metadata_args+=(--no-xattrs)
  fi

  COPYFILE_DISABLE=1 tar "${metadata_args[@]}" -C "$stage" -czf "$archive" .
}
