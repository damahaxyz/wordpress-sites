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
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v057-local'
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
x=json.loads((root/'.identity-private/native-menus-v057.json').read_text());checks=[];clients=[]
def check(ok,name):
    assert ok,name
    checks.append(name)
def client():
    c=Client();clients.append(c);return c
def url(brand=None,menu=None,**extra):
    return main+'/wp-admin/nav-menus.php?'+urlencode({**({'gsm_brand':brand} if brand is not None else {}),**({'menu':menu} if menu is not None else {}),**extra})
def config(page):return json.loads(re.search(r'window.gsmNativeMenus=(\{[^;]+\});',page).group(1))
def scoped(page):
    c=config(page);return {'gsm_brand':c['brand'],'gsm_menu_scope_nonce':c['nonce']}
def update_form(page):
    body=re.search(r'<form id="update-nav-menu".*?</form>',page,re.S).group(0)
    return {**Form(body).fields,**scoped(page)}
def add(c,page,kind,object,id=0,link=None,base=main):
    data={'action':'add-menu-item','menu':x['menu_a'] if base==main else x['menu_b'],'menu-settings-column-nonce':Inputs(page).fields['menu-settings-column-nonce'],**scoped(page)}
    row={'menu-item-type':kind,'menu-item-object':object,'menu-item-object-id':id,'menu-item-title':'QA057 HTTP '+x['suffix']}
    if link is not None:row['menu-item-url']=link
    data.update({'menu-item[-1]['+k+']':v for k,v in row.items()})
    return c.req(base+'/wp-admin/admin-ajax.php',data)
def search(c,page,type,**extra):
    return c.req(main+'/wp-admin/admin-ajax.php',{'action':'menu-quick-search','type':type,'q':x['suffix'],**scoped(page),**extra})
