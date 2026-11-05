#!/usr/bin/env python3
"""Read-only production HTTP checks for the store list and same-origin dialog forms."""
import argparse, http.client, json, re
from pathlib import Path
p=argparse.ArgumentParser(description=__doc__)
p.add_argument('--cookie-file',type=Path,required=True)
a=p.parse_args(); cookie=a.cookie_file.read_text().strip().removeprefix('Cookie: ').strip(); checks=[]
def get(query):
    c=http.client.HTTPConnection('127.0.0.1',8086,timeout=30)
    try:
        c.request('GET','/wp-admin/admin.php?page=muti-storefront-plugin&tab=stores'+query,headers={'Host':'www.animecats.online','X-Forwarded-Proto':'https','Cookie':cookie})
        r=c.getresponse(); body=r.read().decode(); assert r.status==200, r.status
        assert 'notice-error' not in body
        return body
    finally: c.close()
h=get('')
assert 'v0.2.2' in h and h.count('<tr data-store=')==2
checks.append('Exactly two stores appear as individual list records')
assert 'https://www.animecats.online' in h and 'https://test.animecats.online' in h
checks.append('Existing main and test domain addresses remain visible')
assert 'data-gsm-store-dialog="新增店铺"' in h and h.count('data-gsm-store-dialog="编辑店铺"')==2
checks.append('Create and per-store edit links open same-origin dialog forms')
assert '<dialog ' in h and '<iframe title="店铺设置表单"' in h and 'name="logo_id"' not in h and '选择店铺 <select' not in h
checks.append('List contains modal shell without old inline form or selector')
for store,domain in ((1,'www.animecats.online'),(2,'test.animecats.online')):
    h=get('&gsm_dialog=edit&store='+str(store))
    assert f'name="domain" value="{domain}"' in h
    assert bool(re.search(r'name="domain"[^>]+readonly',h))==(store==1)
    checks.append(f'Store {store}: native domain value with correct main-domain protection')
    assert 'gsm-store-dialog-page' in h and 'gsm-store-editor' in h and 'nav-tab-wrapper' not in h and 'name="gsm_dialog" value="edit"' in h
    assert '从媒体库选择图片' in h and 'name="blog_public"' in h
    checks.append(f'Store {store}: compact editor retains scoped native media and visibility controls')
h=get('&gsm_dialog=create')
assert 'name="domain"' in h and 'name="name"' in h and 'name="slug"' not in h and 'name="gsm_dialog" value="create"' in h
checks.append('Create popup accepts name and domain without a technical internal slug')
h=get('&gsm_dialog=edit&store=2&gsm_saved=invalid')
assert 'data-gsm-store-saved' not in h and 'name="domain"' in h
checks.append('An invalid success token cannot falsely signal a saved store')
print(json.dumps({'version':'0.2.2','passed':len(checks),'checks':checks},indent=2))
