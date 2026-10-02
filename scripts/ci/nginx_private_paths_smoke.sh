#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
cd "$ROOT_DIR"

nginx_config="${NGINX_CONFIG_PATH:-$ROOT_DIR/docker/nginx/nginx.conf}"
[[ -f "$nginx_config" ]] || {
    echo "Nginx config does not exist: $nginx_config" >&2
    exit 1
}

docroot="$(mktemp -d "${TMPDIR:-/tmp}/fh-nginx-root.XXXXXX")"
chmod 0755 "$docroot"
network="fh-nginx-smoke-$RANDOM-$$"
nginx_container="fh-nginx-smoke-$RANDOM-$$"
php_container="fh-php-smoke-$RANDOM-$$"
body_file="$docroot/.response"

cleanup() {
    set +e
    docker rm -f "$nginx_container" "$php_container" >/dev/null 2>&1
    docker network rm "$network" >/dev/null 2>&1
    rm -rf -- "$docroot"
}
trap cleanup EXIT

mkdir -p "$docroot/.git" "$docroot/storage/logs" "$docroot/assets" "$docroot/application" "$docroot/pdf"
printf 'synthetic-private-env\n' > "$docroot/.env"
printf 'synthetic-git-config\n' > "$docroot/.git/config"
printf 'synthetic-git-head\n' > "$docroot/.git/HEAD"
printf 'synthetic-storage-log\n' > "$docroot/storage/logs/application.log"
printf 'synthetic-composer\n' > "$docroot/composer.json"
printf 'synthetic-config\n' > "$docroot/config.php"
printf 'synthetic-license-root-marker\n' > "$docroot/LICENSE"
printf 'synthetic-notice-root-marker\n' > "$docroot/NOTICE"
printf 'synthetic-public-asset\n' > "$docroot/assets/fixture.txt"
printf 'synthetic-nested-dotfile\n' > "$docroot/assets/.hidden"
printf '<?php echo "synthetic-private-php\\n";\n' > "$docroot/assets/private.php"
printf 'synthetic-internal-application\n' > "$docroot/application/internal.txt"
printf 'synthetic-private-pdf\n' > "$docroot/pdf/private.txt"
printf 'synthetic-health\n' > "$docroot/health"
printf 'synthetic-pdf-template\n' > "$docroot/pdf/template.html"
printf 'synthetic-pdf-logo\n' > "$docroot/pdf/logo.png"
cat > "$docroot/index.php" <<'PHP'
<?php
header('Content-Type: text/plain');
echo "synthetic-frontcontroller-ok\n";
echo "request_uri=" . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
echo "script_name=" . ($_SERVER['SCRIPT_NAME'] ?? '') . "\n";
echo "path_info=" . ($_SERVER['PATH_INFO'] ?? '') . "\n";
PHP

docker network create "$network" >/dev/null
docker run --detach --rm \
    --name "$php_container" \
    --network "$network" \
    --network-alias php-fpm \
    --volume "$docroot:/var/www/html:ro" \
    php:8.4-fpm-alpine >/dev/null
docker run --detach --rm \
    --name "$nginx_container" \
    --network "$network" \
    --publish 127.0.0.1::80 \
    --volume "$docroot:/var/www/html:ro" \
    --volume "$nginx_config:/etc/nginx/conf.d/default.conf:ro" \
    nginx:1.29.6-alpine >/dev/null

host_port="$(docker port "$nginx_container" 80/tcp | sed -n 's/.*://p' | head -n 1)"
[[ "$host_port" =~ ^[0-9]+$ ]] || {
    echo "Could not resolve nginx's random host port." >&2
    exit 1
}
base_url="http://127.0.0.1:${host_port}"

ready=0
for attempt in {1..30}; do
    asset_status=""
    asset_status="$(curl --noproxy '*' --silent --show-error --output "$body_file" --write-out '%{http_code}' "$base_url/assets/fixture.txt")" || asset_status=""
    if [[ "$asset_status" == '200' ]] && grep -Fqx 'synthetic-public-asset' "$body_file"; then
        ready=1
        break
    fi
    sleep 1
done
if [[ "$ready" != 1 ]]; then
    echo 'Nginx did not become ready.' >&2
    docker logs "$nginx_container" >&2 || true
    exit 1
fi

request_status() {
    local path="$1"
    curl --noproxy '*' --silent --show-error --output "$body_file" --write-out '%{http_code}' "$base_url$path"
}

assert_denied() {
    local path="$1"
    local status
    status="$(request_status "$path")"
    case "$status" in
        403|404) ;;
        *)
            echo "Expected $path to be denied, got HTTP $status." >&2
            cat "$body_file" >&2
            return 1
            ;;
    esac
}

assert_denied '/.env'
assert_denied '/assets/.hidden'
assert_denied '/assets/private.php'
assert_denied '/storage/logs/application.log'
assert_denied '/.git/config'
assert_denied '/.git/HEAD'
assert_denied '/composer.json'
assert_denied '/config.php'
assert_denied '/application/internal.txt'
assert_denied '/pdf/private.txt'

assert_not_raw() {
    local path="$1"
    local marker="$2"
    local status
    status="$(request_status "$path")"
    case "$status" in
        403|404) ;;
        *)
            echo "Expected $path to be denied, got HTTP $status." >&2
            cat "$body_file" >&2
            return 1
            ;;
    esac
    if grep -Fq "$marker" "$body_file"; then
        echo "Expected $path not to disclose its raw synthetic marker." >&2
        cat "$body_file" >&2
        return 1
    fi
}

assert_not_raw '/LICENSE' 'synthetic-license-root-marker'
assert_not_raw '/NOTICE' 'synthetic-notice-root-marker'

assert_public() {
    local path="$1"
    local expected_body="$2"
    local status
    status="$(request_status "$path")"
    [[ "$status" == '200' ]] || {
        echo "Expected $path to return HTTP 200, got $status." >&2
        cat "$body_file" >&2
        return 1
    }
    grep -Fqx "$expected_body" "$body_file"
}

assert_public '/health' 'synthetic-health'
assert_public '/assets/fixture.txt' 'synthetic-public-asset'
assert_public '/pdf/template.html' 'synthetic-pdf-template'
assert_public '/pdf/logo.png' 'synthetic-pdf-logo'

assert_php_route() {
    local path="$1"
    local expected_request_uri="$2"
    local expected_script_name="$3"
    local expected_path_info="$4"
    local status
    status="$(request_status "$path")"
    [[ "$status" == '200' ]] || {
        echo "Expected $path to return HTTP 200, got $status." >&2
        cat "$body_file" >&2
        return 1
    }
    grep -Fqx 'synthetic-frontcontroller-ok' "$body_file"
    grep -Fqx "request_uri=$expected_request_uri" "$body_file"
    grep -Fqx "script_name=$expected_script_name" "$body_file"
    grep -Fqx "path_info=$expected_path_info" "$body_file"
}

assert_php_route '/index.php' '/index.php' '/index.php' ''
assert_php_route '/index.php/login' '/index.php/login' '/index.php' '/login'
assert_php_route '/frontcontroller' '/frontcontroller' '/index.php' ''

printf 'nginx_private_paths_smoke=passed image=nginx:1.29.6-alpine host=127.0.0.1:%s\n' "$host_port"
