#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
[[ "$(id -u)" == 0 && "$(basename "${project_dir}")" == trovesia-shop ]] || { echo 'Server root operator required.' >&2; exit 64; }
cd "${project_dir}"
echo 'Controlled root maintenance: code changes require verification and resealing afterwards.'
docker compose --profile tools run --rm -T --user 0:0 -e TROVESIA_MAINTENANCE=1 wpcli --allow-root "$@"
