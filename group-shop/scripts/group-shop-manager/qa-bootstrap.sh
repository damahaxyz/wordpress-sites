#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
qa_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
[[ "${qa_root##*/}" == group-shop-manager-qa ]] || { echo 'Use only the isolated group-shop-manager-qa checkout.' >&2; exit 64; }
cd "$qa_root"
if [[ ! -f .qa.env ]]; then
  python3 - <<'PY'
import secrets
from pathlib import Path
Path('.qa.env').write_text('GSM_QA_DB_PASSWORD='+secrets.token_urlsafe(36)+'\nGSM_QA_ROOT_PASSWORD='+secrets.token_urlsafe(36)+'\nGSM_QA_MULTISITE=0\nGSM_QA_PORT=18087\n')
Path('.qa.env').chmod(0o600)
Path('.qa-admin-password').write_text(secrets.token_urlsafe(28)+'\n')
Path('.qa-admin-password').chmod(0o600)
PY
fi
qa_port="$(sed -n 's/^GSM_QA_PORT=//p' .qa.env)"
qa_port="${qa_port:-18086}"
[[ "$qa_port" =~ ^[0-9]{4,5}$ ]] || exit 64
qa_domain="$(sed -n 's/^GSM_QA_DOMAIN=//p' .qa.env)"
qa_domain="${qa_domain:-localhost:$qa_port}"
[[ "$qa_domain" == "localhost:$qa_port" || "$qa_domain" == www.main.local ]] || { echo 'Only the isolated local QA host is permitted.' >&2; exit 64; }
qa_url="http://$qa_domain"
compose=(docker compose --project-directory "$qa_root" --env-file "$qa_root/.qa.env" -f "$qa_root/scripts/group-shop-manager/compose.qa.yaml")
qawp() { "${compose[@]}" --profile tools run --rm -T wpcli --allow-root --url="$qa_url" "$@" </dev/null; }
"${compose[@]}" up -d --wait --wait-timeout 120 db
"${compose[@]}" up -d wordpress nginx
# Copy only the installed, trusted WooCommerce code; never copy live data or settings.
"${compose[@]}" exec -T wordpress sh -ec 'until test -f /var/www/html/wp-config.php; do sleep 1; done' </dev/null
if ! qawp core is-installed >/dev/null 2>&1; then
  "${compose[@]}" --profile tools run --rm -T wpcli --allow-root core install --url="$qa_url" --title='Group Shop QA' --admin_user=gsm-admin --admin_email=qa@example.invalid --skip-email --prompt=admin_password < .qa-admin-password > .qa-install.log 2>&1
fi
if [[ "$(sed -n 's/^GSM_QA_MULTISITE=//p' .qa.env)" != 1 ]]; then
  qawp core multisite-convert --title='Group Shop QA Network' --base=/ --skip-config
  python3 - <<'PYENV'
from pathlib import Path
p=Path('.qa.env'); p.write_text(p.read_text().replace('GSM_QA_MULTISITE=0','GSM_QA_MULTISITE=1')); p.chmod(0o600)
PYENV
  "${compose[@]}" up -d --no-deps wordpress
  "${compose[@]}" exec -T nginx nginx -s reload </dev/null
fi
# Official, version-pinned plugin code only. No production database or volume is mounted.
install_qa_plugin() {
  local plugin="$1" version="$2"
  if qawp plugin is-installed "$plugin" >/dev/null 2>&1; then return; fi
  if [[ -f "$qa_root/qa-packages/$plugin.$version.zip" ]]; then
    "${compose[@]}" --profile tools run --rm -T -v "$qa_root/qa-packages:/gsm-packages:ro" wpcli --allow-root plugin install "/gsm-packages/$plugin.$version.zip" </dev/null
  else
    qawp plugin install "$plugin" --version="$version"
  fi
}
install_qa_plugin woocommerce 11.1.2
install_qa_plugin fluent-smtp 2.4.1
qawp plugin activate woocommerce --network
qawp plugin activate fluent-smtp --network
qawp theme enable trovesia --network
qawp theme activate trovesia
qawp plugin activate product-addon-plugin --network
qawp plugin activate muti-storefront-plugin --network
qawp eval 'WC_Install::install(); WC_Install::create_pages(); update_option("woocommerce_enable_myaccount_registration", "yes"); update_option("woocommerce_registration_generate_password", "no"); update_option("permalink_structure", "/%postname%/"); flush_rewrite_rules(false);'
"${compose[@]}" exec -T wordpress chown -R 33:33 /var/www/html/wp-content/uploads </dev/null
qawp core is-installed --network
echo "QA network ready at $qa_url (loopback only; mail and payments blocked)."
