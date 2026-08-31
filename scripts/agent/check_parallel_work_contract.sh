#!/bin/sh

set -eu

trusted_php=''
for candidate in /usr/bin/php /opt/homebrew/bin/php /usr/local/bin/php /opt/local/bin/php; do
    if [ -x "$candidate" ]; then
        trusted_php="$candidate"
        break
    fi
done
if [ -z "$trusted_php" ]; then
    echo "PHP is unavailable on the fixed parallel-work validator path." >&2
    exit 2
fi

script_directory="$(CDPATH= cd -- "$(/usr/bin/dirname -- "$0")" && /bin/pwd -P)"

exec /usr/bin/env -i \
    PATH=/usr/bin:/bin:/usr/sbin:/sbin \
    LANG=C \
    LC_ALL=C \
    TMPDIR="${TMPDIR:-/tmp}" \
    "$trusted_php" -n -d auto_prepend_file= -d auto_append_file= \
    "$script_directory/check_parallel_work_contract.php" "$@"
