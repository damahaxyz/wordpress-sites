#!/usr/bin/env python3
"""Authenticated native-category acceptance, restricted to the local synthetic QA network."""
import html, http.cookiejar, json, re
from pathlib import Path
from urllib.parse import urlencode, urlsplit
from urllib.request import build_opener, HTTPCookieProcessor, ProxyHandler, Request
root=Path.cwd()
if root.name!='group-shop-manager-qa': raise SystemExit('Local synthetic QA checkout required')
fixture=json.loads((root/'qa-report-v030.json').read_text())
assert fixture['version']=='0.3.0'
store=fixture['fixtures']['classification']['store']
base='http://localhost:18087'
client=build_opener(ProxyHandler({}),HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks=[]; logout=None

def request(path,data=None):
    target=path if path.startswith(base+'/') else base+path
    parts=urlsplit(target)
    assert parts.hostname=='localhost' and parts.port==18087
    r=client.open(Request(target,data=urlencode(data).encode() if data else None),timeout=30)
    assert r.status==200
    return r.read().decode()
def check(ok,name):
    assert ok,name
    checks.append(name)

try:
    request('/wp-login.php')
    body=request('/wp-login.php',{'log':'gsm-admin','pwd':(root/'.qa-admin-password').read_text().strip(),'redirect_to':base+'/wp-admin/admin.php?page=muti-storefront-plugin&tab=stores','testcookie':'1'})
    assert 'v0.3.0' in body and 'id="loginform"' not in body
    match=re.search(r'''href=['"]([^'"]*wp-login\.php\?action=logout[^'"]+)['"]''',body)
    assert match; logout=html.unescape(match.group(1))
    check('统一分类' not in body and '店铺分类' not in body,'Retired classification tabs are absent in the authenticated manager')
    check(body.count('class="nav-tab ')==10,'Exactly ten active manager tabs remain')
    for tab in ['overview','stores','collections','catalog','products','orders','customers','reviews','audit','help']:
        body=request(f'/wp-admin/admin.php?page=muti-storefront-plugin&tab={tab}&store={store}')
        check('v0.3.0' in body and 'notice-error' not in body,'Authenticated admin screen: '+tab)
    body=request(f'/wp-admin/admin.php?page=muti-storefront-plugin&tab=collections&store={store}')
    check('本店产品分类（含子分类，任一匹配）' in body and '本店产品标签' in body,'Collection selectors use the current store native taxonomy')
    check(f'/brand-a/wp-admin/edit-tags.php?taxonomy=product_cat' in body,'Collection page links to the correct store native product categories')
    nonce=re.search(r'name="_wpnonce" value="([^"]+)"',body).group(1)
    body=request('/wp-admin/admin-post.php',{'action':'gsm_action','kind':'collection_save','store':store,'return_tab':'collections','_wpnonce':nonce,'name':'QA HTTP Local Rule','mode':'rule','categories[]':fixture['fixtures']['classification']['local_category'],'enabled':'1','sort':'menu_order'})
    check('集合已保存' in body and 'QA HTTP Local Rule' in body,'Native admin-post saves a collection using local classification IDs')
    categories=f'/brand-a/wp-admin/edit-tags.php?taxonomy=product_cat&post_type=product'
    before=request(categories)
    ids=lambda value:set(re.findall(r'id="tag-(\d+)"',value))
    check('Add new category' in before and bool(ids(before)),'Native WooCommerce category management is accessible with existing terms')
    for kind in ['template_save','template_tag','taxonomy_sync','category_save']:
        body=request('/wp-admin/admin-post.php',{'action':'gsm_action','kind':kind,'store':store,'return_tab':'collections','_wpnonce':nonce,'name':'Retired handler must reject'})
        check('未知管理操作' in body,'Retired write handler rejected: '+kind)
    check(ids(request(categories))==ids(before),'Rejected old handlers do not modify native category records')
    for retired in ['templates','categories']:
        body=request(f'/wp-admin/admin.php?page=muti-storefront-plugin&tab={retired}&store={store}')
        check('class="nav-tab nav-tab-active" href="'+base+'/wp-admin/admin.php?page=muti-storefront-plugin&#038;tab=overview"' in body,'Retired URL safely falls back to overview: '+retired)
finally:
    if logout:
        request(logout)
        checks.append('Only the temporary HTTP test session was logged out')
print(json.dumps({'version':'0.3.0','passed':len(checks),'checks':checks},indent=2))
