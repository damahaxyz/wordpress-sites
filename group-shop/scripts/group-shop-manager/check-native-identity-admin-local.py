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
from concurrent.futures import ThreadPoolExecutor
checks=[]
def check(ok,name):
    assert ok,name; checks.append(name)
main='http://www.main.local'; a='http://www.branda.local'
rootc=Client()
try:
    rootc.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/network/users.php')
    body=rootc.get(main+'/wp-admin/network/user-new.php')
    fields=Inputs(body).fields
    alias='employeehttp'+secrets.token_hex(3)
    fields.update({'user[username]':alias,'user[email]':alias+'@example.invalid','add-user':'Add User'})
    action=html.unescape(re.search(r'<form[^>]+action="([^"]+)"',body).group(1))
    if not action.startswith('http'): action=main+'/wp-admin/network/'+action
    status,body=rootc.req(action,fields)
    listing=rootc.get(main+'/wp-admin/network/users.php?'+urlencode({'s':alias}))
    matches=re.findall(r'user-edit.php\?user_id=(\d+)',listing)
    check(status==200 and matches and '员工／按店授权' in listing,'Native network Add User creates a shared employee identity')
    user=int(matches[0]); profile=rootc.get(main+'/wp-admin/network/user-edit.php?user_id='+str(user))
    check('gsm_staff_complete' in profile and '员工店铺授权' in profile,'New native employee profile exposes store grants')
    fields=Inputs(profile).fields
    fields.pop('super_admin',None)
    fields.update({'action':'update','user_id':user,'email':alias+'@example.invalid','nickname':alias,'pass1':'','pass2':''})
    for store in [1,6,7,9,11,12,13]: fields['gsm_staff_roles['+str(store)+']']='shop_manager' if store==6 else ('administrator' if store==7 else '')
    status,body=rootc.req(main+'/wp-admin/network/user-edit.php',fields)
    profile=rootc.get(main+'/wp-admin/network/user-edit.php?user_id='+str(user))
    check(status==200 and re.search(r'name="gsm_staff_roles\[6\]".*?<option value="shop_manager"\s+selected',profile,re.S) and re.search(r'name="gsm_staff_roles\[7\]".*?<option value="administrator"\s+selected',profile,re.S),'Native profile saves A manager and B administrator grants')
    # True simultaneous requests race on one scoped alias/email, never across stores.
    race='race-http-'+secrets.token_hex(3); email=race+'@example.invalid'
    callers=[Client(),Client()]; nonces=[x.modal(a) for x in callers]
    def register(i):
        return callers[i].req(a+'/wp-admin/admin-ajax.php',{'action':'trovesia_account_register','nonce':nonces[i],'email':email,'username':race,'password':secrets.token_urlsafe(20),'website':''})
    with ThreadPoolExecutor(max_workers=2) as pool: results=list(pool.map(register,[0,1]))
    check(sum(status==200 and json.loads(body).get('success',False) for status,body in results)==1 and all(status in [200,409,422] for status,body in results),'Concurrent same-shop registration creates exactly one successful account')
    # No credentials written to report. CLI verifies membership and race count from this fixture manifest.
    (root/'.identity-private/admin-fixtures.json').write_text(json.dumps({'staff':user,'alias':alias,'race':race,'email':email}))
    (root/'.identity-private/admin-fixtures.json').chmod(0o600)
finally:
    if rootc.logout: rootc.req(rootc.logout)
    rootc.jar.clear()
report={'version':'0.4.0','passed':len(checks),'checks':checks}
(reports/'http-native-identity-admin.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
