#!/usr/bin/env python3
"""Real identity flows on loopback QA; credentials/tokens stay in the private fixture file."""
import copy,html,http.cookiejar,json,re,secrets,socket
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode,urlsplit,parse_qs
from urllib.request import Request,build_opener,HTTPCookieProcessor,ProxyHandler,HTTPRedirectHandler
from urllib.error import HTTPError
root=Path.cwd(); assert root==Path('/private/tmp/group-shop-manager-qa')
assert 'GSM_QA_DOMAIN=www.main.local' in (root/'.qa.env').read_text()
private=root/'.identity-private/fixtures.json'; f=json.loads(private.read_text())
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v051-local'
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
        if tag=='input' and a.get('name') and 'disabled' not in a and (a.get('type') not in ('checkbox','radio') or 'checked' in a): self.fields[a['name']]=a.get('value','')
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
xfile=root/'.identity-private/native-products-v051.json'; x=json.loads(xfile.read_text())
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
        if tag=='input' and a.get('name') and 'disabled' not in a and (a.get('type') not in ('checkbox','radio') or 'checked' in a):self.add(a['name'],a.get('value',''))
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
    r.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/edit.php?post_type=product&gsm_brand=0')
    page=r.get(main+'/wp-admin/edit.php?'+urlencode({'post_type':'product','gsm_brand':0,'s':x['suffix']}))
    check({x['base'],x['a'],x['b']}.issubset(ids(page)),'All-brand native search includes public and both sale versions')
    check(all(label in page for label in ['所属品牌／类型','公共商品','品牌版本','gsm-brand-filter','gsm-product-kind']),'Native list includes brand/kind filters and source/version columns')
    page=r.get(main+'/wp-admin/edit.php?'+urlencode({'post_type':'product','gsm_brand':6,'s':x['suffix']}))
    check(x['a'] in ids(page) and x['base'] not in ids(page) and x['b'] not in ids(page),'A list filter excludes B and public records')
    page=r.get(main+'/wp-admin/edit.php?'+urlencode({'post_type':'product','gsm_brand':0,'gsm_products':'base','s':x['suffix']}))
    check(x['base'] in ids(page) and x['vbase'] in ids(page) and x['a'] not in ids(page),'Public-only filter returns native bases without brand editions')
    independent=r.get(main+'/wp-admin/edit.php?'+urlencode({'post_type':'product','gsm_brand':6,'gsm_products':'independent','s':x['suffix']}))
    check(ids(independent)=={x['independent']},'Independent filter includes a native standalone A product and excludes linked versions')
    page=r.get(main+'/wp-admin/edit.php?'+urlencode({'post_type':'product','gsm_brand':0,'gsm_base':x['base']}))
    check(ids(page)=={x['a'],x['b']} and '清除关联筛选' in page,'Public family navigation returns exactly two versions with a clear-filter link')
    draft_links=[html.unescape(u) for u in re.findall(r'href=[\'\"]([^\'\"]+)[\'\"]',page) if 'post_status=draft' in u]
    check(draft_links and parse_qs(urlsplit(draft_links[0]).query).get('gsm_base')==[str(x['base'])],'Native status links preserve the selected product family')
    check(re.search(r'All <span class="count">\(2\)</span>',page),'Native status counts follow current family scope')
    check('gsm_brand=6' in html.unescape(page) and 'gsm_brand=7' in html.unescape(page),'Native family edit links carry each target brand context')
    page=r.get(main+'/wp-admin/post.php?post='+str(x['b'])+'&action=edit&gsm_brand=7');fields=product_form(page)
    check(all(k in fields for k in ['gsm_product_nonce','gsm_versions_complete','gsm_base_product','product-type','woocommerce_meta_nonce']),('Native form completeness',sorted(k for k in fields if k.startswith(('gsm','woocommerce','product')))))
    fields.update({'gsm_brand':'7','_regular_price':'999','post_title':'Forged inherited title','content':'Forged inherited content','_weight':'99'})
    fields.pop('gsm_product_overrides[]',None)
    status,body=r.req(main+'/wp-admin/post.php',fields)
    page=r.get(main+'/wp-admin/post.php?post='+str(x['b'])+'&action=edit&gsm_brand=7');saved=product_form(page)
    check(status==200 and saved.get('post_title')=='QA051 public '+x['suffix'] and saved.get('_regular_price')=='35','Actual native POST restores inherited title and price despite submitted stale edits')
    check(saved.get('_weight')=='1.5' and saved.get('content')=='Public description '+x['suffix'],'Actual native POST preserves shared shipping and content')
    fields=saved;fields.update({'gsm_brand':'7','gsm_product_overrides[]':['name','price'],'post_title':'QA051 independent B '+x['suffix'],'_regular_price':'67'})
    status,body=r.req(main+'/wp-admin/post.php',fields);saved=product_form(r.get(main+'/wp-admin/post.php?post='+str(x['b'])+'&action=edit&gsm_brand=7'))
    check(status==200 and saved.get('post_title')=='QA051 independent B '+x['suffix'] and saved.get('_regular_price')=='67',('Native POST persists explicitly enabled B title and price',status,saved.get('post_title'),saved.get('_regular_price'),saved.get('gsm_product_overrides[]')))
    fields=saved;fields.pop('gsm_product_overrides[]',None);fields['gsm_brand']='7';r.req(main+'/wp-admin/post.php',fields)
    saved=product_form(r.get(main+'/wp-admin/post.php?post='+str(x['b'])+'&action=edit&gsm_brand=7'))
    check(saved.get('post_title')=='QA051 public '+x['suffix'] and saved.get('_regular_price')=='35','Native POST restores inheritance when flags are removed')
    page=r.get(main+'/wp-admin/post.php?post='+str(x['va'])+'&action=edit&gsm_brand=6')
    nonce=re.search(r'"add_variation_nonce":"([^"\\]+)"',page).group(1)
    status,body=r.req(main+'/wp-admin/admin-ajax.php',{'action':'woocommerce_add_variation','post_id':x['va'],'security':nonce})
    check(status==403,'Native AJAX cannot add private specs to a linked brand variable product')
    manager=r.get(main+'/wp-admin/admin.php?'+urlencode({'page':'group-shop-manager','tab':'products','store':6,'product':x['a'],'gsm_brand':0}))
    check('继承商品的可编辑差异统一在原生编辑器维护' in manager and 'name="gsm_product_complete"' not in manager,'Legacy inherited editor offers native editing without a second ineffective form')
    staff=client();staff.admin(a,f['email'],f['password_staff'],'/wp-admin/edit.php?post_type=product')
    staff_editor=staff.get(a+'/wp-admin/post.php?post='+str(x['a'])+'&action=edit&gsm_brand=6')
    check(not re.search(r'<select[^>]+name="gsm_(?:product_brand|base_product)"',staff_editor) and 'name="gsm_base_product"' in staff_editor,'Employee editor shows ownership and source as text instead of unusable disabled dropdowns')
    status,body=staff.req(a+'/wp-admin/admin-ajax.php',{'action':'woocommerce_load_variations','product_id':x['vb'],'gsm_brand':6,'security':'invalid'})
    check(status==403 and '编辑权限' in json.loads(body)['data']['message'],'Classic variation AJAX rejects a foreign product before loading fields')
    page=staff.get(a+'/wp-admin/edit.php?'+urlencode({'post_type':'product','gsm_brand':7,'gsm_base':x['base']}))
    check(ids(page)=={x['b']},'Employee explicit B scope still uses only its granted brand query')
    status,body=staff.req(a+'/wp-admin/edit.php?post_type=product&gsm_brand=9')
    check(status==403,'List brand selection cannot grant access to unauthorized C')
finally:
    for obj in clients:
        if obj.logout:obj.req(obj.logout)
        obj.jar.clear()
report={'version':'0.5.1','passed':len(checks),'checks':checks}
(reports/'http-native-products.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2))
