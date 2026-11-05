#!/usr/bin/env python3
"""Retired central product routes on loopback QA; no product writes."""
import copy,html,http.cookiejar,json,re,secrets,socket
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode,urlsplit,parse_qs
from urllib.request import Request,build_opener,HTTPCookieProcessor,ProxyHandler,HTTPRedirectHandler
from urllib.error import HTTPError
root=Path.cwd(); assert root==Path('/private/tmp/group-shop-manager-qa')
assert 'GSM_QA_DOMAIN=www.main.local' in (root/'.qa.env').read_text()
private=root/'.identity-private/fixtures.json'; f=json.loads(private.read_text())
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v054-local'
hosts={'www.main.local','www.branda.local','www.brandb.local','www.brandc.local'}
for host in hosts: assert socket.gethostbyname(host)=='127.0.0.1'
def allowed(url):
    p=urlsplit(url); assert p.scheme=='http' and p.hostname in hosts and p.port in (None,80),'Nonlocal target'
class Redirect(HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):
        allowed(newurl); return super().redirect_request(req,fp,code,msg,headers,newurl)
class Inputs(HTMLParser):
    def __init__(self,body):
        super().__init__(); self.fields={}; self.feed(body)
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='input' and a.get('name') and 'disabled' not in a and a.get('type') not in ('submit','button','reset','image') and (a.get('type') not in ('checkbox','radio') or 'checked' in a): self.fields[a['name']]=a.get('value','')
class Client:
    def __init__(self):
        self.jar=http.cookiejar.CookieJar(); self.op=build_opener(ProxyHandler({}),HTTPCookieProcessor(self.jar),Redirect()); self.logout=None
    def req(self,url,data=None):
        allowed(url)
        try:
            with self.op.open(Request(url,data=urlencode(data,doseq=True).encode() if data is not None else None),timeout=30) as r: self.lasturl=r.url;return r.status,r.read().decode()
        except HTTPError as e: return e.code,e.read().decode()
    def get(self,url):
        status,body=self.req(url); assert status==200,'Unexpected page response'; return body
    def modal(self,base):
        body=self.get(base+'/my-account/')
        m=re.search(r'var trovesiaAccount\s*=\s*(\{.*?\});',body)
        assert m,'Missing native frontend account configuration'
        return json.loads(m.group(1))['nonce']
    def login(self,base,login,password):
        nonce=self.modal(base)
        status,body=self.req(base+'/wp-admin/admin-ajax.php',{'action':'trovesia_account_login','nonce':nonce,'login':login,'password':password})
        return status,json.loads(body)
    def admin(self,base,login,password,path='/wp-admin/'):
        self.get(base+'/wp-login.php')
        status,body=self.req(base+'/wp-login.php',{'log':login,'pwd':password,'redirect_to':base+path,'testcookie':'1'})
        m=re.search(r'href=[\'"]([^\'"]*wp-login\.php\?action=logout[^\'"]*)[\'"]',body)
        if m: self.logout=html.unescape(m.group(1))
        return status,body
main='http://www.main.local';clients=[];checks=[]
def check(ok,name):
    assert ok,name;checks.append(name)
r=Client();clients.append(r)
try:
    r.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/admin.php?page=group-shop-manager&gsm_brand=0')
    page=r.get(main+'/wp-admin/admin.php?page=group-shop-manager&gsm_brand=0')
    tabs=re.findall(r'<a class="nav-tab[^\"]*"[^>]*>(.*?)</a>',page)
    check(tabs==['概览','品牌设置','商品集合','操作日志','文档与限制'],'Manager shows exactly five tabs without the retired product tab')
    check('tab=products' not in html.unescape(page) and any(parse_qs(urlsplit(html.unescape(u)).query).get('post_type')==['product'] and parse_qs(urlsplit(html.unescape(u)).query).get('gsm_brand')==['6'] for u in re.findall(r'href=[\'\"]([^\'\"]+)',page)),'Overview product links go directly to native brand Products')
    page=r.get(main+'/wp-admin/admin.php?page=group-shop-manager&tab=products&store=6')
    check('<h1 class="wp-heading-inline">' in page and 'gsm-brand-filter' in page and re.search(r'<option value="6"[^>]*selected',page),'Old brand-list URL redirects to native Products with its brand selected')
    page=r.get(main+'/wp-admin/admin.php?page=group-shop-manager&tab=products')
    check(re.search(r'<option value="0"[^>]*selected',page),'Old unscoped product URL redirects to the public directory')
    page=r.get(main+'/wp-admin/admin.php?page=group-shop-manager&tab=products&store=6&product=1245')
    check(Inputs(page).fields.get('post_ID')=='1245' and 'gsm_versions_complete' in page,'Old product-editor URL redirects to the correct native editor')
    for query in ['store=999999','store=7&product=1245']:
        status,body=r.req(main+'/wp-admin/admin.php?page=group-shop-manager&tab=products&'+query)
        check(status==404,'Invalid or mismatched legacy product scope is rejected: '+query.split('&')[0])
    dialog=r.get(main+'/wp-admin/admin.php?page=group-shop-manager&tab=stores&gsm_dialog=edit&store=6&gsm_brand=0')
    nonce=Inputs(dialog).fields['_wpnonce']
    for kind in ['edition_save','edition_status']:
        status,body=r.req(main+'/wp-admin/admin-post.php',{'action':'gsm_action','kind':kind,'store':6,'product':999999999,'_wpnonce':nonce,'return_tab':'products','gsm_product_complete':'1','status':'publish','expected_status':'draft','gsm_brand':0})
        check(status==410 and '旧商品操作已停用' in body,'Authenticated legacy '+kind+' POST is retired without processing products')
    status,body=r.req(main+'/wp-admin/admin-post.php',{'action':'gsm_action','kind':'edition_status','_wpnonce':'invalid','gsm_brand':0})
    check(status==403,'Legacy POST still requires a valid native nonce')
    page=r.get(main+'/wp-admin/admin.php?page=group-shop-manager&tab=collections&store=6&gsm_brand=0')
    check('商品集合' in page and 'gsm-collection' in page,'Collection management remains available after removal of product-only modules')
finally:
    for obj in clients:
        if obj.logout:obj.req(obj.logout)
        obj.jar.clear()
report={'version':'0.5.4','passed':len(checks),'checks':checks}
(reports/'http-retired-products.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2))
