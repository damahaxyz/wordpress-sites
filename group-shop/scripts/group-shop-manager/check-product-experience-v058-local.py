#!/usr/bin/env python3
"""Store-isolated native menus, on loopback QA only."""
import copy,html,http.cookiejar,json,re,secrets,socket
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode,urlsplit,parse_qs
from urllib.request import Request,build_opener,HTTPCookieProcessor,ProxyHandler,HTTPRedirectHandler
from urllib.error import HTTPError
root=Path.cwd(); assert root==Path('/private/tmp/group-shop-manager-qa')
assert 'GSM_QA_DOMAIN=www.main.local' in (root/'.qa.env').read_text()
private=root/'.identity-private/fixtures.json'; f=json.loads(private.read_text())
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v058-local'
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
x=json.loads((root/'.identity-private/product-experience-v058.json').read_text());checks=[];clients=[]
def check(ok,name):
    assert ok,name
    checks.append(name)
def client():
    c=Client();clients.append(c);return c
def editor(id,brand):return main+'/wp-admin/post.php?'+urlencode({'post':id,'action':'edit','gsm_brand':brand})
def form(body):
    body=re.sub(r'<script\b.*?</script>','',body,flags=re.S)
    text=re.search(r'<form[^>]*id="post"[^>]*>.*?</form>',body,re.S).group(0)
    text=re.sub(r'<template\b.*?</template>','',text,flags=re.S)
    return Form(text).fields
def save(c,data,base=main):return c.req(base+'/wp-admin/post.php',data)
def cart(c,base):
    status,body=c.req(base+'/wp-json/wc/store/v1/cart');assert status==200
    return json.loads(body)
