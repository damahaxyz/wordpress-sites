#!/usr/bin/env python3
"""Real loopback HTTP regression; redirects must preserve WordPress admin routes."""
import argparse
import http.client
import json
from pathlib import Path
from urllib.parse import urlsplit

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=8086)
parser.add_argument('--host', default='www.animecats.online')
parser.add_argument('--store', default='', help='Optional virtual subdirectory store slug')
parser.add_argument('--subsite', action='store_true', help='Current host is a mapped child store')
parser.add_argument('--network-host', default='www.animecats.online')
parser.add_argument('--cookie-file', type=Path, help='Private temporary Cookie header; never printed')
args = parser.parse_args()
cookie = args.cookie_file.read_text().strip().removeprefix('Cookie: ').strip() if args.cookie_file else ''
results = []

def get(path, host=None):
    headers = {'Host': host or args.host, 'X-Forwarded-Proto': 'https'}
    if cookie:
        headers['Cookie'] = cookie
    connection = http.client.HTTPConnection('127.0.0.1', args.port, timeout=30)
    try:
        connection.request('GET', path, headers=headers)
        response = connection.getresponse()
        return response.status, response.getheader('Location'), response.getheader('Content-Type', ''), response.read()
    finally:
        connection.close()

def follow(path):
    seen = set()
    host = args.host
    for _ in range(8):
        if (host, path) in seen:
            raise AssertionError(f'Redirect loop: {path}')
        seen.add((host, path))
        status, location, content_type, body = get(path, host)
        if status not in (301, 302, 303, 307, 308):
            return path, status, content_type, body, len(seen)-1
        target = urlsplit(location or '')
        assert not target.netloc or target.netloc in {args.host, args.network_host}, 'Unexpected redirect host'
        host = target.netloc or host
        path = target.path + ('?' + target.query if target.query else '')
    raise AssertionError('More than eight HTTP redirects')

prefixes = ('', '/' + args.store.strip('/')) if args.store.strip('/') else ('',)
for prefix in prefixes:
    management = '/wp-admin/admin.php?page=group-shop-manager' if not prefix and not args.subsite else '/wp-admin/edit.php?post_type=product'
    for suffix in ('/wp-admin/', '/wp-admin/index.php', management, '/wp-admin/network/', '/wp-login.php'):
        path = prefix + suffix
        final, status, _, body, hops = follow(path)
        assert status == 200, f'{path}: HTTP {status}'
        if cookie and suffix != '/wp-login.php':
            assert b'id="wpbody"' in body, f'{path}: not an authenticated admin page'
            if 'group-shop-manager' in suffix:
                assert b'Group Shop Manager' in body, f'{path}: manager page missing'
        elif not cookie:
            assert '/wp-login.php' in final and b'id="loginform"' in body, f'{path}: login page missing'
        results.append({'path': path, 'status': status, 'redirects': hops})
    static = prefix + '/wp-admin/css/common.min.css'
    _, status, content_type, body, hops = follow(static)
    assert status == 200 and 'text/css' in content_type and body, f'{static}: core CSS failed'
    results.append({'path': static, 'status': status, 'redirects': hops})
    for suffix in ('/wp-content/plugins/woocommerce/woocommerce.php', '/wp-content/uploads/security-probe.php'):
        path = prefix + suffix
        status, _, _, _ = get(path)
        assert status == 404, f'{path}: PHP protection failed ({status})'
        results.append({'path': path, 'status': status})

print(json.dumps({'authenticated': bool(cookie), 'passed': len(results), 'checks': results}, indent=2))
