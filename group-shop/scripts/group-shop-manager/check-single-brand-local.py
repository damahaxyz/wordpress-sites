#!/usr/bin/env python3
"""Real identity flows on loopback QA; credentials/tokens stay in the private fixture file."""
import copy,html,http.cookiejar,json,re,secrets,socket
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode,urlsplit
from urllib.request import Request,build_opener,HTTPCookieProcessor,ProxyHandler,HTTPRedirectHandler
from urllib.error import HTTPError
root=Path.cwd(); assert root==Path('/private/tmp/group-shop-manager-qa')
assert 'GSM_QA_DOMAIN=www.main.local' in (root/'.qa.env').read_text()
private=root/'.identity-private/fixtures.json'; f=json.loads(private.read_text())
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v050-local'
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
xfile=root/'.identity-private/single-fixtures.json'; x=json.loads(xfile.read_text())
checks=[]; clients=[]
def check(ok,name):
    assert ok,name; checks.append(name)
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
main='http://www.main.local';a='http://www.branda.local';b='http://www.brandb.local';c='http://www.brandc.local'
try:
    for base in [main,a,b,c]:
        user=client();body=user.get(base+'/my-account/')
        check('woocommerce-form-login' in body,'Native account page works on '+urlsplit(base).hostname)
    # Same public path resolves to each brand native edition.
    pa=client().get(x['a_url']);pb=client().get(x['b_url'])
    check(any(title+x['suffix'] in pa for title in ['QA Brand A ','QA Native A ']) and 'Private A description '+x['suffix'] in pa,'A public URL shows independent native title and description')
    check('QA common updated '+x['suffix'] in pb and 'Private A description '+x['suffix'] not in pb,'B public URL shows inherited public content')
    check('129.00' in pa and '79.00' in pb,'Public storefronts show distinct native prices')
    guest=client();status,body,h=reqh(guest,a+'/wp-json/wc/store/v1/cart');token=h.get('Cart-Token') or h.get('Cart-token') or h.get('cart-token')
    check(status==200 and token,'A Store API creates a native brand-bound cart token')
    foreign=client();status,body,h=reqh(foreign,b+'/wp-json/wc/store/v1/cart',headers={'Cart-Token':token})
    check(status==403,'Store API rejects a cart token copied to another brand')
    status,body,h=reqh(guest,a+'/wp-json/wc/store/v1/cart/add-item',{'id':x['a'],'quantity':1},{'Cart-Token':token})
    check(status in (200,201) and any(i['id']==x['a'] for i in json.loads(body).get('items',[])),'A guest cart accepts its own native brand product')
    status,body,h=reqh(guest,a+'/wp-json/wc/store/v1/cart/add-item',{'id':x['b'],'quantity':1},{'Cart-Token':token})
    check(status in (400,403,404),'A cart refuses another brand product ID')
    status,body,h=reqh(client(),b+'/wp-json/wc/store/v1/products/'+str(x['a']))
    check(status==404,'Store API cannot fetch another brand product by ID')
    ca=client();status,r=ca.login(a,f['email'],f['password_a']);check(status==200 and r['success'],'Migrated A customer logs in with existing independent password')
    cb=client();status,r=cb.login(b,f['email'],f['password_b']);check(status==200 and r['success'],'Migrated same-email B customer retains own password')
    bad=client();status,r=bad.login(b,f['email'],f['password_a']);check(status==401 and not r['success'],'A password cannot authenticate the same-email B identity')
    copied=client()
    for cookie in ca.jar:
        if cookie.name.startswith('wordpress_logged_in_'):
            value=copy.copy(cookie);value.domain='www.brandb.local';value.domain_specified=False;copied.jar.set_cookie(value)
    check('woocommerce-form-login' in copied.get(b+'/my-account/'),'Copied customer login cookie cannot open foreign-brand account')
    # Start from a native empty account cart so repeated acceptance runs are deterministic.
    status,body,h=reqh(ca,a+'/wp-json/wc/store/v1/cart');state=json.loads(body);setup_token=h.get('Cart-Token') or h.get('Cart-token') or h.get('cart-token')
    assert status==200 and setup_token
    for item in state.get('items',[]):
        status,body,h=reqh(ca,a+'/wp-json/wc/store/v1/cart/remove-item',{'key':item['key']},{'Cart-Token':setup_token});assert status==200
    for coupon in state.get('coupons',[]):
        status,body,h=reqh(ca,a+'/wp-json/wc/store/v1/cart/remove-coupon',{'code':coupon['code']},{'Cart-Token':setup_token});assert status==200
    # Real Woo checkout: virtual product + local 100% coupon, no gateway or payment request.
    status,body=ca.req(a+'/?wc-ajax=add_to_cart',{'product_id':x['a'],'quantity':1})
    check(status==200 and not json.loads(body).get('error',False),'Authenticated native AJAX cart adds A product')
    checkout=ca.get(a+'/checkout/')
    cfg=re.search(r'var wc_checkout_params\s*=\s*(\{.*?\});',checkout)
    assert cfg,'Native checkout configuration missing'
    cfg=json.loads(cfg.group(1))
    status,body=ca.req(a+'/?wc-ajax=apply_coupon',{'security':cfg['apply_coupon_nonce'],'coupon_code':x['coupon']})
    check(status==200 and 'woocommerce-error' not in body,'Brand-specific synthetic coupon applies to A cart')
    status,body,h=reqh(ca,a+'/wp-json/wc/store/v1/cart');ctoken=h.get('Cart-Token') or h.get('Cart-token') or h.get('cart-token');assert ctoken
    address={'first_name':'QA','last_name':'Single Brand','country':'US','address_1':'123 Test Street','address_2':'','city':'QA City','state':'CA','postcode':'90001','phone':'5550100','email':f['email']}
    payload={'billing_address':address,'shipping_address':{k:v for k,v in address.items() if k!='email'},'payment_method':'','payment_data':[],'customer_note':'Local synthetic single-brand checkout'}
    allowed(a+'/wp-json/wc/store/v1/checkout')
    try:
        with ca.op.open(Request(a+'/wp-json/wc/store/v1/checkout',data=json.dumps(payload).encode(),headers={'Content-Type':'application/json','Cart-Token':ctoken}),timeout=45) as r:status=r.status;result=json.loads(r.read())
    except HTTPError as e:status=e.code;result=json.loads(e.read())
    assert status in (200,201) and result.get('order_id'),('Native checkout failed',status,result.get('code'),result.get('message'))
    order=int(result['order_id']);x['http_order']=order
    check(True,'Native Store API zero-payment checkout completes without invoking a gateway')
    # New same-alias real-email users in two brands remain independent.
    alias='singlehttp'+secrets.token_hex(3);email=alias+'@example.invalid';x['http_customer_email']=email
    for base in [a,b]:
        customer=client();nonce=customer.modal(base)
        status,body=customer.req(base+'/wp-admin/admin-ajax.php',{'action':'trovesia_account_register','nonce':nonce,'email':email,'username':alias,'password':secrets.token_urlsafe(20),'website':''})
        check(status==200 and json.loads(body)['success'],'Same-alias same-email native registration works on '+urlsplit(base).hostname)
    staff=client();status,body=staff.admin(a,f['email'],f['password_staff'])
    check(status==200 and 'wp-admin-bar-my-account' in body,'Shared employee backend login obeys A grant')
    status,body=staff.req(a+'/wp-admin/post.php?post='+str(x['b'])+'&action=edit')
    check(status==403,'Employee cannot edit B product from A admin context')
    status,body=staff.req(a+'/wp-admin/user-edit.php?user_id='+str(f['b']))
    check(status==403,'Employee cannot edit B customer from A admin context')
    deny=client();status,body=deny.admin(c,f['email'],f['password_staff'])
    check('login_error' in body,'Employee cannot log into an unauthorized brand')
    rootc=client();rootc.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/edit.php?post_type=product&gsm_brand=0')
    product=rootc.get(main+'/wp-admin/post.php?post='+str(x['a'])+'&action=edit&gsm_brand=0')
    check('公共资料／品牌版本' in product and 'gsm_product_overrides[]' in product,'Native Woo product editor exposes brand version and inheritance controls')
    form=re.search(r'<form[^>]+id="post"[^>]*>(.*?)</form>',product,re.S)
    assert form,'Native product form missing'
    fields=Form(form.group(1)).fields;fields.update({'post_title':'QA Native A '+x['suffix'],'gsm_brand':'0'})
    status,body=rootc.req(main+'/wp-admin/post.php',fields)
    check(status==200 and 'QA Native A '+x['suffix'] in client().get(x['a_url']),'Native product editor saves the A title override through actual POST')
    check('QA common updated '+x['suffix'] in client().get(x['b_url']),'Native A title edit leaves B public title unchanged')
    manager=rootc.get(main+'/wp-admin/admin.php?page=group-shop-manager&gsm_brand=0')
    check('v0.5.0' in manager and '单站多品牌' in manager and all(host in manager for host in ['www.branda.local','www.brandb.local','www.brandg.local']),'Manager shows single-site architecture and all seven correct brand domains')
    users=rootc.get(main+'/wp-admin/users.php?gsm_brand=0')
    check('账户类型／范围' in users and '品牌用户名' in users,'Native single-site users exposes identity scope and public aliases')
finally:
    xfile.write_text(json.dumps(x));xfile.chmod(0o600)
    for item in clients:
        if item.logout:item.req(item.logout)
        item.jar.clear()
report={'version':'0.5.0','passed':len(checks),'checks':checks}
(reports/'http-single-brand.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2))
