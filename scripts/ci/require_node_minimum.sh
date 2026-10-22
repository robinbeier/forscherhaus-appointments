#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "Usage: $0 <minimum-node-version> [context-label]" >&2
    exit 1
fi

minimum_version="$1"
context_label="${2:-node-check}"
security_floor="24.22.1"

if ! command -v node >/dev/null 2>&1; then
    echo "[${context_label}] Missing required command: node" >&2
    exit 1
fi

current_version="$(node -p "process.versions.node")"

if ! node - "$minimum_version" "$security_floor" "$current_version" <<'NODE'
const min = process.argv[2];
const floor = process.argv[3];
const cur = process.argv[4];

const parse = (value) => {
    if (!/^\d+\.\d+\.\d+$/.test(value)) {
        return null;
    }

    return value.split('.').map((part) => Number(part));
};

const compare = (left, right) => {
    for (let index = 0; index < 3; index += 1) {
        if (left[index] !== right[index]) {
            return left[index] > right[index] ? 1 : -1;
        }
    }

    return 0;
};

const minimum = parse(min);
const securityFloor = parse(floor);
const current = parse(cur);

if (!minimum || !securityFloor || !current) {
    process.exit(1);
}

if (compare(current, minimum) < 0) {
    process.exit(1);
}

if (current[0] === 24 && compare(current, securityFloor) >= 0) {
    process.exit(0);
}

if (compare(current, [26, 12, 1]) >= 0) {
    process.exit(0);
}
process.exit(1);
NODE
then
    echo "[${context_label}] Node.js security range ^24.22.1 || >=26.12.1 is required (found v${current_version}; caller minimum ${minimum_version})." >&2
    exit 1
fi
