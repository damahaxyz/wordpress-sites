#!/usr/bin/env python3
"""HTTP acceptance for disposable localhost:18087 QA; never use production credentials."""
import html
import http.cookiejar
import json
import re
from pathlib import Path
from urllib.parse import urlencode, urlsplit
from urllib.request import build_opener, HTTPCookieProcessor, ProxyHandler, Request
from urllib.error import HTTPError
root=Path.cwd()
if root.name!='group-shop-manager-qa': raise SystemExit('Local synthetic QA directory required')
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v032-local'
product_report=json.loads((reports/'product-editor.json').read_text())
version=product_report['version']
f=product_report['fixtures']
shared=json.loads((reports/'shared-taxonomies.json').read_text())['fixtures']
base='http://localhost:18087'
client=build_opener(ProxyHandler({}),HTTPCookieProcessor(http.cookiejar.CookieJar()))
anon=build_opener(ProxyHandler({}))
checks=[]; logout=None

def req(path,data=None,opener=client):
    url=path if path.startswith(base+'/') else base+path
    parts=urlsplit(url)
    assert parts.hostname=='localhost' and parts.port==18087
    try:
        with opener.open(Request(url,data=urlencode(data).encode() if data is not None else None),timeout=30) as response:
            return response.status,response.read().decode()
    except HTTPError as error:
        return error.code,error.read().decode()
def get(path):
    status,body=req(path); assert status==200,(path,status); return body
def check(ok,name):
    assert ok,name; checks.append(name)
def nonce(body): return re.search(r'name="_wpnonce" value="([^"]+)"',body).group(1)
def post(kind,**fields):
    fields={'action':'gsm_action','kind':kind,'return_tab':'products','store':f['a'],'_wpnonce':token,**fields}
    status,body=req('/wp-admin/admin-post.php',fields); assert status==200,(kind,status); return body
