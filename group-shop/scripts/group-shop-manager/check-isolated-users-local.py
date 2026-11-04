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
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v040-local'
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
checks=[]; clients=[]
def check(ok,name):
    assert ok,name; checks.append(name)
def client():
    c=Client(); clients.append(c); return c
a='http://www.branda.local'; b='http://www.brandb.local'; c='http://www.brandc.local'; main='http://www.main.local'
try:
    # Independent registration via the existing theme's AJAX modal.
    alias='httpiso-'+secrets.token_hex(3); email=alias+'@example.invalid'
    for base in [a,b]:
        user=client(); nonce=user.modal(base)
        status,body=user.req(base+'/wp-admin/admin-ajax.php',{'action':'trovesia_account_register','nonce':nonce,'email':email,'username':alias,'password':secrets.token_urlsafe(18),'website':''})
        response=json.loads(body)
        check(status==200 and response['success'],'AJAX same-email registration succeeds in '+urlsplit(base).hostname)
    dup=client(); nonce=dup.modal(a)
    status,body=dup.req(a+'/wp-admin/admin-ajax.php',{'action':'trovesia_account_register','nonce':nonce,'email':email,'username':alias,'password':secrets.token_urlsafe(18),'website':''})
    check(status==422 and not json.loads(body)['success'],'Same-shop duplicate registration is rejected')
    cb=client(); status,result=cb.login(b,f['email'],f['password_b'])
    check(status==200 and result['success'],'B frontend same-email independent password succeeds')
    # A key is prepared privately in the owning shop, and must fail if moved to B.
    reset=client(); status,body=reset.req(b+'/my-account/lost-password/?'+urlencode({'key':f['reset_a'],'id':f['a']}))
    check(status==403,'Password-reset link is rejected on another shop domain')
    reset=client(); body=reset.get(a+'/my-account/lost-password/?'+urlencode({'key':f['reset_a'],'id':f['a']}))
    values=Inputs(body).fields
    assert 'reset_login' in values and 'reset_key' in values,('Missing native reset form',sorted(values),bool(re.search('invalid|expired',body,re.I)),[cookie.name for cookie in reset.jar])
    newpass=secrets.token_urlsafe(20)
    values.update({'password_1':newpass,'password_2':newpass,'wc_reset_password':'1','action':'reset_password'})
    status,body=reset.req(a+'/my-account/lost-password/',values)
    newclient=client(); status,result=newclient.login(a,f['email'],newpass)
    check(status==200 and result['success'],'Owning-shop native reset changes the A password')
    f['password_a']=newpass; private.write_text(json.dumps(f)); private.chmod(0o600)
    other=client(); status,result=other.login(b,f['email'],f['password_b'])
    check(status==200 and result['success'],'B password still works after A reset')
    check('woocommerce-MyAccount-navigation' in cb.get(b+'/my-account/'),'B existing session survives A password reset')
    ca=client(); status,result=ca.login(a,f['email'],f['password_a'])
    check(status==200 and result['success'],'A frontend email/password login succeeds')
    bad=client(); status,result=bad.login(b,f['email'],f['password_a'])
    check(status==401 and not result['success'],'A password does not log into B')
    bad=client(); status,result=bad.login(c,f['email'],f['password_a'])
    check(status==401 and not result['success'],'Customer cannot log into an unregistered shop')
    foreign=client()
    for cookie in ca.jar:
        if cookie.name.startswith('wordpress_logged_in_'):
            copied=copy.copy(cookie); copied.domain='www.brandb.local'; copied.domain_specified=False; foreign.jar.set_cookie(copied)
    body=foreign.get(b+'/my-account/')
    check('woocommerce-form-login' in body,'Copied customer cookie does not establish a foreign-shop session')
    detail=ca.get(a+'/my-account/edit-account/')
    check(f['email'] in detail and '顾客账户、密码和默认地址仅用于当前店铺' in detail,'Native account editor shows real email and store-isolation notice')
    address=ca.get(a+'/my-account/edit-address/billing/')
    fields=Inputs(address).fields
    payload={'action':'edit_address','save_address':'1','woocommerce-edit-address-nonce':fields['woocommerce-edit-address-nonce'],'billing_first_name':'QA','billing_last_name':'Customer A','billing_country':'US','billing_address_1':'123 Test Street','billing_city':'HTTP City A','billing_state':'CA','billing_postcode':'90001','billing_email':f['email'],'billing_phone':'5550100'}
    status,body=ca.req(a+'/my-account/edit-address/billing/',payload)
    check(status==200 and 'HTTP City A' in ca.get(a+'/my-account/edit-address/billing/'),'Native Woo billing address saves in A')
    check('HTTP City A' not in cb.get(b+'/my-account/edit-address/billing/'),'A address change does not affect B')
    lost=client(); body=lost.get(a+'/my-account/lost-password/'); values=Inputs(body).fields
    values.update({'user_login':f['email'],'wc_reset_password':'1'})
    status,body=lost.req(a+'/my-account/lost-password/',values)
    check(status==200 and 'Password reset email has been sent' in body,'Native forgot-password accepts this-shop email lookup')
    # Employee backend authentication is a separate entry and obeys per-store grants.
    staff=client(); status,body=staff.admin(a,f['email'],f['password_staff'])
    check(status==200 and 'wp-admin-bar-my-account' in body,'Employee backend login uses shared employee identity in A')
    denied=client(); status,body=denied.admin(c,f['email'],f['password_staff'])
    check('wp-admin-bar-my-account' not in body and 'login_error' in body,'Employee cannot log into an unauthorized shop')
    status,body=staff.req(a+'/wp-admin/user-edit.php?user_id='+str(f['b']))
    check(status==403,'Shop manager cannot edit another shop customer by numeric ID')
    rootc=client(); status,body=rootc.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/network/users.php')
    check('账户类型／范围' in body and '店铺用户名' in body,'Native network users distinguishes customer scope and employee identities')
    profile=rootc.get(main+'/wp-admin/network/user-edit.php?user_id='+str(f['a']))
    check('顾客角色固定为 Customer' in profile and 'gsm_staff_roles' not in profile,'Customer profile locks role and exposes no employee grants')
    fields=Inputs(profile).fields
    fields.pop('super_admin',None)
    fields.update({'action':'update','user_id':f['a'],'email':f['email'],'nickname':f['alias'],'pass1':'','pass2':'','wc_email_verified':'1'})
    status,body=rootc.req(main+'/wp-admin/network/user-edit.php',fields)
    check(status==200 and '此顾客角色固定为 Customer' in body,'Native foreign-shop profile save cannot enable local email-confirmation linking')
    profile=rootc.get(main+'/wp-admin/network/user-edit.php?user_id='+str(f['a']))
    fields=Inputs(profile).fields
    fields.update({'action':'update','user_id':f['a'],'role':'administrator','email':f['email'],'nickname':f['alias']})
    status,body=rootc.req(main+'/wp-admin/network/user-edit.php',fields)
    check(status==403 and '顾客角色固定为 Customer' in body,'Forged native customer-to-administrator update is rejected')
    profile=rootc.get(main+'/wp-admin/network/user-edit.php?user_id='+str(f['staff']))
    check('gsm_staff_roles[6]' in profile and 'gsm_staff_roles[7]' in profile and 'gsm_staff_complete' in profile,'Employee profile exposes explicit per-shop grant controls')
    overview=rootc.get(main+'/wp-admin/admin.php?page=group-shop-manager')
    check('v0.4.0' in overview and '顾客每店独立注册' in overview and '同一邮箱与密码，无需重复注册' not in overview,'Manager describes new identity policy')
finally:
    for item in clients:
        if item.logout: item.req(item.logout)
    # Discard temporary customer cookies; their sessions are isolated test accounts.
    for item in clients: item.jar.clear()
report={'version':'0.4.0','passed':len(checks),'checks':checks}
(reports/'http-isolated-users.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
