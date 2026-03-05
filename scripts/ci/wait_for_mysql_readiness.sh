#!/usr/bin/env bash
set -euo pipefail

max_attempts="${1:-60}"
attempt=1

until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do
    if [[ "$attempt" -ge "$max_attempts" ]]; then
        echo "::error::MySQL root readiness timed out after ${max_attempts} attempts." >&2
        exit 1
    fi

    attempt=$((attempt + 1))
    sleep 2
done

attempt=1
until docker compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do
    if [[ "$attempt" -ge "$max_attempts" ]]; then
        echo "::error::MySQL app-user readiness timed out after ${max_attempts} attempts." >&2
        exit 1
    fi

    attempt=$((attempt + 1))
    sleep 2
done