def public(store_path,product): return req(store_path+'/wp-json/wc/store/v1/products/'+str(product),opener=anon)[0]
list_url=f'/wp-admin/admin.php?page=muti-storefront-plugin&tab=products&store={f["a"]}'
try:
    get('/wp-login.php')
    status,body=req('/wp-login.php',{'log':'gsm-admin','pwd':(root/'.qa-admin-password').read_text().strip(),'redirect_to':base+list_url,'testcookie':'1'})
    assert status==200 and ('v'+version) in body and 'id="loginform"' not in body
    logout=html.unescape(re.search(r'''href=['"]([^'"]*wp-login\.php\?action=logout[^'"]+)['"]''',body).group(1))
    token=nonce(body)
    check('gsm-product-thumb' in body and '<th>库存</th>' not in body,'Real list includes thumbnails and no stock column')
    check('edition_status' in body and '禁用商品' in body and '上架商品' in body,'Real list has quick native status forms')
    body=post('edition_status',product=f['simple'],expected_status='publish',status='draft')
    check('已禁用（设为草稿）' in body,'HTTP quick disable succeeds')
    check(public('/brand-a',f['simple'])==404,'Disabled product is hidden from public Store API')
    check(public('/brand-b',f['sibling'])==200,'Sibling edition remains public after disable')
    check(public('',f['master'])==200,'Main original remains public after disable')
    body=post('edition_status',product=f['simple'],expected_status='publish',status='publish')
    check('商品状态已变化' in body,'Stale HTTP action is rejected')
    body=post('edition_status',product=f['simple'],expected_status='draft',status='publish')
    check('已上架，仅影响当前店铺' in body and public('/brand-a',f['simple'])==200,'HTTP enable restores public visibility')
    edit_url=list_url+'&product='+str(f['simple'])
    body=get(edit_url)
    check('wp-gsm_description-wrap' in body and 'gsmMedia' in body and 'gsm_product_complete' in body,'Real editor loads native rich text and authorized store media')
    check('name="category_ids' not in body and 'name="tag_ids' not in body,'Real editor has no classification controls')
    body=post('edition_save',product=f['simple'],name='HTTP must not save without complete marker')
    check('表单提交不完整' in body and 'value="HTTP must not save' not in body,'Truncated form is rejected before writing')
    body=post('edition_save',product=f['simple'],gsm_product_complete='1',description='<h2>HTTP rich text</h2><p><strong>Formatted</strong></p>',image_ids=f['image'])
    check('操作已保存' in body and 'HTTP rich text' in body and 'Formatted' in body,'Native admin-post persists rich HTML and returns to editor')
    body=post('edition_save',product=f['simple'],gsm_product_complete='1',regular_price='9',sale_price='99')
    check('促销价必须低于普通售价' in body and '已保留上次提交' in body and 'value="99"' in body,'Invalid save retains entered form values for correction')
    body=post('edition_save',product=f['simple'],gsm_product_complete='1',regular_price='30',sale_price='')
    check('操作已保存' in body and '已保留上次提交' not in body,'Corrected save clears the retained error form')
    child=f['variations'][0]
    body=post('edition_save',product=f['variable'],gsm_product_complete='1',**{f'variations[{child}][regular_price]':'55',f'variations[{child}][sale_price]':'35',f'variations[{child}][status]':'publish'})
    check('操作已保存' in body and 'value="55"' in body,'Real HTTP saves variation rows with the parent')
    media_nonce=re.search(r'"nonce":"([^"]+)"',re.search(r'var gsmMedia = (.*?);',body).group(1)).group(1)
    status,data=req('/wp-admin/admin-ajax.php',{'action':'query-attachments','gsm_media_store':f['a'],'gsm_media_nonce':media_nonce,'query[type]':'image','query[posts_per_page]':'40'})
    attachments=json.loads(data)
    check(status==200 and attachments['success'] and any(int(item['id'])==f['image'] for item in attachments['data']),'Native AJAX queries the target store media without early context switching')
    check(all('/sites/'+str(f['a'])+'/' in item['url'] for item in attachments['data']),'Target media query excludes main and sibling attachments')
    send_nonce=re.search(r'"sendToEditor":"([^"]+)"',body).group(1)
    status,data=req('/wp-admin/admin-ajax.php',{'action':'send-attachment-to-editor','gsm_media_store':f['a'],'gsm_media_nonce':media_nonce,'nonce':send_nonce,'attachment[id]':f['image'],'attachment[image-size]':'full','post_id':'0'})
    check(status==200 and json.loads(data)['success'] and '/sites/'+str(f['a'])+'/' in json.loads(data)['data'],'Native rich-text image insertion resolves the attachment in the selected store')
    status,data=req('/wp-admin/admin-ajax.php',{'action':'query-attachments','gsm_media_store':f['a'],'gsm_media_nonce':'invalid'})
    check(status==403 and not json.loads(data)['success'],'Invalid target-media authorization rejects the request')
    body=get('/brand-a/wp-admin/edit-tags.php?taxonomy=product_tag&post_type=product')
    check('Add new tag' in body and '/brand-a/wp-admin/edit-tags.php?taxonomy=product_tag' not in re.search(r'<form[^>]*id="addtag".*?</form>',body,re.S).group(0),'Child taxonomy management redirects to the main native authority')
    body=get('/wp-admin/edit-tags.php?taxonomy=product_tag&post_type=product')
    tag_nonce=re.search(r'name="_wpnonce_add-tag"[^>]*value="([^"]+)"',body).group(1)
    status,data=req('/wp-admin/admin-ajax.php',{'action':'add-tag','taxonomy':'product_tag','tag-name':'QA HTTP Shared Tag','slug':'qa-http-shared-tag-'+__import__('secrets').token_hex(3),'_wpnonce_add-tag':tag_nonce})
    check(status==200 and 'QA HTTP Shared Tag' in data,'Native main-site AJAX can create a shared product tag')
    body=get(f'/wp-admin/admin.php?page=muti-storefront-plugin&tab=collections&store={f["b"]}&collection=new')
    check('QA HTTP Shared Tag' in body,'A native taxonomy AJAX creation appears in sibling-store collection choices')
    body=get('/wp-admin/term.php?taxonomy=product_cat&tag_ID='+str(shared['category'])+'&post_type=product')
    edit_nonce=re.search(r'name="_wpnonce" value="([^"]+)"',body).group(1)
    status,body=req('/wp-admin/edit-tags.php',{'action':'editedtag','taxonomy':'product_cat','tag_ID':shared['category'],'post_type':'product','_wpnonce':edit_nonce,'name':'QA HTTP Global Category','slug':'qa-http-global-category','description':'Shared native HTTP edit','parent':'0'})
    check(status==200,'Native category edit submits successfully')
    body=get(f'/wp-admin/admin.php?page=muti-storefront-plugin&tab=collections&store={f["b"]}&collection=new')
    check('QA HTTP Global Category' in body,'Native category editing propagates to sibling-store choices')
finally:
    if logout:
        req(logout); checks.append('Temporary HTTP acceptance session logged out')
print(json.dumps({'version':version,'passed':len(checks),'checks':checks},indent=2))
