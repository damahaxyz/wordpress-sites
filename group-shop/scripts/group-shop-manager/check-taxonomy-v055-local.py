#!/usr/bin/env python3
"""Shared category views and collection hierarchy on loopback QA only."""
import copy,html,http.cookiejar,json,re,secrets,socket
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode,urlsplit,parse_qs
from urllib.request import Request,build_opener,HTTPCookieProcessor,ProxyHandler,HTTPRedirectHandler
from urllib.error import HTTPError
root=Path.cwd(); assert root==Path('/private/tmp/group-shop-manager-qa')
assert 'GSM_QA_DOMAIN=www.main.local' in (root/'.qa.env').read_text()
private=root/'.identity-private/fixtures.json'; f=json.loads(private.read_text())
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v055-local'
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

main='http://www.main.local'; a='http://www.branda.local'
x=json.loads((root/'.identity-private/collections-v055.json').read_text())
checks=[]
def check(ok,name):
    assert ok,name
    checks.append(name)
def ids(body): return set(map(int,re.findall(r'<tr[^>]+id="tag-(\d+)"',body)))
def selected(body,name):
    m=re.search(r'<select[^>]+name="'+name+r'"[^>]*>(.*?)</select>',body,re.S)
    return Form(m.group(0)).fields.get(name) if m else None
def native(**args):
    return main+'/wp-admin/edit-tags.php?'+urlencode({'taxonomy':'product_cat','post_type':'product',**args})
