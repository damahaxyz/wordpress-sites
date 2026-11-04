#!/usr/bin/env python3
"""Read-only HTTP verification against synthetic loopback QA, after integration.php."""
import argparse
import http.client
import json
from pathlib import Path

parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port',type=int,default=18087,choices=[18086,18087])
parser.add_argument('--report',type=Path,default=Path('qa-report-v030.json'))
args=parser.parse_args()
root = Path.cwd()
if root.name != 'group-shop-manager-qa':
    raise SystemExit('Disposable QA checkout required')
fixture = json.loads(args.report.read_text())['fixtures']['classification']
checks = []

def get(path):
    connection = http.client.HTTPConnection('127.0.0.1', args.port, timeout=30)
    try:
        connection.request('GET', path, headers={'Host': 'localhost:'+str(args.port)})
        response = connection.getresponse()
        body = response.read().decode()
        assert response.status == 200, (path, response.status)
        return body
    finally:
        connection.close()

def check(ok, name):
    assert ok, name
    checks.append(name)

path = '/brand-a/product-category/' + fixture['collection_slug'] + '/'
body = get(path)
check('QA Anime room' in body and 'Showing all 2 results' in body, 'Native themed archive displays its collection and two products')
check('QA Extra lamp' in body and 'QA Included lamp' in body, 'Real archive contains exactly the expected synthetic products')
nav = get('/brand-a/qa-collections/')
check('gsm-collections' in nav and fixture['collection_slug'] in nav, 'Navigation shortcode renders links inside the active theme')
base = '/brand-a/wp-json/wc/store/v1/products?category=' + str(fixture['collection'])
one = json.loads(get(base + '&per_page=1&page=1'))
two = json.loads(get(base + '&per_page=1&page=2'))
check(len(one) == 1 and len(two) == 1 and one[0]['id'] != two[0]['id'], 'Public Store API pagination returns distinct collection members')
check({one[0]['id'], two[0]['id']} == {fixture['included'], fixture['priority']}, 'Public API category filtering agrees with the native archive')
check(one[0]['is_in_stock'] and two[0]['is_in_stock'], 'Public API reports unlimited products in stock')
print(json.dumps({'version': '0.3.0', 'passed': len(checks), 'checks': checks}, indent=2))
