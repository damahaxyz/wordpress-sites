#!/usr/bin/env bash
# Server-root backups only. Never copies sensitive data to a public or remote target.
set -Eeuo pipefail
umask 077
project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
[[ "$(id -u)" == 0 && "${project_dir}" == /root/wordpress-sites/trovesia-shop ]] || exit 64
cd "${project_dir}"
backup_root=/root/trovesia-backups
mkdir -p "${backup_root}"
chmod 700 "${backup_root}"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
staging="$(mktemp -d "${backup_root}/.incomplete-${timestamp}-XXXXXX")"
BACKUP_DIR="${staging}" bash scripts/backup.sh
tar -czf "${staging}/deployment-config.tar.gz" .env compose.yaml nginx php security scripts
docker compose exec -T wordpress tar -C /var/www/html -czf - wp-config.php > "${staging}/wp-config.tar.gz"
tar -tzf "${staging}/deployment-config.tar.gz" >/dev/null
tar -tzf "${staging}/wp-config.tar.gz" >/dev/null
(cd "${staging}"; sha256sum ./*.gz > all-checksums.sha256; sha256sum -c all-checksums.sha256 >/dev/null)
find "${staging}" -type f -exec chmod 600 {} +
mv "${staging}" "${backup_root}/${timestamp}"
echo "Verified root-private backup: ${backup_root}/${timestamp}"
