#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "Usage: $0 <minimum-node-version> [context-label]" >&2
    exit 1
fi

minimum_version="$1"
context_label="${2:-node-check}"

if ! command -v node >/dev/null 2>&1; then
    echo "[${context_label}] Missing required command: node" >&2
    exit 1
fi

current_version="$(node -p "process.versions.node")"

if ! node - "$minimum_version" "$current_version" <<'NODE'
const min = process.argv[2];
const cur = process.argv[3];

const parse = (value) => {
    const parts = value.split('.').map((part) => Number.parseInt(part, 10));

    if (parts.some((part) => Number.isNaN(part))) {
        return null;
    }

    return [parts[0] ?? 0, parts[1] ?? 0, parts[2] ?? 0];
};

const minParts = parse(min);
const curParts = parse(cur);

if (!minParts || !curParts) {
    process.exit(1);
}

for (let index = 0; index < 3; index += 1) {
    if (curParts[index] > minParts[index]) {
        process.exit(0);
    }

    if (curParts[index] < minParts[index]) {
        process.exit(1);
    }
}

process.exit(0);
NODE
then
    echo "[${context_label}] Node.js >=${minimum_version} is required (found v${current_version})." >&2
    exit 1
fi
