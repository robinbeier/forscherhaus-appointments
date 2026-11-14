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
payload_file="$docroot/.payload"

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
    php:8.4.27-fpm-alpine >/dev/null
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
assert_php_route '/booking/register' '/booking/register' '/index.php' '/booking/register'
assert_php_route '/booking/register/' '/booking/register/' '/index.php' '/booking/register'
assert_php_route '/index.php/booking/register' '/index.php/booking/register' '/index.php' '/booking/register'
assert_php_route '/index.php/booking/register/' '/index.php/booking/register/' '/index.php' '/booking/register/'

# Query-routed paths are accepted by CodeIgniter but cannot be constrained by
# an Nginx location match. They must be rejected at the edge so normalized
# slash and dot-dot variants cannot bypass the booking body limit; ordinary
# query parameters and canonical path forms remain available to browsers.
for booking_query_alias in \
    '/index.php?/booking/register' '/?/booking/register' \
    '/index.php?/booking/register?foo=bar' '/?/booking/register?foo=bar' \
    '/index.php?//booking///register' '/?//booking///register' \
    '/index.php?/../booking/register' '/?/../booking/register'; do
    alias_route_status="$(curl --noproxy '*' --silent --show-error --output "$body_file" \
        --write-out '%{http_code}' --request POST \
        --header 'Content-Type: application/x-www-form-urlencoded' \
        --data 'fixture=small' "$base_url$booking_query_alias")"
    [[ "$alias_route_status" == '404' ]] || {
        echo "Expected query-string booking alias $booking_query_alias to return HTTP 404, got $alias_route_status." >&2
        cat "$body_file" >&2
        exit 1
    }
done

for ordinary_query_path in '/?foo=bar' '/index.php?foo=bar'; do
    ordinary_query_status="$(request_status "$ordinary_query_path")"
    [[ "$ordinary_query_status" == '200' ]] || {
        echo "Expected ordinary query path $ordinary_query_path to return HTTP 200, got $ordinary_query_status." >&2
        cat "$body_file" >&2
        exit 1
    }
    grep -Fqx 'synthetic-frontcontroller-ok' "$body_file"
done

# Route-specific request-body protection: booking/register must reject a
# form-encoded body above 1 MiB at Nginx, while the ordinary route still reaches
# the front controller and unrelated static paths are not given this cap.
dd if=/dev/zero of="$payload_file" bs=1024 count=1025 >/dev/null 2>&1
booking_status="$(curl --noproxy '*' --silent --show-error --output "$body_file" \
    --write-out '%{http_code}' --request POST \
    --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@$payload_file" "$base_url/booking/register")"
[[ "$booking_status" == '413' ]] || {
    echo "Expected oversized booking/register body to return HTTP 413, got $booking_status." >&2
    cat "$body_file" >&2
    exit 1
}

for booking_path in '/booking/register/' '/index.php/booking/register' '/index.php/booking/register/'; do
    alias_status="$(curl --noproxy '*' --silent --show-error --output "$body_file" \
        --write-out '%{http_code}' --request POST \
        --header 'Content-Type: application/x-www-form-urlencoded' \
        --data-binary "@$payload_file" "$base_url$booking_path")"
    [[ "$alias_status" == '413' ]] || {
        echo "Expected oversized $booking_path body to return HTTP 413, got $alias_status." >&2
        cat "$body_file" >&2
        exit 1
    }
done

for booking_query_alias in \
    '/index.php?/booking/register' '/?/booking/register' \
    '/index.php?/booking/register?foo=bar' '/?/booking/register?foo=bar' \
    '/index.php?//booking///register' '/?//booking///register' \
    '/index.php?/../booking/register' '/?/../booking/register'; do
    oversized_alias_status="$(curl --noproxy '*' --silent --show-error --output "$body_file" \
        --write-out '%{http_code}' --request POST \
        --header 'Content-Type: application/x-www-form-urlencoded' \
        --data-binary "@$payload_file" "$base_url$booking_query_alias")"
    [[ "$oversized_alias_status" == '404' ]] || {
        echo "Expected oversized query-string booking alias $booking_query_alias to return HTTP 404, got $oversized_alias_status." >&2
        cat "$body_file" >&2
        exit 1
    }
done

chunked_status="$(cat "$payload_file" | curl --noproxy '*' --silent --show-error --output "$body_file" \
    --write-out '%{http_code}' --request POST \
    --header 'Content-Type: application/x-www-form-urlencoded' \
    --header 'Transfer-Encoding: chunked' \
    --data-binary @- "$base_url/booking/register")"
[[ "$chunked_status" == '413' ]] || {
    echo "Expected chunked oversized booking body to return HTTP 413, got $chunked_status." >&2
    cat "$body_file" >&2
    exit 1
}

small_booking_status="$(curl --noproxy '*' --silent --show-error --output "$body_file" \
    --write-out '%{http_code}' --request POST \
    --header 'Content-Type: application/x-www-form-urlencoded' \
    --data 'fixture=small' "$base_url/booking/register")"
[[ "$small_booking_status" == '200' ]] || {
    echo "Expected ordinary booking/register body to reach the front controller, got HTTP $small_booking_status." >&2
    cat "$body_file" >&2
    exit 1
}

asset_status="$(curl --noproxy '*' --silent --show-error --output "$body_file" \
    --write-out '%{http_code}' --request POST \
    --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@$payload_file" "$base_url/assets/fixture.txt")"
[[ "$asset_status" != '413' ]] || {
    echo 'Expected unrelated asset path to retain the server-wide body limit.' >&2
    exit 1
}

unrelated_php_status="$(curl --noproxy '*' --silent --show-error --output "$body_file" \
    --write-out '%{http_code}' --request POST \
    --header 'Content-Type: application/x-www-form-urlencoded' \
    --data-binary "@$payload_file" "$base_url/index.php/login")"
[[ "$unrelated_php_status" == '200' ]] || {
    echo "Expected unrelated front-controller path to retain the server-wide body limit, got HTTP $unrelated_php_status." >&2
    cat "$body_file" >&2
    exit 1
}

printf 'nginx_private_paths_smoke=passed image=nginx:1.29.6-alpine host=127.0.0.1:%s\n' "$host_port"
