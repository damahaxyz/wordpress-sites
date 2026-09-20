#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
project_dir="$(cd -- "${script_dir}/.." && pwd)"
backup_dir="${BACKUP_DIR:-${project_dir}/backups}"
timestamp="$(date -u +'%Y%m%dT%H%M%SZ')"

mkdir -p -- "${backup_dir}"

database_file="${backup_dir}/database-${timestamp}.sql.gz"
content_file="${backup_dir}/wp-content-${timestamp}.tar.gz"
checksum_file="${backup_dir}/checksums-${timestamp}.sha256"
database_tmp="$(mktemp "${backup_dir}/.database.XXXXXX")"
content_tmp="$(mktemp "${backup_dir}/.wp-content.XXXXXX")"

cleanup() {
  rm -f -- "${database_tmp}" "${content_tmp}"
}
trap cleanup EXIT

cd -- "${project_dir}"

echo "Backing up MariaDB..."
docker compose exec -T db sh -ec '
  exec mariadb-dump \
    --user=root \
    --password="$MARIADB_ROOT_PASSWORD" \
    --single-transaction \
    --quick \
    --routines \
    --events \
    --triggers \
    --hex-blob \
    --databases "$MARIADB_DATABASE"
' | gzip -9 > "${database_tmp}"

echo "Backing up WordPress wp-content..."
docker compose exec -T wordpress \
  tar -C /var/www/html -czf - wp-content > "${content_tmp}"

gzip -t "${database_tmp}"
tar -tzf "${content_tmp}" > /dev/null

mv -- "${database_tmp}" "${database_file}"
mv -- "${content_tmp}" "${content_file}"

if command -v sha256sum > /dev/null 2>&1; then
  (
    cd -- "${backup_dir}"
    sha256sum "$(basename -- "${database_file}")" "$(basename -- "${content_file}")"
  ) > "${checksum_file}"
else
  (
    cd -- "${backup_dir}"
    shasum -a 256 "$(basename -- "${database_file}")" "$(basename -- "${content_file}")"
  ) > "${checksum_file}"
fi

echo "Backup completed:"
printf '  %s\n  %s\n  %s\n' \
  "${database_file}" \
  "${content_file}" \
  "${checksum_file}"
