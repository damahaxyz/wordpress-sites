#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
deploy_host="${DEPLOY_HOST:-root@site}"
deploy_path="${DEPLOY_PATH:-/root/wordpress-sites/trovesia-shop}"
[[ "${deploy_path}" =~ ^/[A-Za-z0-9._/-]+$ && "${deploy_path##*/}" == trovesia-shop ]] || exit 64
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
mode="${1:-full}"
[[ "$#" -le 1 && ( "${mode}" == full || "${mode}" == --monitor-only ) ]] || { echo 'Usage: deploy-security.sh [--monitor-only]' >&2; exit 64; }
deployment_started=0
cleanup_deployment() {
    status=$?
    trap - EXIT
    if [[ "${deployment_started}" == 1 ]]; then
        ssh -o ConnectTimeout=5 "${deploy_host}" "rm -f '${deploy_path}/security-reports/deployment-in-progress'" || true
    fi
    exit "${status}"
}
trap cleanup_deployment EXIT
cd "${project_dir}"
for file in security/*.php; do php -l "${file}" >/dev/null; done
python3 -c 'import ast, pathlib; ast.parse(pathlib.Path("scripts/security-watch.py").read_text())'
for file in scripts/security-baseline.sh scripts/deploy-component.sh scripts/maintenance-wp.sh; do bash -n "${file}"; done
ssh "${deploy_host}" "set -e; cd '${deploy_path}'; if [ -f security-reports/code-manifest.sha256 ]; then bash scripts/security-baseline.sh check; fi; mkdir -p security deploy-backups runtime/security-logs/nginx; chmod 750 runtime/security-logs/nginx; tar -czf 'deploy-backups/security-${timestamp}.tar.gz' compose.yaml nginx php scripts security"
ssh "${deploy_host}" "mkdir -p '${deploy_path}/security-reports'; chmod 700 '${deploy_path}/security-reports'; touch '${deploy_path}/security-reports/deployment-in-progress'"
deployment_started=1
ssh "${deploy_host}" "mkdir -p '${deploy_path}/tests/security' '${deploy_path}/scripts/maintenance'"
rsync -az tests/security/ "${deploy_host}:${deploy_path}/tests/security/"
rsync -az scripts/maintenance/ "${deploy_host}:${deploy_path}/scripts/maintenance/"
# Retire known incident/test tools from the web container's read-only runtime
# mount. The archive above preserves every original file, including server-only tools.
ssh "${deploy_host}" "
    set -e
    cd '${deploy_path}'
    archive='deploy-backups/security-tools-${timestamp}'
    mkdir -p \"\$archive\"
    chmod 700 \"\$archive\" scripts/maintenance
    for file in admin-access-self-test.php media-self-test.php self-test.php rekey-fluent-smtp.php inspect-mail.php; do
        if [ -f \"security/\$file\" ]; then mv \"security/\$file\" \"\$archive/\$file\"; fi
    done
    if [ -f tests/security/admin-access-self-test.php ]; then
        mv tests/security/admin-access-self-test.php \"\$archive/legacy-admin-access-self-test.php\"
    fi
"
if [[ "${mode}" == --monitor-only ]]; then
    rsync -az scripts/security-watch.py scripts/deploy-security.sh "${deploy_host}:${deploy_path}/scripts/"
    rsync -az security/trovesia-security-watch.service "${deploy_host}:${deploy_path}/security/"
    ssh "${deploy_host}" "
        set -e
        cd '${deploy_path}'
        chown root:root scripts/security-watch.py scripts/deploy-security.sh security/trovesia-security-watch.service
        chmod 750 scripts/security-watch.py scripts/deploy-security.sh
        chmod 644 security/trovesia-security-watch.service
        bash scripts/security-baseline.sh check
        cp security/trovesia-security-watch.service /etc/systemd/system/trovesia-security-watch.service
        chmod 644 /etc/systemd/system/trovesia-security-watch.service
        systemctl daemon-reload
        systemctl restart trovesia-security-watch.service
    "
    echo "Monitor/tools deployed at ${timestamp}; web containers and administrator sessions preserved."
    exit
fi
rsync -az security/ "${deploy_host}:${deploy_path}/security/"
rsync -az --inplace compose.yaml "${deploy_host}:${deploy_path}/compose.yaml"
rsync -az --inplace nginx/conf.d/default.conf "${deploy_host}:${deploy_path}/nginx/conf.d/default.conf"
rsync -az --inplace php/uploads.ini php/fpm-security.conf "${deploy_host}:${deploy_path}/php/"
rsync -az nginx/cache-watch.sh "${deploy_host}:${deploy_path}/nginx/cache-watch.sh"
rsync -az scripts/security-baseline.sh scripts/maintenance-wp.sh scripts/deploy-security.sh scripts/deploy-component.sh scripts/security-watch.py scripts/rotate-database-credential.py scripts/security-backup.sh scripts/backup.sh "${deploy_host}:${deploy_path}/scripts/"
ssh "${deploy_host}" "
    set -e
    cd '${deploy_path}'
    docker compose config --quiet
    docker compose up -d --no-deps --force-recreate --pull never wordpress
    if [ -f security/admin-access.json ]; then
        mv security/admin-access.json 'deploy-backups/security-tools-${timestamp}/admin-access.json'
    fi
    bash scripts/security-baseline.sh apply
    cp security/logrotate-trovesia.conf /etc/logrotate.d/trovesia-shop-security
    chown root:root /etc/logrotate.d/trovesia-shop-security
    chmod 644 /etc/logrotate.d/trovesia-shop-security
    logrotate --debug /etc/logrotate.d/trovesia-shop-security >/dev/null 2>&1
    docker compose up -d --no-deps --force-recreate --pull never nginx
    docker compose up -d --wait --wait-timeout 180 wordpress nginx
    bash scripts/security-baseline.sh seal
    bash scripts/security-baseline.sh check
    cp security/trovesia-security-watch.service /etc/systemd/system/trovesia-security-watch.service
    chmod 644 /etc/systemd/system/trovesia-security-watch.service
    systemctl daemon-reload
    systemctl enable --now trovesia-security-watch.service
    systemctl restart trovesia-security-watch.service
    docker compose --profile tools run --rm -T wpcli trovesia page-cache purge
"
echo "Security baseline deployed and verified at ${timestamp}."
