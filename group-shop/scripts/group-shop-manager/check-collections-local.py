#!/usr/bin/env python3
"""Disposable localhost-only acceptance for collection management and native Woo storefronts."""
import html
import http.cookiejar
import json
import re
import secrets
from pathlib import Path
from urllib.parse import urlencode, urlsplit
from urllib.request import Request, build_opener, HTTPCookieProcessor, ProxyHandler
from urllib.error import HTTPError
root=Path.cwd()
if root.name!='group-shop-manager-qa': raise SystemExit('Local synthetic QA directory required')
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v032-local'
f=json.loads((reports/'collections.json').read_text())['fixtures']
base='http://localhost:18087'
client=build_opener(ProxyHandler({}),HTTPCookieProcessor(http.cookiejar.CookieJar()))
anon=build_opener(ProxyHandler({})); checks=[]; logout=None; restore=False

def request(path,data=None,opener=client):
    url=path if path.startswith(base+'/') else base+path
    parts=urlsplit(url); assert parts.hostname=='localhost' and parts.port==18087
    try:
        with opener.open(Request(url,data=urlencode(data,doseq=True).encode() if data is not None else None),timeout=30) as response:
            return response.status,response.read().decode()
    except HTTPError as error: return error.code,error.read().decode()
def get(path,opener=client):
    status,body=request(path,opener=opener); assert status==200,(path,status); return body
def check(ok,name):
    assert ok,name; checks.append(name)
def nonce(body): return re.search(r'name="_wpnonce" value="([^"]+)"',body).group(1)
def post(kind,**data):
    status,body=request('/wp-admin/admin-post.php',{'action':'gsm_action','kind':kind,'store':f['a'],'return_tab':'collections','_wpnonce':token,**data})
    assert status==200,(kind,status); return body
