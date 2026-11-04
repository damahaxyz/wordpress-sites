#!/usr/bin/env python3
"""Retired publisher acceptance, limited to the existing loopback .local QA."""
import html
import http.cookiejar
import json
import re
import socket
from pathlib import Path
from urllib.parse import urlencode,urlsplit
from urllib.request import Request,build_opener,HTTPCookieProcessor,ProxyHandler,HTTPRedirectHandler
from urllib.error import HTTPError
root=Path.cwd()
assert root==Path('/private/tmp/group-shop-manager-qa') and 'GSM_QA_DOMAIN=www.main.local' in (root/'.qa.env').read_text()
assert socket.gethostbyname('www.main.local')=='127.0.0.1'
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v037-local'
f=json.loads((reports/'retired-publisher.json').read_text())['fixtures']
base='http://www.main.local'
def allowed(url):
    p=urlsplit(url)
    assert p.scheme=='http' and p.hostname=='www.main.local' and p.port in (None,80),'Unexpected target or redirect'
class LocalRedirect(HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):
        allowed(newurl)
        return super().redirect_request(req,fp,code,msg,headers,newurl)
client=build_opener(ProxyHandler({}),HTTPCookieProcessor(http.cookiejar.CookieJar()),LocalRedirect())
checks=[]; logout=None
def req(path,data=None):
    url=path if path.startswith(base+'/') else base+path
    allowed(url)
    try:
        with client.open(Request(url,data=urlencode(data,doseq=True).encode() if data is not None else None),timeout=30) as response:
            return response.status,response.read().decode()
    except HTTPError as error: return error.code,error.read().decode()
def get(path):
    status,body=req(path); assert status==200
    return body
def check(ok,name):
    assert ok,name
    checks.append(name)
try:
    get('/wp-login.php')
    status,body=req('/wp-login.php',{'log':'gsm-admin','pwd':(root/'.qa-admin-password').read_text().strip(),'redirect_to':base+'/wp-admin/admin.php?page=group-shop-manager','testcookie':'1'})
    assert status==200 and 'v0.3.7' in body and 'id="loginform"' not in body
    logout=html.unescape(re.search(r'href=[\'"]([^\'"]*wp-login\.php\?action=logout[^\'"]*)[\'"]',body).group(1))
    check(body.count('class="nav-tab ')==6,'Manager renders six tabs')
    check('主商品发布' not in body and 'tab=catalog' not in body and 'catalog_publish' not in body,'Manager exposes no cross-store publication UI')
    old=get('/wp-admin/admin.php?page=group-shop-manager&tab=catalog&store='+str(f['target_store']))
    check('<h2>店铺入口</h2>' in old and 'catalog_publish' not in old,'Old publisher bookmark falls back to overview')
    editor='/wp-admin/admin.php?page=group-shop-manager&tab=products&store='+str(f['target_store'])+'&product='+str(f['edit_product'])
    body=get(editor)
    token=re.search(r'name="_wpnonce" value="([^"]+)"',body).group(1)
    check('wp-gsm_description-wrap' in body and 'wp-gsm_short_description-wrap' in body and 'gsm_product_complete' in body,'Local product editor keeps rich text and completeness protection')
    check('gsm-image-picker' in body and 'var gsmMedia' in body,'Local product editor keeps native media controls')
    status,body=req('/wp-admin/admin-post.php',{'action':'gsm_action','kind':'catalog_publish','store':f['target_store'],'product':f['main_product'],'fields[]':['name','description','short_description','images','prices'],'return_tab':'catalog','_wpnonce':token})
    check(status==200 and '未知管理操作' in body,'Valid-nonce retired publish POST is rejected')
    check('<h2>店铺入口</h2>' in body and '商品版本 #' not in body,'Retired return tab falls back without publication success')
    body=get(editor)
    media=json.loads(re.search(r'var gsmMedia\s*=\s*(\{.*?\});',body).group(1))
    status,data=req('/wp-admin/admin-ajax.php',{'action':'query-attachments','gsm_media_store':media['store'],'gsm_media_nonce':media['nonce'],'query[type]':'image','query[posts_per_page]':'5'})
    result=json.loads(data)
    check(status==200 and result['success'] and bool(result['data']),'Native target-store media query still works after copy/import removal')
    check(all(urlsplit(item['url']).hostname=='www.branda.local' and '/sites/6/' in item['url'] for item in result['data']),'Native media query keeps target-store ownership')
    for tab in ['overview','stores','collections','products','audit','help']:
        body=get('/wp-admin/admin.php?page=group-shop-manager&tab='+tab+'&store='+str(f['target_store']))
        check('v0.3.7' in body and 'notice-error' not in body,'Remaining manager tab renders: '+tab)
    check('主商品跨店复制或字段覆盖' in body,'Help describes retired cross-store publication')
finally:
    if logout:
        get(logout); checks.append('Only temporary acceptance session was logged out')
report={'version':'0.3.7','passed':len(checks),'checks':checks}
(reports/'http-retired-publisher.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
