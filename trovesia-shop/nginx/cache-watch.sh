#!/bin/sh

set -eu

runtime_dir="/var/run/trovesia-page-cache"
generation_file="${runtime_dir}/generation.conf"
check_interval="${TROVESIA_CACHE_WATCH_INTERVAL:-2}"

mkdir -p "${runtime_dir}"
chmod 0777 "${runtime_dir}"

if [ ! -f "${generation_file}" ]; then
    printf 'set $trovesia_cache_generation "bootstrap";\n' > "${generation_file}"
fi
chmod 0666 "${generation_file}"

generation_checksum() {
    cksum "${generation_file}" 2>/dev/null | awk '{ print $1 ":" $2 }'
}

nginx -t
nginx -g 'daemon off;' &
nginx_pid=$!
last_checksum="$(generation_checksum)"

shutdown_nginx() {
    nginx -s quit >/dev/null 2>&1 || true
    wait "${nginx_pid}" 2>/dev/null || true
    exit 0
}

trap shutdown_nginx INT TERM QUIT

while kill -0 "${nginx_pid}" 2>/dev/null; do
    sleep "${check_interval}" &
    wait $! || true

    current_checksum="$(generation_checksum)"
    if [ "${current_checksum}" = "${last_checksum}" ]; then
        continue
    fi

    if nginx -t; then
        nginx -s reload
        last_checksum="${current_checksum}"
        printf '%s\n' "Trovesia page cache generation changed; Nginx reloaded."
    else
        printf '%s\n' "Trovesia page cache generation is invalid; keeping the current Nginx configuration." >&2
    fi
done

wait "${nginx_pid}"
