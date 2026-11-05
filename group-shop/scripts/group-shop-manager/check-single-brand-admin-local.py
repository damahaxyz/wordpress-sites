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
from concurrent.futures import ThreadPoolExecutor
main='http://www.main.local';a='http://www.branda.local';b='http://www.brandb.local'
rootc=client()
try:
    rootc.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/users.php?gsm_brand=0')
    page=rootc.get(main+'/wp-admin/user-new.php?gsm_brand=0');fields=Form(page).fields
    alias='singleemployee'+secrets.token_hex(3);password=secrets.token_urlsafe(20)
    fields.update({'action':'createuser','user_login':alias,'email':alias+'@example.invalid','pass1':password,'pass2':password,'role':'subscriber','gsm_brand':'0'})
    fields.pop('send_user_notification',None)
    status,body=rootc.req(main+'/wp-admin/user-new.php',fields)
    listing=rootc.get(main+'/wp-admin/users.php?'+urlencode({'s':alias,'gsm_brand':'0'}));ids=re.findall(r'user-edit.php\?user_id=(\d+)',listing)
    check(status==200 and ids and '员工／按店授权' in listing,'Native single-site Add User creates an independent employee')
    uid=int(ids[0]);x['native_staff']=uid
    page=rootc.get(main+'/wp-admin/user-edit.php?user_id='+str(uid)+'&gsm_brand=0');fields=Form(page).fields
    fields.update({'action':'update','user_id':uid,'pass1':'','pass2':'','email':alias+'@example.invalid','nickname':alias,'gsm_brand':'0'})
    for brand in [1,6,7,9,11,12,13]:fields['gsm_staff_roles['+str(brand)+']']='shop_manager' if brand==6 else ('administrator' if brand==7 else '')
    status,body=rootc.req(main+'/wp-admin/user-edit.php',fields)
    check(status==200 and 'gsm_staff_roles[6]' in body,'Native employee profile accepts explicit A/B brand grants')
    employee=client();status,body=employee.admin(a,alias,password)
    check(status==200 and 'wp-admin-bar-my-account' in body,'New native employee can actually log into an authorized brand')
    status,body=employee.req(a+'/wp-admin/admin.php?page=wc-settings')
    check(status==403,'Brand employee cannot change shared mall payment/tax/settings')
    status,body=employee.req(a+'/wp-admin/admin.php?page=wc-reports')
    check(status==403,'Brand employee cannot read global legacy sales reports')
    page=rootc.get(main+'/wp-admin/user-edit.php?user_id='+str(f['a'])+'&gsm_brand=0');fields=Form(page).fields
    fields.update({'action':'update','user_id':f['a'],'role':'administrator','pass1':'','pass2':'','gsm_brand':'0'})
    status,body=rootc.req(main+'/wp-admin/user-edit.php',fields)
    check(status==403,'Native single-site customer role forgery is rejected')
    # Signed brand context makes the central Gutenberg/React backend use the selected brand.
    page=rootc.get(main+'/wp-admin/post.php?post='+str(x['a'])+'&action=edit&gsm_brand=6')
    configs=re.findall(r'const c=(\{[^;]+\});wp\.apiFetch\.use',page)
    config=json.loads(configs[-1]);nonce=re.search(r'createNonceMiddleware\(\s*["\']([^"\']+)',page).group(1)
    headers={'X-WP-Nonce':nonce,'X-GSM-Brand':str(config['brand']),'X-GSM-Brand-Nonce':config['nonce']}
    status,body,h=reqh(rootc,main+'/wp-json/wc/v3/products/'+str(x['a']),headers=headers)
    check(status==200 and json.loads(body)['id']==x['a'],'Central native REST editor reads selected A brand through signed context')
    status,body,h=reqh(rootc,main+'/wp-json/wc/v3/products/'+str(x['b']),headers=headers)
    check(status==404,'Selected A central REST context refuses B product ID')
    bad=dict(headers);bad['X-GSM-Brand']='7';status,body,h=reqh(rootc,main+'/wp-json/wc/v3/products/'+str(x['b']),headers=bad)
    check(status==403,'Tampering with signed REST brand context is rejected')
    # A true simultaneous registration must persist exactly one new local identity.
    race='singlerace'+secrets.token_hex(3);email=race+'@example.invalid';x['race_email']=email
    callers=[client(),client()];nonces=[obj.modal(a) for obj in callers]
    def register(i):return callers[i].req(a+'/wp-admin/admin-ajax.php',{'action':'trovesia_account_register','nonce':nonces[i],'username':race,'email':email,'password':secrets.token_urlsafe(20),'website':''})
    with ThreadPoolExecutor(max_workers=2) as pool:results=list(pool.map(register,[0,1]))
    check(sum(status==200 and json.loads(body).get('success',False) for status,body in results)==1 and all(status in [200,409,422] for status,body in results),'Concurrent same-brand registration has exactly one successful identity')
finally:
    xfile.write_text(json.dumps(x));xfile.chmod(0o600)
    for obj in clients:
        if obj.logout:obj.req(obj.logout)
        obj.jar.clear()
report={'version':'0.5.0','passed':len(checks),'checks':checks}
(reports/'http-native-admin.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2))
