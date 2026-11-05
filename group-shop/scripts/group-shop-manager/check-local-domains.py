#!/usr/bin/env python3
"""Read-only HTTP acceptance for the explicitly mapped, isolated local QA."""
import html
import http.cookiejar
import json
import re
import socket
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path('/private/tmp/group-shop-manager-qa')
HOSTS = ['www.main.local'] + [f'www.brand{x}.local' for x in 'abcefg']
assert 'GSM_QA_DOMAIN=www.main.local' in (ROOT / '.qa.env').read_text()
for host in HOSTS:
    assert socket.gethostbyname(host) == '127.0.0.1', f'{host} is not loopback'


class LocalRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        target = urllib.parse.urlsplit(newurl)
        assert target.scheme == 'http' and target.hostname in HOSTS, 'Unexpected redirect'
        return super().redirect_request(req, fp, code, msg, headers, newurl)


jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(
    urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar), LocalRedirect()
)
checks = []
logouts = []


def check(label, passed):
    checks.append({'check': label, 'passed': bool(passed)})
    assert passed, label


def request(url, data=None, binary=False):
    if data is not None:
        data = urllib.parse.urlencode(data).encode()
    with opener.open(url, data=data, timeout=30) as response:
        body = response.read()
        return response.geturl(), response.status, body if binary else body.decode('utf8')


try:
    password = (ROOT / '.qa-admin-password').read_text().strip()
    for host in HOSTS:
        base = 'http://' + host
        final, status, body = request(base + '/')
        check(host + ': frontend', status == 200 and urllib.parse.urlsplit(final).hostname == host)
        final, status, body = request(base + '/wp-admin/')
        check(host + ': separate login', '/wp-login.php' in final)
        check(host + ': login action', base + '/wp-login.php' in body)
        final, status, body = request(base + '/wp-login.php', {
            'log': 'gsm-admin', 'pwd': password, 'wp-submit': 'Log In',
            'redirect_to': base + '/wp-admin/', 'testcookie': '1',
        })
        check(host + ': authenticated admin', status == 200 and '/wp-admin/' in final and 'wp-admin-bar-my-account' in body)
        check(host + ': host-only authentication', any(
            c.name.startswith('wordpress_logged_in_') and c.domain.lstrip('.') == host
            for c in jar
        ))
        logout = re.search(r'href=[\'\"]([^\'\"]*wp-login\.php\?action=logout[^\'\"]*)', body)
        assert logout, 'No logout link'
        logouts.append(html.unescape(logout.group(1)))

    main = 'http://' + HOSTS[0]
    _, _, body = request(main + '/wp-admin/admin.php?page=muti-storefront-plugin')
    check('manager: all seven domains', all('http://' + h in body for h in HOSTS))
    check('manager: no old localhost links', 'localhost:18087' not in body)
    _, _, body = request(main + '/wp-admin/admin.php?page=muti-storefront-plugin&tab=products&store=6&product=32')
    config = re.search(r'var gsmMedia\s*=\s*(\{[^;]+\});', body)
    assert config, 'Missing authorized store-media configuration'
    media = json.loads(config.group(1))
    _, _, body = request(main + '/wp-admin/admin-ajax.php', {
        'action': 'query-attachments', 'gsm_media_store': media['store'],
        'gsm_media_nonce': media['nonce'], 'query[posts_per_page]': '10',
    })
    response = json.loads(body)
    check('store A: authorized media query', response.get('success') and bool(response.get('data')))
    urls = [item['url'] for item in response['data']]
    check('store A: own media host and directory', all(
        urllib.parse.urlsplit(u).hostname == HOSTS[1] and '/uploads/sites/6/' in u for u in urls
    ))
    _, status, image = request(urls[0], binary=True)
    check('store A: image accessible', status == 200 and bool(image))
    final, _, _ = request('http://' + HOSTS[1] + '/wp-admin/edit-tags.php?taxonomy=product_cat&post_type=product')
    check('shared categories: canonical main admin', urllib.parse.urlsplit(final).hostname == HOSTS[0])
finally:
    for logout in logouts:
        try:
            request(logout)
        except Exception:
            pass

report = {'hosts': HOSTS, 'passed': len(checks), 'checks': checks}
(ROOT / 'qa-local-domains.json').write_text(json.dumps(report, indent=2))
print(json.dumps(report, indent=2))
