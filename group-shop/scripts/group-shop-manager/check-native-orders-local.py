#!/usr/bin/env python3
"""Native order acceptance for disposable localhost:18087 QA only."""
import html
import http.cookiejar
import json
import re
from pathlib import Path
from urllib.parse import urlencode, urlsplit
from urllib.request import Request, build_opener, HTTPCookieProcessor, ProxyHandler
from urllib.error import HTTPError
root=Path.cwd()
if root.name!='group-shop-manager-qa': raise SystemExit('Local synthetic QA directory required')
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v034-local'
f=json.loads((reports/'native-orders.json').read_text())['fixtures']
base='http://localhost:18087'
client=build_opener(ProxyHandler({}),HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks=[]; logout=None

def req(path,data=None):
    url=path if path.startswith(base+'/') else base+path
    parts=urlsplit(url)
    assert parts.hostname=='localhost' and parts.port==18087
    try:
        with client.open(Request(url,data=urlencode(data).encode() if data is not None else None),timeout=30) as response:
            return response.status,response.read().decode()
    except HTTPError as error: return error.code,error.read().decode()
def get(path):
    status,body=req(path); assert status==200,(path,status); return body
def check(ok,name):
    assert ok,name; checks.append(name)

try:
    get('/wp-login.php')
    status,body=req('/wp-login.php',{'log':'gsm-admin','pwd':(root/'.qa-admin-password').read_text().strip(),'redirect_to':base+'/wp-admin/admin.php?page=group-shop-manager','testcookie':'1'})
    assert status==200 and 'v0.3.4' in body and 'id="loginform"' not in body
    logout=html.unescape(re.search(r'''href=['"]([^'"]*wp-login\.php\?action=logout[^'"]+)['"]''',body).group(1))
    check('统一订单' not in body and 'tab=orders' not in body,'Authenticated manager no longer exposes central order UI')
    check(body.count('class="nav-tab ')==9,'Nine current manager tabs remain')
    for store,fixture in f.items():
        check(html.escape(fixture['url'],quote=True) in body or fixture['url'] in html.unescape(body),'Overview contains native order entry: '+store)
    for store,fixture in f.items():
        listing=get(fixture['url'])
        order=fixture['id']; path=fixture['path']
        check('id="loginform"' not in listing and ('id="order-'+str(order)+'"' in listing or 'id="post-'+str(order)+'"' in listing),'Native order list loads synthetic order in correct store: '+store)
        if fixture['hpos']:
            check('店铺来源' in listing,'Native HPOS store origin column remains visible')
            edit=path+'wp-admin/admin.php?page=wc-orders&action=edit&id='+str(order)
        else:
            edit=path+'wp-admin/post.php?post='+str(order)+'&action=edit'
        detail=get(edit)
        check('name="order_status"' in detail and 'woocommerce-order-items' in detail and 'QA native order internal note' in detail,'Native detail exposes status, line items and internal notes: '+store)
        nonce=re.search(r'"add_order_note_nonce"\s*:\s*"([^"]+)"',detail).group(1)
        status,note=req(path+'wp-admin/admin-ajax.php',{'action':'woocommerce_add_order_note','post_id':order,'note':'QA HTTP native internal note','note_type':'','security':nonce})
        check(status==200 and 'QA HTTP native internal note' in note,'Native AJAX saves internal note in original store: '+store)
        detail=get(edit)
        check('QA HTTP native internal note' in detail,'Native note persists on reload: '+store)
        form=get('/wp-admin/admin.php?page=group-shop-manager&tab=collections&store='+store+'&collection=new')
        token=re.search(r'name="_wpnonce" value="([^"]+)"',form).group(1)
        for kind in ['order_note','order_status']:
            status,result=req('/wp-admin/admin-post.php',{'action':'gsm_action','kind':kind,'store':store,'order':order,'return_tab':'orders','_wpnonce':token,'status':'cancelled','expected':'on-hold','note':'Retired handler must not write'})
            check(status==200 and '未知管理操作' in result,'Retired central handler is rejected: '+kind+' store '+store)
        after=get(edit)
        check('Retired handler must not write' not in after and re.search(r'<option[^>]*value="wc-on-hold"[^>]*selected',after),'Rejected old handlers preserve notes and native status: '+store)
    old=get('/wp-admin/admin.php?page=group-shop-manager&tab=orders&store=6&order='+str(f['6']['id']))
    check('<h2>店铺入口</h2>' in old and 'name="kind" value="order_' not in old,'Old central order bookmark falls back to overview')
finally:
    if logout:
        get(logout); checks.append('Only temporary acceptance session was logged out')
report=json.dumps({'version':'0.3.4','passed':len(checks),'checks':checks},indent=2)+'\n'
# Preserve the prior successful evidence if a later run fails before completion.
(reports/'http-native-orders.json').write_text(report)
print(report,end='')
