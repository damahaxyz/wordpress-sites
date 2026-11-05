#!/usr/bin/env python3
"""Native product batch flows on loopback QA; credentials/tokens stay private."""
import copy,html,http.cookiejar,json,re,secrets,socket
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode,urlsplit,parse_qs
from urllib.request import Request,build_opener,HTTPCookieProcessor,ProxyHandler,HTTPRedirectHandler
from urllib.error import HTTPError
root=Path.cwd(); assert root==Path('/private/tmp/group-shop-manager-qa')
assert 'GSM_QA_DOMAIN=www.main.local' in (root/'.qa.env').read_text()
private=root/'.identity-private/fixtures.json'; f=json.loads(private.read_text())
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v052-local'
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
xfile=root/'.identity-private/bulk-products-v052.json'; x=json.loads(xfile.read_text())
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
main='http://www.main.local';a='http://www.branda.local'
class Config(HTMLParser):
    def __init__(self,body):super().__init__();self.value=None;self.feed(body)
    def handle_starttag(self,tag,attrs):
        d=dict(attrs)
        if tag=='div' and d.get('id')=='gsm-bulk-job':self.value=d
jobs=[]
def select(obj,mode,selected):
    page=obj.get(main+'/wp-admin/edit.php?post_type=product&gsm_brand=0')
    nonce=Form(page).fields['_wpnonce']
    status,body=obj.req(main+'/wp-admin/edit.php?'+urlencode({'post_type':'product','gsm_brand':0,'action':mode,'_wpnonce':nonce,'post[]':selected},doseq=True))
    assert status==200 and '确认并开始批量处理' in body
    form=Form(body).fields;assert form.get('action')=='gsm_bulk_start';jobs.append(form['gsm_job']);return form,body
def start(obj,form,target=0):
    data=dict(form);data['gsm_brand']='0';data['target_brand']=target
    status,body=obj.req(main+'/wp-admin/admin-post.php',data);assert status==200
    config=Config(body).value;assert config and config['data-stage']=='running';return config
def command(obj,config,control='step',nonce=None,base=main):
    status,body=obj.req(base+'/wp-admin/admin-ajax.php',{'action':'gsm_bulk_step','gsm_job':config['data-token'],'nonce':nonce or config['data-nonce'],'gsm_brand':'0','control':control})
    return status,json.loads(body)
def finish(obj,config):
    for _ in range(210):
        status,payload=command(obj,config);assert status==200 and payload.get('success'),('Task request failed',status)
        if payload['data']['stage']=='done':return payload['data']
    raise AssertionError('Task did not finish')
def list_ids(obj,brand,source=None):
    args={'post_type':'product','gsm_brand':brand,'s':x['suffix']}
    if source:args['gsm_base']=source
    return set(map(int,re.findall(r'<tr[^>]+id="post-(\d+)"',obj.get(main+'/wp-admin/edit.php?'+urlencode(args)))))
from concurrent.futures import ThreadPoolExecutor
r=client();r2=client()
try:
    for obj in [r,r2]:obj.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/edit.php?post_type=product&gsm_brand=0')
    page=r.get(main+'/wp-admin/edit.php?post_type=product&gsm_brand=0')
    check(all(label in page for label in ['批量添加到品牌（草稿）','批量上架品牌商品','批量转为草稿']),'Native Products exposes all three authorized bulk actions')
    form,body=select(r,'gsm_add_brand',[x['base']]);form2,body2=select(r2,'gsm_add_brand',[x['base']])
    check(not list_ids(r,13,x['base']),'Native Apply opens confirmation without creating a product')
    bad=dict(form);bad['_wpnonce']='invalid';status,body=r.req(main+'/wp-admin/admin-post.php',bad)
    check(status==403,'Starting a task rejects an invalid confirmation nonce')
    c1=start(r,form,13);c2=start(r2,form2,13)
    status,payload=command(r,c1,nonce='invalid');check(status==403 and not payload['success'],'Step endpoint rejects an invalid task nonce')
    with ThreadPoolExecutor(max_workers=2) as pool:results=list(pool.map(lambda pair:command(*pair),[(r,c1),(r2,c2)]))
    check(all(status==200 for status,payload in results),'Concurrent native task requests are accepted or safely wait')
    done=[payload['data'] if payload['data']['stage']=='done' else finish(obj,cfg) for (status,payload),obj,cfg in zip(results,[r,r2],[c1,c2])]
    check(sum(d['counts']['success'] for d in done)==1 and sum(d['counts']['skipped'] for d in done)==1 and len(list_ids(r,13,x['base']))==1,'Two concurrent add jobs create exactly one sale version and skip the duplicate')
    form,body=select(r,'gsm_add_brand',[x['other'],x['variable']]);config=start(r,form,13)
    status,paused=command(r,config,'pause');check(status==200 and paused['data']['stage']=='paused','Native task can pause before processing')
    status,idle=command(r,config);check(idle['data']['processed']==0,'Paused HTTP task performs no product writes')
    reloaded=r.get(main+'/wp-admin/edit.php?post_type=product&page=gsm-bulk-products&gsm_job='+config['data-token']+'&gsm_brand=0');newconfig=Config(reloaded).value
    check(newconfig and newconfig['data-stage']=='paused','Reload preserves the paused task and progress')
    command(r,newconfig,'resume');done=finish(r,newconfig);check(done['counts']=={'success':2,'skipped':0,'failed':0},'Resumed native task creates simple and variable inherited drafts')
    selected=list_ids(r,13);x['http_created']=sorted(selected)
    form,body=select(r,'gsm_publish',sorted(selected));published=finish(r,start(r,form));check(published['counts']['success']==len(selected),'Native bulk publish updates all selected brand drafts')
    form,body=select(r,'gsm_draft',sorted(selected));drafted=finish(r,start(r,form));check(drafted['counts']['success']==len(selected),'Native bulk draft updates all selected brand sale versions')
    repeat_form,body=select(r,'gsm_add_brand',[x['base'],x['other'],x['variable']]);repeat=finish(r,start(r,repeat_form,13))
    check(repeat['counts']=={'success':0,'skipped':3,'failed':0} and list_ids(r,13)==selected,'Repeating a confirmed batch preserves all existing versions without extra records')
    staff=client();staff.admin(a,f['email'],f['password_staff'],'/wp-admin/edit.php?post_type=product')
    page=staff.get(a+'/wp-admin/edit.php?post_type=product')
    check('value="gsm_add_brand"' not in page and 'value="gsm_publish"' in page,'Employee list offers status actions but no brand-association action')
    status,payload=command(staff,config,base=a);check(status==403,'Another user cannot run the root task')
finally:
    x['http_jobs']=jobs;xfile.write_text(json.dumps(x));xfile.chmod(0o600)
    for obj in clients:
        if obj.logout:obj.req(obj.logout)
        obj.jar.clear()
report={'version':'0.5.2','passed':len(checks),'checks':checks}
(reports/'http-bulk-products.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,indent=2))
