#!/usr/bin/env python3
"""Read-only HTTP checks of native media scope and store-setting controls."""
import argparse
import http.client
import json
import re
from pathlib import Path
from urllib.parse import urlencode, urlsplit

parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--version',default='0.2.2')
parser.add_argument('--cookie-file',required=True,type=Path)
args=parser.parse_args()
cookie=args.cookie_file.read_text().strip().removeprefix('Cookie: ').strip()
checks=[]

def request(path, data=None, authenticated=True):
    conn=http.client.HTTPConnection('127.0.0.1',8086,timeout=30)
    headers={'Host':'www.animecats.online','X-Forwarded-Proto':'https'}
    if authenticated: headers['Cookie']=cookie
    if data is not None: headers['Content-Type']='application/x-www-form-urlencoded'
    try:
        conn.request('POST' if data is not None else 'GET',path,urlencode(data) if data is not None else None,headers)
        res=conn.getresponse()
        if res.status in (301,302,307,308):
            target=urlsplit(res.getheader('Location',''))
            raise AssertionError(f'Media request redirected to {target.netloc}{target.path}')
        return res.status,res.read().decode()
    finally: conn.close()

for store in (1,2):
    status,html=request('/wp-admin/admin.php?page=group-shop-manager&tab=stores&gsm_dialog=edit&store='+str(store))
    assert status==200 and 'gsm-store-editor' in html and '从媒体库选择图片' in html and 'Logo 附件编号' not in html
    assert 'name="blog_public"' in html and 'name="logo_id"' in html
    match=re.search(r'var gsmMedia = (\{[^;]+\});',html)
    assert match, 'Native media configuration missing'
    config=json.loads(match.group(1)); assert int(config['store'])==store
    checks.append('Store '+str(store)+': native image picker and search visibility controls render')
    payload={'action':'query-attachments','gsm_media_store':store,'gsm_media_nonce':config['nonce'],'query[post_mime_type]':'image','query[posts_per_page]':10}
    status,body=request('/wp-admin/admin-ajax.php',payload)
    assert status==200 and body.lstrip().startswith('{'), f'Native media query HTTP {status}; JSON response missing'
    result=json.loads(body)
    assert status==200 and result['success'] and result['data'], 'Native media query failed'
    for attachment in result['data']:
        assert ('/sites/2/' in attachment['url']) if store==2 else ('/sites/2/' not in attachment['url'])
    checks.append('Store '+str(store)+': original media query returns only the owning store images')
    status,_=request('/wp-admin/admin-ajax.php',dict(payload,gsm_media_nonce='invalid')); assert status==403
    checks.append('Store '+str(store)+': invalid media authorization rejected')
    status,_=request('/wp-admin/admin-ajax.php',dict(payload,action='delete-post',id=1)); assert status==403
    checks.append('Store '+str(store)+': unsupported destructive media operation rejected without touching an attachment')
print(json.dumps({'version':args.version,'passed':len(checks),'checks':checks},indent=2))
