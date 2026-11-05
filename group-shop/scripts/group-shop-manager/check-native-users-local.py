#!/usr/bin/env python3
"""Read-only native-user acceptance on explicitly loopback-mapped QA domains."""
import html
import http.cookiejar
import json
import re
import socket
from pathlib import Path
from urllib.parse import urlencode, urlsplit
from urllib.request import Request, build_opener, HTTPCookieProcessor, ProxyHandler, HTTPRedirectHandler
root=Path.cwd()
assert root==Path('/private/tmp/group-shop-manager-qa'), 'Local synthetic QA directory required'
assert 'GSM_QA_DOMAIN=www.main.local' in (root/'.qa.env').read_text()
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v036-local'
fixtures=json.loads((reports/'native-users.json').read_text())['fixtures']
hosts={'www.main.local','www.branda.local'}
for host in hosts:
    assert socket.gethostbyname(host)=='127.0.0.1', 'Nonlocal QA host'
def allowed(url):
    p=urlsplit(url)
    assert p.scheme=='http' and p.hostname in hosts and p.port in (None,80), 'Unexpected target or redirect'
class LocalRedirect(HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):
        allowed(newurl)
        return super().redirect_request(req,fp,code,msg,headers,newurl)
client=build_opener(ProxyHandler({}),HTTPCookieProcessor(http.cookiejar.CookieJar()),LocalRedirect())
checks=[]; logouts=[]; main='http://www.main.local'
def req(url,data=None):
    allowed(url)
    with client.open(Request(url,data=urlencode(data).encode() if data is not None else None),timeout=30) as response:
        assert response.status==200
        return response.geturl(),response.read().decode()
def get(url): return req(url)[1]
def check(ok,name):
    assert ok,name
    checks.append(name)
def login(base,redirect):
    req(base+'/wp-login.php')
    final,body=req(base+'/wp-login.php',{'log':'gsm-admin','pwd':(root/'.qa-admin-password').read_text().strip(),'redirect_to':redirect,'testcookie':'1'})
    assert 'wp-admin-bar-my-account' in body and 'id="loginform"' not in body
    match=re.search(r'href=[\'"]([^\'"]*wp-login\.php\?action=logout[^\'"]*)[\'"]',body)
    assert match
    logouts.append(html.unescape(match.group(1)))
    return body
def row(body,id):
    for item in re.findall(r'<tr\b[^>]*>.*?</tr>',body,re.S):
        if id==1 and 'network/profile.php' in item and 'gsm-admin' in item:
            return item
        if 'user-edit.php' in item and re.search(r'user_id='+str(id)+r"""(?:&|['"])""",html.unescape(item)):
            return item
    raise AssertionError('Missing native user row')
try:
    final,body=req(main+'/wp-admin/network/users.php')
    check('/wp-login.php' in final,'Anonymous network users requires login')
    body=login(main,main+'/wp-admin/network/users.php')
    check('首次来源店铺' in body and 'column-gsm_first_store' in body,'Native network users renders origin column')
    check('column-blogs' in body and 'name="s"' in body and 'bulk-action-selector-top' in body,'Native Sites, search and bulk controls remain')
    for fixture in fixtures:
        item=row(body,fixture['id'])
        cell=re.search(r"""<td[^>]*class=['"][^'"]*column-gsm_first_store[^'"]*['"][^>]*>(.*?)</td>""",item,re.S).group(1)
        check(html.unescape(re.sub('<[^>]+>','',cell)).strip()==html.unescape(fixture['label']),'Native row origin matches recorded source: '+str(fixture['id']))
        check('column-blogs' in item and ('user-edit.php' in item or 'network/profile.php' in item),'Native membership and edit links remain: '+str(fixture['id']))
    search=get(main+'/wp-admin/network/users.php?s=gsm-admin')
    check('gsm-admin' in row(search,1),'Native username search works')
    edit=get(main+'/wp-admin/network/user-edit.php?user_id='+str(fixtures[-1]['id']))
    check('id="your-profile"' in edit and 'name="email"' in edit,'Native network user editor loads')
    check('name="gsm_first_store"' not in edit,'Origin extension adds no editable field or save handler')
    site=get(main+'/wp-admin/users.php')
    check('column-gsm_first_store' not in site,'Main site user list is not extended with network-only origin')
    site_edit=get(main+'/wp-admin/user-edit.php?user_id='+str(fixtures[-1]['id']))
    check('name="billing_city"' in site_edit and 'name="shipping_city"' in site_edit,'Woo native user editor retains shared billing and shipping fields')
    overview=get(main+'/wp-admin/admin.php?page=muti-storefront-plugin')
    check('v0.3.6' in overview and 'tab=customers' not in overview and '共享客户' not in overview,'Manager no longer exposes shared customer tab')
    check(overview.count('class="nav-tab ')==7 and main+'/wp-admin/network/users.php' in html.unescape(overview),'Overview has seven tabs and native network user entry')
    old=get(main+'/wp-admin/admin.php?page=muti-storefront-plugin&tab=customers')
    check('<h2>店铺入口</h2>' in old and '首次来源</th>' not in old,'Old customer bookmark falls back to overview')
    child='http://www.branda.local'
    child_users=login(child,child+'/wp-admin/users.php')
    check('column-gsm_first_store' not in child_users,'Child site user list retains its original columns')
    check('Your account and saved billing/shipping addresses are shared across our stores.' in get(child+'/my-account/edit-account/'),'Frontend account still explains shared credentials and addresses')
finally:
    for url in logouts: req(url)
    if logouts: checks.append('Only temporary per-domain acceptance sessions were logged out')
report={'version':'0.3.6','passed':len(checks),'checks':checks}
(reports/'http-native-users.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
