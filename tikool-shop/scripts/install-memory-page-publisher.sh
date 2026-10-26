#!/usr/bin/env bash

set -Eeuo pipefail
umask 027

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_dir="$(cd -- "${script_dir}/.." && pwd)"
repo_dir="${MEMORY_PAGES_REPO_PATH:-/root/wordpress-sites/tikool-memory-pages}"
base_path="${MEMORY_PAGES_BASE_PATH:-public}"
socket_dir="${project_dir}/runtime/memory-page-publisher"
tasks_dir="${project_dir}/runtime/memory-page-tasks"

if [[ ! -d "${repo_dir}/.git" ]]; then
    echo "Memory Page Git checkout not found: ${repo_dir}" >&2
    exit 66
fi
if [[ ! "${base_path}" =~ ^[A-Za-z0-9_.-]+$ ]] || [[ "${base_path}" =~ ^bucket[1-9][0-9]*$ ]]; then
    echo "MEMORY_PAGES_BASE_PATH is invalid." >&2
    exit 64
fi
install -d -m 0755 -o root -g root "${repo_dir}/${base_path}"
install -d -m 2770 -o 33 -g 33 "${tasks_dir}"
install -d -m 0755 -o root -g root "${socket_dir}"
chmod 0755 "${script_dir}/memory-page-publisher.py"
install -m 0644 "${script_dir}/tikool-memory-page-publisher.service" /etc/systemd/system/tikool-memory-page-publisher.service

systemctl daemon-reload
systemctl enable tikool-memory-page-publisher.service
systemctl restart tikool-memory-page-publisher.service
systemctl --no-pager --full status tikool-memory-page-publisher.service
