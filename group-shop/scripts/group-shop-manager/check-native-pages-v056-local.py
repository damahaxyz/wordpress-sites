#!/usr/bin/env python3
"""Independent native pages per store, on loopback QA only."""
import copy,html,http.cookiejar,json,re,secrets,socket
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode,urlsplit,parse_qs
from urllib.request import Request,build_opener,HTTPCookieProcessor,ProxyHandler,HTTPRedirectHandler
from urllib.error import HTTPError
root=Path.cwd(); assert root==Path('/private/tmp/group-shop-manager-qa')
assert 'GSM_QA_DOMAIN=www.main.local' in (root/'.qa.env').read_text()
private=root/'.identity-private/fixtures.json'; f=json.loads(private.read_text())
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v056-local'
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
xpath=root/'.identity-private/native-pages-v056.json';x=json.loads(xpath.read_text());checks=[];clients=[]
def check(ok,name):
    assert ok,name
    checks.append(name)
def client():
    obj=Client();clients.append(obj);return obj
def ids(body):return set(map(int,re.findall(r'<tr[^>]+id="post-(\d+)"',body)))
def listing(brand=None,**args):
    return main+'/wp-admin/edit.php?'+urlencode({'post_type':'page',**({'gsm_brand':brand} if brand is not None else {}),**args})
def api_headers(obj,id,brand,base=main):
    page=obj.get(base+'/wp-admin/post.php?post='+str(id)+'&action=edit&gsm_brand='+str(brand))
    config=json.loads(re.findall(r'const c=(\{[^;]+\});wp\.apiFetch\.use',page)[-1])
    nonce=re.search(r'createNonceMiddleware\(\s*["\']([^"\']+)',page).group(1)
    return {'X-WP-Nonce':nonce,'X-GSM-Brand':str(config['brand']),'X-GSM-Brand-Nonce':config['nonce']},page
def rest(obj,path,headers,data=None,base=main):
    url=base+'/wp-json'+path;allowed(url);req=Request(url,data=json.dumps(data).encode() if data is not None else None,headers={**headers,'Content-Type':'application/json'})
    try:
        with obj.op.open(req,timeout=30) as response:return response.status,json.loads(response.read().decode())
    except HTTPError as e:return e.code,json.loads(e.read().decode())
