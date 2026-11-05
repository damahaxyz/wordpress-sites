#!/usr/bin/env python3
"""Unified public/brand product flows on loopback QA; credentials/tokens stay private."""
import copy,html,http.cookiejar,json,re,secrets,socket
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode,urlsplit,parse_qs
from urllib.request import Request,build_opener,HTTPCookieProcessor,ProxyHandler,HTTPRedirectHandler
from urllib.error import HTTPError
root=Path.cwd(); assert root==Path('/private/tmp/group-shop-manager-qa')
assert 'GSM_QA_DOMAIN=www.main.local' in (root/'.qa.env').read_text()
private=root/'.identity-private/fixtures.json'; f=json.loads(private.read_text())
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v053-local'
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
            with self.op.open(Request(url,data=urlencode(data,doseq=True).encode() if data is not None else None),timeout=30) as r: return r.status,r.read().decode()
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
import time
xfile=root/'.identity-private/products-v053.json'; x=json.loads(xfile.read_text())
checks=[]; clients=[]
def check(ok,name):
    assert ok,name; checks.append(name[0] if isinstance(name,tuple) else name)
def client():
    obj=Client(); clients.append(obj); return obj
def reqh(obj,url,data=None,headers=None):
    allowed(url)
    try:
        with obj.op.open(Request(url,data=urlencode(data,doseq=True).encode() if data is not None else None,headers=headers or {}),timeout=45) as r:return r.status,r.read().decode(),dict(r.headers)
    except HTTPError as e:return e.code,e.read().decode(),dict(e.headers)
class Form(HTMLParser):
    def __init__(self,body):
        super().__init__(); self.fields={}; self.select=None; self.option=None; self.choices=[]; self.textarea=None; self.value=''; self.feed(body)
    def add(self,key,value):
        if key.endswith('[]'):self.fields.setdefault(key,[]).append(value)
        else:self.fields[key]=value
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='input' and a.get('name') and 'disabled' not in a and a.get('type') not in ('submit','button','reset','image') and (a.get('type') not in ('checkbox','radio') or 'checked' in a):self.add(a['name'],a.get('value',''))
        if tag=='textarea' and a.get('name'):self.textarea=a['name']; self.value=''
        if tag=='select' and a.get('name') and 'disabled' not in a:self.select=a['name']; self.choices=[]
        if tag=='option' and self.select:self.option=a;self.value=''
    def handle_data(self,data):
        if self.textarea or self.option is not None:self.value+=data
    def handle_endtag(self,tag):
        if tag=='textarea' and self.textarea:self.add(self.textarea,self.value);self.textarea=None;self.value=''
        if tag=='option' and self.option is not None:self.choices.append((self.option.get('value',self.value),'selected' in self.option));self.option=None;self.value=''
        if tag=='select' and self.select:
            if self.choices:self.add(self.select,next((v for v,s in self.choices if s),self.choices[0][0]))
            self.select=None
main='http://www.main.local';a='http://www.branda.local';b='http://www.brandb.local'
def ids(body):return set(map(int,re.findall(r'<tr[^>]+id="post-(\d+)"',body)))
class PostForm(Form):
    def __init__(self,body):self.active=False;super().__init__(body)
    def handle_starttag(self,tag,attrs):
        if tag=='form':
            if dict(attrs).get('id')=='post':self.active=True
            return
        if self.active:super().handle_starttag(tag,attrs)
    def handle_endtag(self,tag):
        if tag=='form':self.active=False;return
        if self.active:super().handle_endtag(tag)
