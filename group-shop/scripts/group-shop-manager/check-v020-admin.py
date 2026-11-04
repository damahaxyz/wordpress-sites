#!/usr/bin/env python3
"""Read-only, authenticated loopback HTTP acceptance for the v0.2.0 production UI."""
import argparse
import http.client
import json
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--cookie-file', required=True, type=Path)
parser.add_argument('--version', default='0.2.0')
args = parser.parse_args()
cookie = args.cookie_file.read_text().strip().removeprefix('Cookie: ').strip()
checks = []

def get(host, path):
    connection = http.client.HTTPConnection('127.0.0.1', 8086, timeout=30)
    try:
        connection.request('GET', path, headers={'Host': host, 'X-Forwarded-Proto': 'https', 'Cookie': cookie})
        response = connection.getresponse()
        body = response.read().decode()
        assert response.status == 200, (host, path, response.status)
        return body
    finally:
        connection.close()

for tab in ['overview', 'stores', 'templates', 'categories', 'collections', 'catalog', 'products', 'orders', 'customers', 'reviews', 'audit', 'help']:
    body = get('www.animecats.online', '/wp-admin/admin.php?page=group-shop-manager&store=2&tab=' + tab)
    assert ('v'+args.version) in body and 'notice-error' not in body and '批量库存' not in body, tab
    checks.append('Authenticated production UI: ' + tab)
body = get('www.animecats.online', '/wp-admin/admin.php?page=group-shop-manager&store=2&tab=products&product=18')
assert 'name="category_ids[]"' in body and 'name="tag_ids[]"' in body and '分类编号' not in body
checks.append('Production draft product editor uses category/tag pickers')
products = json.loads(get('www.animecats.online', '/wp-json/wc/store/v1/products?per_page=100'))
assert products and all(product['is_in_stock'] for product in products)
checks.append('All public master products report in-stock through the native Store API')
drafts = json.loads(get('test.animecats.online', '/wp-json/wc/store/v1/products?per_page=100'))
assert drafts == []
checks.append('Second-store draft products remain absent from the public product API')
print(json.dumps({'version': args.version, 'passed': len(checks), 'checks': checks}, indent=2))