r=client()
try:
    r.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/edit.php?post_type=page&gsm_brand=7')
    page=r.get(listing(s=x['suffix']))
    check(ids(page)=={x['main']} and re.search(r'<option value="1"[^>]*selected',page) is not None,'Bare Pages defaults to the main store rather than the previous brand cookie')
    check('公共页面模板' not in page and 'page=gsm-page-sync' not in page and 'page=gsm-page-bindings' not in page,'Native Pages has no public-template selector, synchronization page or new purpose-binding layer')
    page=r.get(listing(6,s=x['suffix']))
    check(ids(page)=={x['a'],x['child']} and '所属店铺' in page,'Store A list displays only A pages with a clear owning-store column')
    check('gsm_brand=6' in html.unescape(page),'Native status and sorting links retain the selected store')
    page=r.get(listing(7,s=x['suffix']))
    check(ids(page)=={x['b'],x['employee'],x['copy']},'Switching to B shows only independent B pages')
    page=r.get(listing(6,s='QA056 child '+x['suffix']))
    check(ids(page)=={x['child']},'Native page search works inside the selected store')
    page=r.get(listing(6,s=x['suffix'],orderby='title',order='desc'))
    check(ids(page)=={x['a'],x['child']},'Native sorting preserves page ownership filtering')
    status,body=r.req(listing(999999))
    check(status==404,'Unknown store scopes are rejected')
    h,editor=api_headers(r,x['a'],6)
    api_root=re.search(r'createRootURLMiddleware\(\s*["\']([^"\']+)',editor)
    check(api_root is not None and api_root.group(1).replace('\\/','/').startswith(main+'/wp-json/'),'Gutenberg REST calls use the authenticated admin host for a different storefront')
    check('gsm-page-brand' in editor and '页面归属与模板' not in editor and 'native-pages.js' not in editor,'Native editor displays read-only store ownership without inheritance controls')
    status,data=rest(r,'/wp/v2/pages/'+str(x['a'])+'?context=edit',h)
    check(status==200 and data['content']['raw']=='QA056 A independently edited','Native Gutenberg REST reads the independent A page')
    status,data=rest(r,'/wp/v2/pages/'+str(x['a']),h,{'content':'QA056 A HTTP independently edited'})
    check(status==200 and data['content']['raw']=='QA056 A HTTP independently edited','Native editor can freely update this store page content')
    status,data=rest(r,'/wp/v2/pages/'+str(x['a']),h,{'title':'Rejected partial change','parent':x['b']})
    check(status==403,'REST parent assignment rejects another store before saving other fields')
    status,data=rest(r,'/wp/v2/pages/'+str(x['a'])+'?context=edit',h)
    check(status==200 and data['title']['raw'].endswith(x['suffix']) and data['parent']==0,'Rejected parent request leaves title and hierarchy intact')
    status,data=rest(r,'/wp/v2/pages/'+str(x['a']),h,{'title':'Rejected reassignment','meta':{'_gsm_brand_id':7}})
    check(status==403,'REST metadata cannot reassign a page to another store')
    status,data=rest(r,'/wp/v2/pages?per_page=100&_fields=id',h)
    check(status==200 and x['a'] in {p['id'] for p in data} and x['b'] not in {p['id'] for p in data},'Block editor page choices stay in the signed store')
    status,data=rest(r,'/wp/v2/pages',h,{'title':'QA056 HTTP new '+x['suffix'],'content':'QA056 new independent page','status':'draft','parent':x['a']})
    check(status==201 and data['parent']==x['a'],'Native REST can create an ordinary independent page with a same-store parent')
    x['http']=data['id'];x['ids'].append(data['id'])
    check(x['http'] in ids(r.get(listing(6,s=x['suffix']))) and x['http'] not in ids(r.get(listing(7,s=x['suffix']))),'The new page is listed only under the selected store')
    check('QA056 A HTTP independently edited' in r.get(x['a_url']),'Published independent pages render at their own storefront URL')
    anonymous=client();status,body=anonymous.req(x['a_url'].replace(a,b))
    check(status==404,'An A page path on B cannot reveal A content')
    page=r.get(listing(7,s=x['suffix']));row=re.search(r'<tr[^>]+id="post-'+str(x['b'])+r'".*?</tr>',page,re.S).group(0)
    previews=[html.unescape(u) for u in re.findall(r'href=[\'\"]([^\'\"]+)[\'\"]',row) if 'gsm_preview_nonce' in u]
    check(bool(previews) and previews[0].startswith(main+'/'),'Native draft preview stays on the logged-in admin host')
    preview=r.get(previews[0]);check('QA056 B employee edit' in preview,'The root administrator can preview another store draft without a new domain session')
    status,body=anonymous.req(previews[0]);check(status==404,'A copied preview link alone does not grant anonymous access')
    bad=urlsplit(previews[0]);query=parse_qs(bad.query);query['gsm_preview_nonce']=['invalid'];status,body=r.req(main+'/?'+urlencode(query,doseq=True));check(status==404,'Invalid preview signatures cannot select another store')
    staff=client();staff.admin(b,f['email'],f['password_staff'],'/wp-admin/edit.php?post_type=page&gsm_brand=7')
    check(ids(staff.get(listing(7,s=x['suffix']).replace(main,b)))=={x['b'],x['employee'],x['copy']},'Brand administrator native list contains only permitted store pages')
    sh,_=api_headers(staff,x['b'],7,base=b)
    status,data=rest(staff,'/wp/v2/pages/'+str(x['b']),sh,{'content':'QA056 B staff REST independently edited'},base=b)
    check(status==200 and data['content']['raw']=='QA056 B staff REST independently edited','Brand administrator can independently save its page through Gutenberg REST')
    status,data=rest(staff,'/wp/v2/pages/'+str(x['a']),sh,{'content':'Unauthorized foreign edit'},base=b)
    check(status in (403,404),'Employee REST cannot mutate a foreign page by numeric ID')
    status,data=rest(staff,'/wp/v2/pages',sh,{'title':'QA056 HTTP employee '+x['suffix'],'status':'draft','content':'QA056 independent employee page'},base=b)
    check(status==201,'Authorized brand administrator can create a native independent page')
    x['ids'].append(data['id'])
    status,body=staff.req(listing(9).replace(main,b))
    check(status==403,'Employee cannot select an unauthorized store')
finally:
    xpath.write_text(json.dumps(x));xpath.chmod(0o600)
    for obj in clients:
        if obj.logout:obj.req(obj.logout)
        obj.jar.clear()
report={'version':'0.5.6','passed':len(checks),'checks':checks}
(reports/'http-native-pages.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2))