def product_form(body):return PostForm(body).fields
r=client()
try:
    r.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/edit.php?post_type=product&gsm_brand=7')
    r.get(main+'/wp-admin/edit.php?post_type=product&gsm_brand=7')
    page=r.get(main+'/wp-admin/edit.php?'+urlencode({'post_type':'product','s':x['suffix']}))
    check({x['base'],x['other'],x['variable']}.issubset(ids(page)) and not ids(page).intersection({x['a'],x['b'],x['va']}) and '>继承版本</span>' not in page,'Bare Products defaults to public despite a previously selected brand cookie')
    check('gsm-product-kind' not in page and '独立品牌商品' not in page and '全部商品资料' not in page,'Retired type selector and independent-product mode are absent')
    check(Form(page).fields['gsm_brand']=='0' and '商品归属' in page,'The single combined selector defaults to public and retains source/version columns')
    page=r.get(main+'/wp-admin/edit.php?'+urlencode({'post_type':'product','gsm_brand':6,'s':x['suffix']}))
    check(ids(page)=={x['a'],x['va']} and Form(page).fields['gsm_brand']=='6','Selecting Brand A returns only inherited A products')
    page=r.get(main+'/wp-admin/edit.php?'+urlencode({'post_type':'product','gsm_brand':7,'s':x['suffix']}))
    check(ids(page)=={x['b']},'Selecting Brand B excludes public and other brand editions')
    page=r.get(main+'/wp-admin/edit.php?'+urlencode({'post_type':'product','gsm_brand':6,'gsm_base':x['base']}))
    check(ids(page)=={x['a']} and re.search(r'All <span class="count">\(1\)</span>',page),'Native status counts follow the combined brand and optional public-source scope')
    links=[html.unescape(u) for u in re.findall(r'href=[\'\"]([^\'\"]+)[\'\"]',page) if 'post_status=draft' in u]
    check(links and parse_qs(urlsplit(links[0]).query).get('gsm_brand')==['6'],'Status navigation retains the chosen brand')
    page=r.get(main+'/wp-admin/post.php?post='+str(x['b'])+'&action=edit&gsm_brand=7');saved=product_form(page)
    check(not re.search(r'<select[^>]+name="gsm_(?:product_brand|base_product)"',page) and saved['gsm_base_product']==str(x['base']),'Root brand editor shows fixed ownership and public source instead of reassignment dropdowns')
    fields=dict(saved);fields.update({'gsm_brand':'7','gsm_base_product':'0','post_title':'QA053 tampered '+secrets.token_hex(2)+' '+x['suffix']})
    r.req(main+'/wp-admin/post.php',fields)
    stored=product_form(r.get(main+'/wp-admin/post.php?post='+str(x['b'])+'&action=edit&gsm_brand=7'))
    check(stored['gsm_base_product']==str(x['base']) and stored['post_title']==saved['post_title'],'Tampered real native POST cannot detach the source or partly save title')
    stored.update({'gsm_brand':'7','gsm_product_overrides[]':['name','price'],'post_title':'QA053 B override '+x['suffix'],'_regular_price':'73'})
    status,body=r.req(main+'/wp-admin/post.php',stored)
    stored=product_form(r.get(main+'/wp-admin/post.php?post='+str(x['b'])+'&action=edit&gsm_brand=7'))
    check(status==200 and stored['post_title']=='QA053 B override '+x['suffix'] and stored['_regular_price']=='73','Actual native POST still saves enabled brand title and price differences')
    new=r.get(main+'/wp-admin/post-new.php?post_type=product&gsm_brand=6');fields=product_form(new);new_id=int(fields['post_ID']);x['http_public']=new_id
    check(fields['gsm_product_brand']=='0' and '独立商品／未关联' not in new,'Add new while viewing a brand opens a public product without independent ownership controls')
    fields.update({'gsm_brand':'6','post_title':'QA053 native new '+x['suffix'],'_regular_price':'32','post_status':'draft','save':'Save Draft'})
    status,body=r.req(main+'/wp-admin/post.php',fields)
    stored=product_form(r.get(main+'/wp-admin/post.php?post='+str(new_id)+'&action=edit&gsm_brand=0'))
    check(status==200 and stored['gsm_product_brand']=='0' and stored['post_title']=='QA053 native new '+x['suffix'] and stored['post_status']=='draft','Real native creation persists a public draft even in a brand context')
    staff=client();staff.admin(a,f['email'],f['password_staff'],'/wp-admin/edit.php?post_type=product&gsm_brand=6')
    page=staff.get(a+'/wp-admin/edit.php?post_type=product&gsm_brand=6')
    check('value="0">公共商品' not in page and 'post-new.php?post_type=product' not in page,'Employee selector excludes public maintenance and native Add new is hidden')
    status,body=staff.req(a+'/wp-admin/post-new.php?post_type=product&gsm_brand=6')
    check(status==403,'Employee cannot bypass the public-first workflow with direct Add new URL')
    status,body=staff.req(a+'/wp-admin/edit.php?post_type=product&gsm_brand=9')
    check(status==403,'Combined selection cannot grant access to an unauthorized brand')
    page=staff.get(a+'/wp-admin/edit.php?'+urlencode({'post_type':'product','gsm_brand':7,'s':x['suffix']}))
    check(ids(page)=={x['b']},'Staff selector can switch to its separately authorized Brand B')
    legacy=r.get(main+'/wp-admin/admin.php?'+urlencode({'page':'group-shop-manager','tab':'products','store':6,'product':x['a'],'gsm_brand':0}))
    check('品牌商品统一在原生编辑器维护继承与差异' in legacy and 'name="gsm_product_complete"' not in legacy,'Central product tab no longer exposes a standalone editor')
finally:
    xfile=root/'.identity-private/products-v053.json';xfile.write_text(json.dumps(x));xfile.chmod(0o600)
    for obj in clients:
        if obj.logout:obj.req(obj.logout)
        obj.jar.clear()
report={'version':'0.5.3','passed':len(checks),'checks':checks}
(reports/'http-products.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2))