r=client()
try:
    r.admin(main,'gsm-admin',(root/'.qa-admin-password').read_text().strip(),'/wp-admin/nav-menus.php?gsm_brand=6')
    page=r.get(url())
    check(config(page)['brand']==1 and '菜单所属店铺' in page,'Bare native Menus defaults to main store despite previous store cookie')
    page=r.get(url(6,x['menu_a']))
    check(config(page)['brand']==6 and 'QA057 a '+x['suffix'] in page and 'QA057 b '+x['suffix'] not in page,'Store selector and menu list isolate A from B')
    check('QA057 page a '+x['suffix'] in page and 'QA057 page b '+x['suffix'] not in page,'Native Pages picker lists only current store pages')
    check('QA057 collection a '+x['suffix'] in page and 'QA057 collection b '+x['suffix'] not in page,'Native categories picker contains own collections and hides foreign collections')
    status,body=r.req(url(6,x['menu_b']));check(status==404,'Explicit foreign menu edit ID is rejected')
    status,body=r.req(url(999999));check(status==404,'Unknown store selector is rejected')
    status,body=search(r,page,'quick-search-posttype-product')
    check(status==200 and str(x['product_a']) in body and str(x['product_b']) not in body and str(x['base']) not in body,'Asynchronous product search returns own sales edition only')
    status,body=search(r,page,'quick-search-posttype-page')
    check(status==200 and str(x['page_a']) in body and str(x['page_b']) not in body,'Asynchronous page search is store scoped')
    status,body=search(r,page,'quick-search-taxonomy-product_cat')
    check(status==200 and str(x['collection_a']) in body and str(x['shared']) in body and str(x['collection_b']) not in body,'Asynchronous category search includes shared categories plus own collections')
    status,body=search(r,page,'get-post-item',object_type='page',ID=x['page_b']);check(status==403,'Direct picker fetch by foreign page ID is rejected')
    for kind,obj,id in [('post_type','page',x['page_b']),('post_type','product',x['product_b']),('post_type','product',x['base']),('taxonomy','product_cat',x['collection_b'])]:
        status,body=add(r,page,kind,obj,id);check(status==403,'Forged add-menu-item rejects foreign or public resource '+obj)
    status,body=add(r,page,'custom','custom',link=b+'/privacy-policy/');check(status==403,'Custom link cannot point at another storefront')
    status,body=add(r,page,'post_type','product',x['product_a']);check(status==200 and 'menu-item-' in body,'Native AJAX can add an own product')
    added=int(re.search(r'id="menu-item-(\d+)"',body).group(1));x['http_added']=added
    # Record the orphan before subsequent assertions, allowing precise cleanup after a failed run.
    inventory=root/'.identity-private/native-menus-v057.json';saved=json.loads(inventory.read_text());saved['posts'].append(added);saved['http_added']=added;inventory.write_text(json.dumps(saved));inventory.chmod(0o600)
    own=update_form(page);key=str(added)
    for field,value in {'db-id':added,'object-id':x['product_a'],'object':'product','parent-id':x['item_a'],'position':3,'type':'post_type','title':'QA057 HTTP '+x['suffix'],'url':'','description':'','attr-title':'','target':'','classes':'','xfn':''}.items():own['menu-item-'+field+'['+key+']']=value
    own['menu-locations[primary]']=x['menu_a'];own['menu-locations[footer]']=x['menu_a']
    status,body=r.req(url(6,x['menu_a']),own)
    check(status==200 and 'has been updated' in body,'Classic native save persists own product as a child item')
    page=r.get(url(6,x['menu_a']));saved_form=update_form(page)
    check(saved_form.get('menu-item-parent-id['+key+']')==str(x['item_a']),'Native hierarchy survives reload')
    # Validate the whole request before renaming or mutating any item.
    bad=copy.deepcopy(saved_form);bad['menu-name']='Rejected QA057 rename';bad['menu-item-object-id['+key+']']=x['product_b']
    status,body=r.req(url(6,x['menu_a']),bad);check(status==403,'Mixed native save with foreign resource is rejected before menu rename')
    check('QA057 a '+x['suffix'] in r.get(url(6,x['menu_a'])),'Rejected native save leaves original menu name intact')
    bad=copy.deepcopy(saved_form);bad['menu-locations[primary]']=x['menu_b']
    status,body=r.req(url(6,x['menu_a']),bad);check(status==403,'Cross-store theme location binding is rejected')
    bad=copy.deepcopy(saved_form);bad['menu-item-parent-id['+key+']']=added
    status,body=r.req(url(6,x['menu_a']),bad);check(status==403,'Forged self-parent menu hierarchy is rejected')
    bad=copy.deepcopy(saved_form);bad['gsm_menu_scope_nonce']='invalid'
    status,body=r.req(url(6,x['menu_a']),bad);check(status==403,'Invalid store signature cannot save a menu')
    status,body=search(r,page,'get-post-item',object_type='attachment',ID=x['page_a']);check(status==403,'Unsupported resource picker cannot fetch arbitrary objects')
    status,body=r.req(main+'/wp-admin/admin-ajax.php',{'action':'menu-locations-save','menu-locations[primary]':x['menu_b'],'menu-settings-column-nonce':Inputs(page).fields['menu-settings-column-nonce'],**scoped(page)})
    check(status==403,'AJAX location saving also rejects foreign menu IDs')
    front=r.get(a+'/');other=r.get(b+'/')
    check(('QA057 HTTP '+x['suffix']) in front and ('QA057 HTTP '+x['suffix']) not in other,'Storefront A renders own header/footer menu while B remains independent')
    locations=r.get(url(6,action='locations'))
    check('Assigned Menu' in locations,'Manage Locations GET remains available without a mutation signature')
    status,body=r.req(url(6,action='locations'),{'action':'locations','menu-locations[primary]':x['menu_a'],'menu-locations[footer]':x['menu_a'],'_wpnonce':Inputs(locations).fields['_wpnonce'],**scoped(locations)})
    check(status==200 and 'Menu locations updated' in body,'Native location form saves only current store assignments')
    create=r.get(url(6,0));newdata={'action':'update','menu':0,'menu-name':'QA057 HTTP new '+x['suffix'],'update-nav-menu-nonce':Inputs(create).fields['update-nav-menu-nonce'],**scoped(create)}
    status,body=r.req(url(6,0),newdata);check(status==200 and 'QA057 HTTP new '+x['suffix'] in body,'Native menu creation succeeds in selected store')
    created=int(update_form(body)['menu']);saved=json.loads(inventory.read_text());saved['terms'].append(created);inventory.write_text(json.dumps(saved));inventory.chmod(0o600)
    delete=html.unescape(re.search(r'<a class="submitdelete deletion menu-delete" href="([^"]+)"',body).group(1))
    status,body=r.req(delete);check(status==200 and 'menu has been successfully deleted' in body,'Native menu deletion carries store signature and preserves its scope')
    # Core menu REST API and Customizer are deliberately not alternative editors.
    status,body=r.req(main+'/wp-json/wp/v2/menus');check(status==403,'Alternate REST menu editor is closed rather than exposing unscoped menus')
    s=client();s.admin(b,f['email'],f['password_staff'],'/wp-admin/nav-menus.php?gsm_brand=7')
    employee=s.get(b+'/wp-admin/nav-menus.php?gsm_brand=7&menu='+str(x['menu_b']))
    check(config(employee)['brand']==7 and '店铺菜单' in employee,'Authorized brand administrator can open native store menu editor')
    status,body=add(s,employee,'post_type','page',x['page_b'],base=b)
    check(status==200 and 'menu-item-' in body,'Authorized employee can add an own page through native AJAX')
    empid=int(re.search(r'id="menu-item-(\d+)"',body).group(1));saved=json.loads(inventory.read_text());saved['posts'].append(empid);inventory.write_text(json.dumps(saved));inventory.chmod(0o600)
    foreign={'action':'add-menu-item','menu':x['menu_a'],'menu-settings-column-nonce':Inputs(employee).fields['menu-settings-column-nonce'],'gsm_brand':6,'gsm_menu_scope_nonce':scoped(employee)['gsm_menu_scope_nonce']}
    status,body=s.req(b+'/wp-admin/admin-ajax.php',foreign);check(status==403,'Employee cannot switch a menu mutation into Shop Manager scope')
    status,body=s.req(a+'/wp-admin/nav-menus.php?gsm_brand=6');check(status in (200,403) and 'window.gsmNativeMenus=' not in body,'No automatic staff login or menu privilege on Shop Manager store')
    status,body=s.req(b+'/wp-admin/themes.php?gsm_brand=7');check('window.gsmNativeMenus=' not in body and ('not allowed' in body or 'higher level' in body),'Menu authority does not unlock theme management')
    print(json.dumps({'version':'0.5.7','passed':len(checks),'checks':checks},indent=2))
    reports.mkdir(exist_ok=True,parents=True);(reports/'http-native-menus.json').write_text(json.dumps({'version':'0.5.7','passed':len(checks),'checks':checks},indent=2)+'\n')
finally:
    for c in clients:
        if c.logout:
            try:c.req(c.logout)
            except Exception:pass
