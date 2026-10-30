#!/usr/bin/env python3
"""Manual public-edge checks from a non-allowlisted host, without credentials."""
import json
import re
import subprocess
import sys
import urllib.error
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
# A guest nonce permits a real logout redirect without using any user's session.
nonce = subprocess.check_output([
    'docker', 'compose', '--profile', 'tools', 'run', '--rm', '-T',
    'wpcli', '--quiet', 'eval', 'wp_set_current_user(0); echo wp_create_nonce("log-out");',
], text=True).strip()
if not re.fullmatch(r'[A-Za-z0-9]{10}', nonce):
    raise RuntimeError('Could not generate a guest logout nonce')
logout_path = '/wp-login.php?action=logout&_wpnonce=' + nonce + '&redirect_to=%2Fchannel-portal%2F'
cases = [
    ('home', '/', 'GET', 200),
    ('admin', '/wp-admin', 'GET', 403),
    ('admin-child', '/wp-admin/index.php', 'GET', 403),
    ('login-get', '/wp-login.php', 'GET', 403),
    ('login-post', '/wp-login.php', 'POST', 403),
    ('logout-post', '/wp-login.php?action=logout', 'POST', 403),
    ('logout-get', logout_path, 'GET', 302),
    ('logout-wrong-value', '/wp-login.php?action=logout-other', 'GET', 403),
    ('logout-substring', '/wp-login.php?redirect_to=action=logout', 'GET', 403),
    ('logout-duplicate', '/wp-login.php?action=logout&action=login', 'GET', 403),
    ('logout-encoded-duplicate', '/wp-login.php?action=logout&%61ction=login', 'GET', 403),
    ('public-ajax', '/wp-admin/admin-ajax.php', 'GET', 400),
    ('channel', '/channel-portal/', 'GET', 200),
    ('cart', '/wp-json/wc/store/v1/cart', 'GET', 200),
]
checks = []
for name, path, method, expected in cases:
    request = urllib.request.Request('https://www.trovesia.com' + path, method=method,
                                     headers={'User-Agent': 'Mozilla/5.0'})
    try:
        try:
            response = opener.open(request, timeout=25)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            status = response.status
            body = response.read().decode('utf-8', errors='replace')
            cf = bool(response.headers.get('cf-ray'))
        blocked = 'cf-error-details' in body or 'Attention Required! | Cloudflare' in body
        passed = status == expected and cf and (expected != 403 or blocked)
        checks.append(dict(name=name, status=status, expected=expected, cloudflare=cf,
                           edge_block=blocked, passed=passed))
    except (OSError, urllib.error.URLError) as error:
        checks.append(dict(name=name, error=type(error).__name__, passed=False))
passed = all(check['passed'] for check in checks)
print(json.dumps(dict(checks=checks, passed=passed), indent=2))
sys.exit(0 if passed else 1)
