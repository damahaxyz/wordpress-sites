#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
mode="${1:-check}"
if [[ "$(id -u)" != 0 || "$(basename "${project_dir}")" != trovesia-shop || ! -f "${project_dir}/compose.yaml" ]]; then
    echo 'Run on the Trovesia server as root from the managed project.' >&2
    exit 64
fi
cd "${project_dir}"
mkdir -p security-reports
chmod 700 security-reports
manifest="${project_dir}/security-reports/code-manifest.sha256"
temp_manifest=''
if [[ "${mode}" == check ]]; then
    report="${project_dir}/security-reports/check-$(date -u +%Y%m%dT%H%M%SZ)-$$.log"
    exec > >(tee -a "${report}") 2>&1
    trap 'status=$?; if [[ -n "${temp_manifest}" ]]; then rm -f -- "${temp_manifest}"; fi; echo "Security check exit=${status}; report=${report}"' EXIT
fi

case "${mode}" in
apply)
    for path in theme/trovesia plugin/trovesia-plugin plugin/trovesia-channel security; do
        test -d "${path}"
        chown -R root:root "${path}"
        find "${path}" -type d -exec chmod 755 {} +
        find "${path}" -type f -exec chmod 644 {} +
    done
    chown root:root scripts/security-baseline.sh scripts/maintenance-wp.sh scripts/deploy-security.sh scripts/deploy-component.sh scripts/security-watch.py scripts/rotate-database-credential.py scripts/security-backup.sh scripts/backup.sh
    chmod 750 scripts/security-baseline.sh scripts/maintenance-wp.sh scripts/deploy-security.sh scripts/deploy-component.sh scripts/security-watch.py scripts/rotate-database-credential.py scripts/security-backup.sh scripts/backup.sh
    chown root:root compose.yaml php/uploads.ini php/fpm-security.conf nginx/conf.d/default.conf .env
    chmod 644 compose.yaml php/uploads.ini php/fpm-security.conf nginx/conf.d/default.conf
    chmod 600 .env
    docker compose exec -T --user 0:0 wordpress sh -eu <<'SH'
for path in /var/www/html /var/www/html/wp-content; do
    chown 0:0 "$path"
    chmod 755 "$path"
    find "$path" -maxdepth 1 -type f -exec chown 0:0 {} + -exec chmod 644 {} +
done
for path in /var/www/html/wp-admin /var/www/html/wp-includes /var/www/html/wp-content/languages /var/www/html/wp-content/upgrade; do
    if [ -d "$path" ]; then
        chown -R 0:0 "$path"
        find "$path" -type d -exec chmod 755 {} +
        find "$path" -type f -exec chmod 644 {} +
    fi
done
# Skip deployment-managed read-only bind mounts; their host ownership is set above.
for path in /var/www/html/wp-content/plugins /var/www/html/wp-content/themes /var/www/html/wp-content/mu-plugins; do
    find "$path" \( -path /var/www/html/wp-content/plugins/trovesia-plugin -o -path /var/www/html/wp-content/plugins/trovesia-channel -o -path /var/www/html/wp-content/themes/trovesia -o -path /var/www/html/wp-content/mu-plugins/trovesia-security.php \) -prune -o -type d -exec chown 0:0 {} + -exec chmod 755 {} +
    find "$path" \( -path /var/www/html/wp-content/plugins/trovesia-plugin -o -path /var/www/html/wp-content/plugins/trovesia-channel -o -path /var/www/html/wp-content/themes/trovesia -o -path /var/www/html/wp-content/mu-plugins/trovesia-security.php \) -prune -o -type f -exec chown 0:0 {} + -exec chmod 644 {} +
done
for path in /var/www/html/wp-content/uploads /var/www/html/wp-content/cache; do
    mkdir -p "$path"
    chown -R 33:33 "$path"
    find "$path" -type d -exec chmod 755 {} +
    find "$path" -type f -exec chmod 644 {} +
done
chown 0:33 /var/www/html/wp-config.php
chmod 640 /var/www/html/wp-config.php
SH
    echo 'Applied root ownership and minimum writable directories.'
    ;;
seal)
    docker compose exec -T --user 33:33 wordpress php /opt/trovesia/filesystem-audit.php check
    temp_manifest="$(mktemp "${project_dir}/security-reports/.manifest.XXXXXX")"
    docker compose exec -T --user 0:0 wordpress php /opt/trovesia/filesystem-audit.php manifest > "${temp_manifest}"
    test -s "${temp_manifest}"
    mv "${temp_manifest}" "${manifest}"
    chmod 600 "${manifest}"
    echo 'Sealed authorized code manifest.'
    ;;
check)
    docker compose config --quiet
    [[ "$(stat -c '%u:%a' .env)" == 0:600 ]] || { echo 'FAIL: .env ownership/mode' >&2; exit 1; }
    mounts="$(docker inspect --format '{{range .Mounts}}{{println .Destination .RW}}{{end}}' "$(docker compose ps -q wordpress)")"
    for destination in /var/www/html/wp-content/themes/trovesia /var/www/html/wp-content/plugins/trovesia-plugin /var/www/html/wp-content/plugins/trovesia-channel /var/www/html/wp-content/mu-plugins/trovesia-security.php /opt/trovesia; do
        grep -Fxq "${destination} false" <<< "${mounts}" || { echo "FAIL: writable/missing code mount ${destination}" >&2; exit 1; }
    done
    docker compose exec -T --user 33:33 wordpress php /opt/trovesia/filesystem-audit.php check
    test -s "${manifest}"
    temp_manifest="$(mktemp "${project_dir}/security-reports/.check.XXXXXX")"
    docker compose exec -T --user 0:0 wordpress php /opt/trovesia/filesystem-audit.php manifest > "${temp_manifest}"
    if ! cmp -s "${manifest}" "${temp_manifest}"; then
        echo 'FAIL: code manifest changed. Investigate; do not blindly reseal.' >&2
        diff -u "${manifest}" "${temp_manifest}" | head -50 || true
        exit 1
    fi
    docker compose exec -T wordpress php -r '
        foreach (["exec","passthru","shell_exec","system","proc_open","popen","pcntl_exec","pcntl_fork"] as $f) { if (function_exists($f)) {fwrite(STDERR,"Enabled process function: ".$f.PHP_EOL);exit(1);} }
        if (ini_get("allow_url_include") || ini_get("display_errors") || ini_get("expose_php")) {exit(1);}
        echo "PHP execution restrictions verified\n";'
    docker compose --profile tools run --rm -T -v "${project_dir}/security:/security:ro" wpcli eval-file /security/verify-wordpress.php
    docker compose exec -T wordpress php /opt/trovesia/fpm-healthcheck.php
    docker compose exec -T nginx nginx -t
    for path in /wp-content/plugins/woocommerce/woocommerce.php /wp-content/uploads/security-probe.php /xmlrpc.php; do
        status="$(curl -sS --max-time 15 -o /dev/null -w '%{http_code}' -H 'Host: www.trovesia.com' "http://127.0.0.1:8083${path}")"
        [[ "${status}" == 404 ]] || { echo "FAIL blocked PHP route: ${path} (${status})" >&2; exit 1; }
    done
    echo 'PASS: permissions, manifest, login/code policies, dynamic health and blocked routes.'
    ;;
*) echo 'Usage: security-baseline.sh apply|seal|check' >&2; exit 64 ;;
esac