r=client();g=client()
try:
    r.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/edit.php?post_type=product&gsm_brand=0')
    body=r.get(editor(x['base'],0));check('加购规则在各店铺' in body and 'trovesia_addon_rule_count' not in body,'Public editor explains independent brand add-ons without editable rules')
    body=r.get(editor(x['a'],6));data=form(body)
    check('单独设置本店展示信息' in body and 'QA058 public latest' in body,'Brand presentation shows inherited public values and an explicit override control')
    picker=re.search(r'<select[^>]*name="trovesia_addons\[0\]\[product_id\]".*?</select>',body,re.S).group(0)
    check('value="'+str(x['extra_a'])+'"' in picker and 'value="'+str(x['extra_b'])+'"' not in picker and 'value="'+str(x['extra'])+'"' not in picker,'Add-on picker contains own sales versions and excludes public/foreign products')
    bad=copy.deepcopy(data);bad['post_title']='Rejected QA058 rename';bad['trovesia_addons[0][product_id]']=x['extra_b']
    status,body=save(r,bad);check(status==403,'Forged foreign add-on is rejected before native product save')
    check(form(r.get(editor(x['a'],6)))['post_title']==data['post_title'],'Rejected add-on request leaves title unchanged')
    bad=copy.deepcopy(data);del bad['trovesia_addon_rules_complete']
    status,body=save(r,bad);check(status==403,'Truncated add-on form is rejected before mutation')
    bad=copy.deepcopy(data);del bad['trovesia_product_meta_complete']
    status,body=save(r,bad);check(status==403,'Truncated presentation form is rejected before mutation')
    data['gsm_brand']=6;data['trovesia_eyebrow']='Forged inherited text'
    status,body=save(r,data);check(status==200,'Native inherited product save succeeds while ignoring hidden presentation edits')
    check(form(r.get(editor(x['a'],6)))['trovesia_eyebrow']==x['shared_eyebrow'],'Forged inherited field does not replace public presentation')
    data=form(r.get(editor(x['a'],6)));data['gsm_brand']=6;data['gsm_product_overrides[]']=['presentation'];data['trovesia_eyebrow']='QA058 HTTP store A';data['trovesia_order_activity']='QA058 A activity'
    status,body=save(r,data);check(status==200,'Native brand presentation override can be saved')
    check(form(r.get(editor(x['a'],6)))['trovesia_eyebrow']=='QA058 HTTP store A','Custom presentation persists after reload')
    check(form(r.get(editor(x['b'],7)))['trovesia_eyebrow']==x['shared_eyebrow'],'A presentation override leaves B inheritance unchanged')
    data=form(r.get(editor(x['a'],6)));data['gsm_brand']=6;data.pop('gsm_product_overrides[]',None)
    status,body=save(r,data);check(status==200 and form(r.get(editor(x['a'],6)))['trovesia_eyebrow']==x['shared_eyebrow'],'Unchecking presentation override restores current public content')
    data=form(r.get(editor(x['base'],0)));data['gsm_brand']=0;data['trovesia_eyebrow']='QA058 HTTP public updated'
    status,body=save(r,data);check(status==200,'Public presentation can be edited in its original native metabox')
    check(form(r.get(editor(x['a'],6)))['trovesia_eyebrow']=='QA058 HTTP public updated' and form(r.get(editor(x['b'],7)))['trovesia_eyebrow']=='QA058 HTTP public updated','Native public edit reaches both inheriting brand editors')
    status,body=g.req(a+'/?post_type=product&p='+str(x['a']))
    check(status==200 and 'QA058 HTTP public updated' in body and 'trovesia-product-addon' in body,'Actual product page renders inherited presentation and own add-on card')
    status,body=g.req(a+'/?add-to-cart='+str(x['a']),{'quantity':2,'trovesia_addons[0]':'qa058-extra'})
    items=cart(g,a)['items'];main_item=next(i for i in items if i['id']==x['a']);child=next(i for i in items if i['id']==x['extra_a'])
    check(len(items)==2 and child['quantity']==4 and child['prices']['price']=='500','Guest classic add-to-cart creates own add-on at correct price and quantity')
    check(cart(g,b)['items']==[],'Another storefront cannot see the A shopping session')
    home=g.get(a+'/');config=json.loads(re.search(r'var trovesiaCart\s*=\s*(\{.*?\});',home).group(1))
    status,body=g.req(a+'/wp-admin/admin-ajax.php',{'action':'trovesia_update_cart_quantity','nonce':config['nonce'],'cart_item_key':main_item['key'],'quantity':3})
    check(status==200,'Ordinary mini-cart quantity AJAX remains functional')
    items=cart(g,a)['items'];check(next(i for i in items if i['id']==x['a'])['quantity']==3 and next(i for i in items if i['id']==x['extra_a'])['quantity']==6,'HTTP quantity update also synchronizes add-on units')
    status,body=g.req(a+'/wp-admin/admin-ajax.php',{'action':'trovesia_update_cart_quantity','nonce':config['nonce'],'cart_item_key':child['key'],'quantity':9})
    check(status==400,'An add-on quantity cannot be independently changed through mini-cart AJAX')
    data=form(r.get(editor(x['a'],6)));data['gsm_brand']=6;data['trovesia_addons[0][product_id]']=x['other_a']
    status,body=save(r,data);check(status==200,'Own-store add-on can be deliberately rebound to another own product')
    items=cart(g,a)['items'];check(len(items)==1 and items[0]['id']==x['a'],'Real cart invalidates old child after rule rebind instead of applying new product price')
    # Leave the fixture ready for browser verification with the original own-store add-on.
    data=form(r.get(editor(x['a'],6)));data['gsm_brand']=6;data['trovesia_addons[0][product_id]']=x['extra_a'];save(r,data)
    print(json.dumps({'version':'0.5.8','passed':len(checks),'checks':checks},indent=2))
    reports.mkdir(exist_ok=True,parents=True);(reports/'http-product-experience.json').write_text(json.dumps({'version':'0.5.8','passed':len(checks),'checks':checks},indent=2)+'\n')
finally:
    for c in clients:
        if c.logout:
            try:c.req(c.logout)
            except Exception:pass
