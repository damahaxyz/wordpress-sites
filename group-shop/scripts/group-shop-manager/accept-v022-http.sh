#!/usr/bin/env bash
# Read-only acceptance using a private, short-lived session of the existing administrator.
set -Eeuo pipefail
umask 077
[[ "$PWD" == /root/wordpress-sites/group-shop ]] || exit 64
private_check_dir=$(mktemp -d /root/gsm-v022-http.XXXXXX)
cli=(docker compose --profile tools run --rm -T --user 0:0 -e TROVESIA_MAINTENANCE=1 -v "$PWD/scripts/group-shop-manager:/gsm-ops:ro" -v "$private_check_dir:/gsm-check" wpcli --allow-root --user=1)
cleanup() {
    local status=$?
    trap - EXIT
    "${cli[@]}" eval-file /gsm-ops/http-session.php close </dev/null || true
    rm -rf -- "$private_check_dir"
    exit "$status"
}
trap cleanup EXIT
"${cli[@]}" eval-file /gsm-ops/http-session.php open </dev/null
python3 scripts/group-shop-manager/check-store-list.py --cookie-file "$private_check_dir/headers" > security-reports/gsm-v022-list-http.json
python3 scripts/group-shop-manager/check-store-settings.py --cookie-file "$private_check_dir/headers" > security-reports/gsm-v022-store-http.json
python3 scripts/group-shop-manager/check-v020-admin.py --version 0.2.2 --cookie-file "$private_check_dir/headers" > security-reports/gsm-v022-admin-http.json
python3 scripts/group-shop-manager/check-http-routes.py > security-reports/gsm-v022-main-http.json
python3 scripts/group-shop-manager/check-http-routes.py --host test.animecats.online --subsite > security-reports/gsm-v022-test-http.json
python3 scripts/group-shop-manager/check-http-routes.py --cookie-file "$private_check_dir/headers" > security-reports/gsm-v022-main-auth.json
python3 scripts/group-shop-manager/check-http-routes.py --host test.animecats.online --subsite --cookie-file "$private_check_dir/headers" > security-reports/gsm-v022-test-auth.json
"${cli[@]}" eval-file /gsm-ops/store-settings-state.php > security-reports/gsm-v022-after.json </dev/null
cmp security-reports/gsm-v022-before.json security-reports/gsm-v022-after.json
python3 - <<'PY'
import json
from pathlib import Path
for p in sorted(Path('security-reports').glob('gsm-v022-*.json')):
    d=json.loads(p.read_text())
    if 'passed' in d: print(p.name, d['passed'])
print('Domain/path/home/siteurl, UUID, brand settings and user/order counts unchanged.')
PY