r=Client()
try:
    r.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/edit-tags.php?taxonomy=product_cat&post_type=product&gsm_brand=7')
    r.get(native(gsm_brand=7))
    page=r.get(native(s=x['suffix']))
    check(ids(page)=={x['shared'],x['sharedchild']} and selected(page,'gsm_brand')=='0','Native categories default to shared vocabulary despite the previous brand cookie')
    check(re.search(r'<span class="displaying-num">[^<]*2',page) is not None,'Default list count includes only matching shared categories')
    for scope in [0,6,7]:
        first=r.get(native(gsm_brand=scope))
        total=int(re.search(r'<span class="displaying-num">([0-9,]+)',first).group(1).replace(',',''))
        pages=re.search(r'<span class=[\'"]total-pages[\'"]>([0-9]+)</span>',first)
        found=ids(first)
        for n in range(2,int(pages.group(1))+1 if pages else 2):
            nextpage=r.get(native(gsm_brand=scope,paged=n))
            check(selected(nextpage,'gsm_brand')==str(scope),'Native page '+str(n)+' retains scope '+str(scope))
            found|=ids(nextpage)
        check(len(found)==total,'Complete paginated category view matches its SQL count for scope '+str(scope))
    page=r.get(native(gsm_brand=6,s=x['suffix']))
    check(ids(page)=={x['parent'],x['child'],x['grand']} and selected(page,'gsm_brand')=='6','Brand A category view contains only A collections and preserves hierarchy')
    check('inline hide-if-no-js' not in re.search(r'<tbody id="the-list".*?</tbody>',page,re.S).group(0),'Collection rows do not expose native quick edit')
    check('管理商品集合' in page and '创建集合' in page,'Native collection view links to collection management and creation')
    links=[html.unescape(u) for u in re.findall(r'href=[\'\"]([^\'\"]+)[\'\"]',page) if 'orderby=' in u]
    check(links and all(parse_qs(urlsplit(u).query).get('gsm_brand')==['6'] for u in links),'Native sorting links preserve selected store')
    page=r.get(native(gsm_brand=6,s=x['suffix'],orderby='name',order='desc'))
    check(ids(page)=={x['parent'],x['child'],x['grand']},'Explicit native sort keeps the filtered collection set')
    page=r.get(native(gsm_brand=7,s=x['suffix']))
    check(ids(page)=={x['foreign']},'Switching to B excludes all A and shared categories')
    page=r.get(native(gsm_brand=6,s='QA055 Grandchild '+x['suffix']))
    check(ids(page)=={x['grand']},'Native search finds a nested collection without leaking ancestors or foreign terms')
    status,body=r.req(native(gsm_brand=999999))
    check(status==404,'Unknown store selector is rejected')
    tagpage=r.get(main+'/wp-admin/edit-tags.php?taxonomy=product_tag&post_type=product&gsm_brand=0')
    check('gsm-taxonomy-brand' not in tagpage,'Shared tag page is unchanged by the category selector')
    page=r.get(main+'/wp-admin/term.php?taxonomy=product_cat&tag_ID='+str(x['child'])+'&post_type=product&gsm_brand=6')
    check('gsm_collection_complete' in page and selected(page,'parent')==str(x['parent']),'Old native collection edit URL opens its complete collection editor with the stored parent')
    nonce=Inputs(page).fields['_wpnonce']
    parents=re.search(r'<select[^>]+name="parent"[^>]*>(.*?)</select>',page,re.S).group(1)
    check('value="'+str(x['foreign'])+'"' not in parents and 'value="'+str(x['child'])+'"' not in parents and 'value="'+str(x['grand'])+'"' not in parents,'Parent picker excludes foreign, self and descendant collections')
    save={**x['http_parent_data'],'name':'QA055 Child '+x['suffix'],'include[]':[x['a2']],'parent':0,'action':'gsm_action','kind':'collection_save','store':6,'collection':x['child'],'return_tab':'collections','_wpnonce':nonce,'gsm_collection_complete':'1','gsm_brand':0}
    save.pop('include',None)
    page=r.get(main+'/wp-admin/admin.php?page=muti-storefront-plugin&tab=collections&store=6&collection='+str(x['child'])+'&gsm_brand=0')
    status,body=r.req(main+'/wp-admin/admin-post.php',save)
    check(status==200 and selected(body,'parent')=='0','Real collection POST can move a child to top level')
    save['parent']=x['parent'];status,body=r.req(main+'/wp-admin/admin-post.php',save)
    check(status==200 and selected(body,'parent')==str(x['parent']),'Real collection POST persists a same-store parent')
    save['parent']=x['foreign'];status,body=r.req(main+'/wp-admin/admin-post.php',save)
    check(status==200 and '上次提交的内容已保留' in body and '父级必须是同店铺' in body,'Invalid cross-store parent preserves failed form values with an actionable error')
    saved=r.get(main+'/wp-admin/admin.php?page=muti-storefront-plugin&tab=collections&store=6&collection='+str(x['child'])+'&gsm_brand=0')
    # Clear the private retry form by saving the original valid hierarchy.
    save['parent']=x['parent'];status,body=r.req(main+'/wp-admin/admin-post.php',save)
    check(status==200 and selected(body,'parent')==str(x['parent']),'Retry succeeds without replacing the collection ID')
    save['_wpnonce']='invalid';status,body=r.req(main+'/wp-admin/admin-post.php',save)
    check(status==403,'Collection hierarchy write requires a valid nonce')
    status,body=r.req(main+'/wp-admin/admin-ajax.php',{'action':'inline-save-tax','taxonomy':'product_cat','tax_ID':x['child'],'name':'Tampered name','parent':x['foreign']})
    check(status==403 and '集合管理' in body,'Native quick-edit POST cannot bypass collection hierarchy validation')
    page=r.get(native(gsm_brand=6,s=x['suffix']))
    parentrow=re.search(r'<tr[^>]+id="tag-'+str(x['parent'])+r'".*?</tr>',page,re.S).group(0)
    archive_urls=[html.unescape(u) for u in re.findall(r'href=[\'\"]([^\'\"]+)[\'\"]',parentrow) if '/product-category/' in u]
    check(bool(archive_urls),'Parent collection has a native archive URL')
    for suffix in ['', '?orderby=price']:
        archive=r.get(archive_urls[0]+suffix)
        check(re.search(r'\bpost-'+str(x['a'])+r'\b',archive) is not None and re.search(r'\bpost-'+str(x['a2'])+r'\b',archive) is None,'Collection archive keeps independent rule membership with sort '+(suffix or 'default'))
    status,body=r.req(main+'/wp-admin/admin-ajax.php',{'action':'woocommerce_term_ordering','thetaxonomy':'product_cat','id':x['child']})
    check(status==403,'Native drag-ordering POST cannot rewrite collection order')
    native_nonce=Inputs(page).fields['_wpnonce']
    status,body=r.req(native(),{'action':'delete','taxonomy':'product_cat','delete_tags[]':[x['child']],'_wpnonce':native_nonce})
    check(status==403 and '集合管理' in body,'Native bulk deletion cannot remove collection rules or hierarchy')
    status,body=r.req(main+'/wp-admin/admin-ajax.php',{'action':'add-tag','taxonomy':'product_cat','tag-name':'QA055 invalid '+x['suffix'],'parent':x['parent']})
    check(status==403 and '共享分类不能' in body,'Native shared category creation cannot attach to a private collection parent')
    child_editor=r.get(main+'/wp-admin/admin.php?page=muti-storefront-plugin&tab=collections&store=6&collection='+str(x['child'])+'&gsm_brand=0')
    check('Tampered name' not in child_editor and selected(child_editor,'parent')==str(x['parent']),'Rejected native writes leave the collection title and parent intact')
finally:
    if r.logout:r.req(r.logout)
    r.jar.clear()
report={'version':'0.5.5','passed':len(checks),'checks':checks}
(reports/'http-taxonomy.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
