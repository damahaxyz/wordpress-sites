#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_dir="$(cd -- "${script_dir}/.." && pwd)"

deploy_host="${DEPLOY_HOST:-root@contabo2}"
deploy_path="${DEPLOY_PATH:-/root/wordpress-sites/tikool-shop}"
deploy_url="${DEPLOY_URL:-https://www.tikool.com/}"
timestamp="$(date -u +'%Y%m%dT%H%M%SZ')"

if (($# == 0)); then
    echo "Usage: $0 theme [plugin] [frame-customizer] [ai] [memory-page]" >&2
    exit 64
fi

if [[ ! "${deploy_path}" =~ ^/[A-Za-z0-9._/-]+$ ]]; then
    echo "DEPLOY_PATH must be an absolute path containing only letters, numbers, '.', '_', '-' and '/'." >&2
    exit 64
fi

declare -a component_types=()
declare -a component_slugs=()
declare -a local_paths=()
declare -a required_files=()

for component in "$@"; do
    case "${component}" in
        theme)
            component_types+=("theme")
            component_slugs+=("tikool")
            local_paths+=("${project_dir}/theme/tikool")
            required_files+=("style.css")
            ;;
        plugin)
            component_types+=("plugin")
            component_slugs+=("tikool-plugin")
            local_paths+=("${project_dir}/plugin/tikool-plugin")
            required_files+=("tikool-plugin.php")
            ;;
        frame-customizer)
            component_types+=("plugin")
            component_slugs+=("tikool-frame-customizer")
            local_paths+=("${project_dir}/plugin/tikool-frame-customizer")
            required_files+=("tikool-frame-customizer.php")
            ;;
        ai)
            component_types+=("plugin")
            component_slugs+=("tikool-ai")
            local_paths+=("${project_dir}/plugin/tikool-ai")
            required_files+=("tikool-ai.php")
            ;;
        memory-page)
            component_types+=("plugin")
            component_slugs+=("tikool-memory-page")
            local_paths+=("${project_dir}/plugin/tikool-memory-page")
            required_files+=("tikool-memory-page.php")
            ;;
        *)
            echo "Unknown component: ${component}. Expected theme, plugin, frame-customizer, ai, or memory-page." >&2
            exit 64
            ;;
    esac
done

for index in "${!component_types[@]}"; do
    local_path="${local_paths[${index}]}"
    required_file="${required_files[${index}]}"

    if [[ ! -f "${local_path}/${required_file}" ]]; then
        echo "Required file not found: ${local_path}/${required_file}" >&2
        exit 66
    fi
done

if command -v php >/dev/null 2>&1; then
    echo "Checking PHP syntax..."

    for local_path in "${local_paths[@]}"; do
        while IFS= read -r -d '' php_file; do
            php -l "${php_file}" >/dev/null
        done < <(find "${local_path}" -type f -name '*.php' -print0)
    done
fi

echo "Preparing ${deploy_host}:${deploy_path}..."
ssh "${deploy_host}" \
    "mkdir -p '${deploy_path}/theme' '${deploy_path}/plugin' '${deploy_path}/deploy-backups'"

for index in "${!component_types[@]}"; do
    component_type="${component_types[${index}]}"
    component_slug="${component_slugs[${index}]}"
    local_path="${local_paths[${index}]}"
    remote_parent="${deploy_path}/${component_type}"
    remote_path="${remote_parent}/${component_slug}"
    backup_path="${deploy_path}/deploy-backups/${component_slug}-${timestamp}.tar.gz"

    echo "Backing up ${component_slug} when a deployed copy exists..."
    ssh "${deploy_host}" \
        "if [ -d '${remote_path}' ]; then tar -C '${remote_parent}' -czf '${backup_path}' '${component_slug}'; fi"

    echo "Deploying ${component_slug}..."
    rsync \
        -az \
        --delete-delay \
        --delay-updates \
        --exclude '.DS_Store' \
        --exclude '.git/' \
        --exclude 'node_modules/' \
        "${local_path}/" \
        "${deploy_host}:${remote_path}/"
done

echo "Validating Docker configuration without interrupting active requests..."
ssh "${deploy_host}" "
    cd '${deploy_path}'
    docker compose config --quiet
    docker compose up -d --wait --wait-timeout 180 wordpress nginx
    docker compose --profile tools run --rm wpcli tikool page-cache purge
"

if [[ -n "${deploy_url}" ]]; then
    echo "Checking ${deploy_url}..."
    curl \
        --fail \
        --location \
        --silent \
        --show-error \
        --output /dev/null \
        "${deploy_url}"
fi

echo "Deployment completed at ${timestamp}."
