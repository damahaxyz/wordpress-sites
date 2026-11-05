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

checks=[];clients=[]
def client():
    obj=Client();clients.append(obj);return obj
def check(ok,name):
    assert ok,name;checks.append(name)
a='http://www.branda.local';b='http://www.brandb.local'
try:
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
finally:
    for obj in clients:obj.jar.clear()
report={'version':'0.5.0','passed':len(checks),'checks':checks}
(reports/'http-reset.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2))