def ids(body): return set(map(int,re.findall(r'<li\s+class="[^"]*\bpost-(\d+)\b[^"]*"',body)))
list_url=f'/wp-admin/admin.php?page=muti-storefront-plugin&tab=collections&store={f["a"]}'
shop='/brand-a/shop/'
try:
    get('/wp-login.php')
    status,body=request('/wp-login.php',{'log':'gsm-admin','pwd':(root/'.qa-admin-password').read_text().strip(),'redirect_to':base+list_url,'testcookie':'1'})
    assert status==200 and 'v0.3.2' in body and 'id="loginform"' not in body
    logout=html.unescape(re.search(r'''href=['"]([^'"]*wp-login\.php\?action=logout[^'"]+)['"]''',body).group(1))
    token=nonce(body)
    check('gsm-collection-table' in body and '创建集合' in body and 'collection_trash' in body,'Authenticated landing page is a collection list with create/edit/delete actions')
    check('name="mode"' not in body and 'name="slug"' not in body,'Landing page does not mix the list with an always-open editor')
    body=get(list_url+'&collection=new')
    check('集合标题' in body and 'gsm_collection_description' in body and 'gsm-image-picker' in body,'Dedicated create screen contains title, description and local media selection')
    check('name="slug"' not in body and 'name="parent"' not in body,'Create screen asks for no URL slug or category hierarchy')
    search_config=json.loads(re.search(r'var gsmCollectionSearch = (.*?);',body).group(1))
    status,data=request('/wp-admin/admin-ajax.php',{'action':'gsm_collection_products','store':f['a'],'nonce':search_config['nonce'],'search':str(f['products']['red'])})
    found=json.loads(data)
    check(status==200 and found['success'] and [item['id'] for item in found['data']]==[f['products']['red']],'Product chooser searches the entire current-store catalog by ID')
    check(all('name' in item and 'image' in item and 'status' in item for item in found['data']),'Product chooser returns visual names, thumbnails and publication status')
    status,data=request('/wp-admin/admin-ajax.php',{'action':'gsm_collection_products','store':f['b'],'nonce':search_config['nonce'],'search':''})
    check(status==403 and not json.loads(data)['success'],'Product chooser nonce cannot be reused for another store')
    status,data=request('/wp-admin/admin-ajax.php',{'action':'gsm_collection_products','store':f['a'],'nonce':search_config['nonce'],'search':''},opener=anon)
    check(status in (400,403) and 'success":true' not in data,'Anonymous visitors cannot query admin product selection')
    name='QA HTTP Homepage '+secrets.token_hex(3)
    body=post('collection_save',collection=0,gsm_collection_complete='1',name=name,description='<p>One collection for homepage and campaigns.</p>',mode='manual',**{'include[]':[f['products']['red'],f['products']['blue']]},image=f['products']['image'],enabled='1',shop_filter='1',sort='menu_order')
    check('集合已保存' in body and name in body,'Real admin-post creates a titled, described and illustrated manual collection')
    created=int(re.search(r'name="collection" value="(\d+)"',body).group(1)); assert created>0
    check('用于首页或专题页面' in body and '[group_shop_collection' in body,'Saved collection provides reusable homepage/topic insertion instructions')
    body=post('collection_save',collection=created,gsm_collection_complete='1',name=name+' Updated',description='Updated description',mode='manual',**{'include[]':[f['products']['blue']]},image=f['products']['image'],enabled='1',shop_filter='1')
    check('集合已保存' in body and name+' Updated' in body,'Real HTTP edits the existing collection in its dedicated page')
    products=json.loads(get('/brand-a/wp-json/wc/store/v1/products?category='+str(created),anon))
    check([p['id'] for p in products]==[f['products']['blue']],'Native public Store API reads the edited collection membership')
    body=post('collection_save',collection=created,gsm_collection_complete='1',name='Retained invalid title',mode='rule',enabled='1')
    check('规则集合至少选择' in body and '上次提交的内容已保留' in body,'Invalid rule submission retains entered fields for correction')
    body=post('collection_save',collection=created,name='Truncated must not save')
    check('集合表单提交不完整' in body,'Incomplete form is rejected before changing collection data')
    # Restore valid content after the intentional error so subsequent UI starts from a saved state.
    body=post('collection_save',collection=created,gsm_collection_complete='1',name=name+' Updated',mode='manual',**{'include[]':[f['products']['blue']]},enabled='1',shop_filter='1')
    body=get(f['topic'],anon)
    check('gsm-collection-module' in body and f['title'] in body and 'Reusable homepage' in body,'Published topic page renders collection title and description')
    check(ids(body)=={f['products']['extra']},'First topic page shows the first manually prioritized public product')
    second=get(f['topic']+'?gsm_page_'+str(f['collection'])+'=2',anon)
    check(ids(second)=={f['products']['red']},'Collection-scoped topic pagination returns the next product')
    body=get(shop+'?gsm_collection='+str(f['collection']),anon)
    check(ids(body)=={f['products']['red'],f['products']['extra']},'Real native Shop filters to exactly the selected collection')
    check('gsm-shop-collection-filter' in body and f['title'] in body,'Native Shop automatically renders opted-in collection choices')
    combined=get(shop+'?'+urlencode({'gsm_collection':f['collection'],'filter_gsm-qa-color':'red','query_type_gsm-qa-color':'and'}),anon)
    check(ids(combined)=={f['products']['red']},'Real Shop intersects collection membership with native Woo attribute filters')
    empty=get(shop+'?'+urlencode({'gsm_collection':f['collection'],'max_price':'1'}),anon)
    check(not ids(empty) and 'gsm-shop-collection-filter' in empty,'Empty filtered Shop still offers controls to change or clear collection selection')
    missing=get(shop+'?gsm_collection=9999999',anon)
    check(not ids(missing),'Invalid collection ID never expands to the entire catalog')
    archive=get(f['archive'],anon)
    check(ids(archive)=={f['products']['red'],f['products']['extra']},'Existing native archive remains compatible with collection contents')
    body=post('collection_trash',collection=f['collection']); restore=True
    check('集合已移入回收站' in body and 'collection_restore' in body,'Real HTTP delete moves a collection into a recoverable trash list')
    check('gsm-collection-module' not in get(f['topic'],anon),'Trashed collection disappears from topic modules')
    check(request(f['archive'],opener=anon)[0]==404,'Trashed native collection archive is no longer public')
    check(not ids(get(shop+'?gsm_collection='+str(f['collection']),anon)),'Trashed collection cannot bypass Shop filters')
    body=post('collection_restore',collection=f['collection']); restore=False
    check('集合已恢复' in body and 'gsm-collection-module' in get(f['topic'],anon),'Restore rebuilds public module membership')
    body=post('collection_trash',collection=created)
    check('集合已移入回收站' in body and not json.loads(get('/brand-a/wp-json/wc/store/v1/products?category='+str(created),anon)),'Deleting the HTTP fixture clears only its derived members')
finally:
    if restore:
        post('collection_restore',collection=f['collection'])
    if logout: request(logout); checks.append('Temporary HTTP acceptance session logged out')
print(json.dumps({'version':'0.3.2','passed':len(checks),'checks':checks,'fixtures':{'created':created}},indent=2))
