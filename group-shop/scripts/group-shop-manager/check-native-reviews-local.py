#!/usr/bin/env python3
"""Native Reviews acceptance, restricted to explicitly loopback-mapped .local QA."""
import html
import http.cookiejar
import json
import re
import socket
from pathlib import Path
from urllib.parse import urlencode, urlsplit, urljoin
from urllib.request import Request, build_opener, HTTPCookieProcessor, ProxyHandler, HTTPRedirectHandler

root=Path.cwd()
assert root==Path('/private/tmp/group-shop-manager-qa'), 'Local synthetic QA directory required'
assert 'GSM_QA_DOMAIN=www.main.local' in (root/'.qa.env').read_text()
reports=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v035-local'
fixtures=json.loads((reports/'native-reviews.json').read_text())['fixtures']
hosts={f['host'] for f in fixtures.values()}
for host in hosts:
    assert host.endswith('.local') and socket.gethostbyname(host)=='127.0.0.1', 'Nonlocal QA host'

def allowed(url):
    p=urlsplit(url)
    assert p.scheme=='http' and p.hostname in hosts and p.port in (None,80), 'Unexpected target or redirect'

class LocalRedirect(HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):
        allowed(newurl)
        return super().redirect_request(req,fp,code,msg,headers,newurl)

client=build_opener(ProxyHandler({}),HTTPCookieProcessor(http.cookiejar.CookieJar()),LocalRedirect())
checks=[]; logouts=[]; restore=[]
def req(url,data=None):
    allowed(url)
    with client.open(Request(url,data=urlencode(data).encode() if data is not None else None),timeout=30) as response:
        assert response.status==200
        return response.geturl(),response.read().decode()
def get(url): return req(url)[1]
def check(ok,name):
    assert ok,name
    checks.append(name)
def row(body,id):
    match=re.search(r'<tr\b[^>]*id="comment-'+str(id)+r'"[^>]*>.*?</tr>',body,re.S)
    assert match, 'Missing native review row'
    return match.group(0)
def action(body,id,kind,base):
    item=row(body,id)
    links=[html.unescape(v) for v in re.findall(r'href=[\'"]([^\'"]+)[\'"]',item)]
    return urljoin(base,next(v for v in links if 'action='+kind in v))

try:
    for store,f in fixtures.items():
        base='http://'+f['host']
        final,body=req(f['url'])
        check('/wp-login.php' in final,'Anonymous native Reviews requires login: '+store)
        final,body=req(base+'/wp-login.php',{'log':'gsm-admin','pwd':(root/'.qa-admin-password').read_text().strip(),'redirect_to':f['url'],'testcookie':'1'})
        assert 'wp-admin-bar-my-account' in body and 'id="loginform"' not in body
        logout=re.search(r'href=[\'"]([^\'"]*wp-login\.php\?action=logout[^\'"]*)[\'"]',body)
        assert logout
        logouts.append(html.unescape(logout.group(1)))
        check('所属店铺' in body and 'column-gsm_store' in body,'Native Reviews exposes store column: '+store)
        for id in [f['review'],f['reply']]:
            item=row(body,id)
            cell=re.search(r"""<td[^>]*class=['"][^'"]*column-gsm_store[^'"]*['"][^>]*>(.*?)</td>""",item,re.S).group(1)
            check(html.unescape(re.sub('<[^>]+>','',cell)).strip()==f['label'],'Native review/reply row has correct current-store ownership: '+store+' row '+str(id))
        check('review_rating' in body and 'bulk-action-selector-top' in body and 'Reply' in body and 'Quick Edit' in body,'Native filters, bulk actions, reply and edit remain available: '+store)
        unapprove=action(body,f['review'],'unapprovecomment',base)
        req(unapprove)
        pending=get(f['url']+'&comment_status=moderated')
        check('unapproved' in row(pending,f['review']),'Native unapprove action works: '+store)
        approve=action(pending,f['review'],'approvecomment',base)
        restore.append(approve)
        req(approve)
        current=get(f['url'])
        check('unapproved' not in re.search(r'<tr[^>]+>',row(current,f['review'])).group(0),'Native approve action restores review: '+store)
        restore.remove(approve)
    main='http://www.main.local'
    overview=get(main+'/wp-admin/admin.php?page=group-shop-manager')
    check('v0.3.5' in overview and '独立评论' not in overview and 'tab=reviews' not in overview,'Manager removes independent reviews tab and links')
    check(overview.count('class="nav-tab ')==8,'Manager has eight active tabs')
    old=get(main+'/wp-admin/admin.php?page=group-shop-manager&tab=reviews&store=6')
    check('<h2>店铺入口</h2>' in old and 'review_status' not in old,'Old review bookmark safely falls back to overview')
    edit=get(main+'/wp-admin/admin.php?page=group-shop-manager&tab=collections&collection=new')
    token=re.search(r'name="_wpnonce" value="([^"]+)"',edit).group(1)
    for store,f in fixtures.items():
        _,result=req(main+'/wp-admin/admin-post.php',{'action':'gsm_action','kind':'review_status','store':store,'review':f['review'],'status':'spam','return_tab':'reviews','_wpnonce':token})
        check('未知管理操作' in result,'Old review moderation handler rejects requests: '+store)
        current=get(f['url'])
        check('GSM native review store '+store in row(current,f['review']),'Rejected central request leaves native review unchanged: '+store)
    comments=get(main+'/wp-admin/edit-comments.php')
    check('column-gsm_store' not in comments,'Ordinary WordPress comments table has no extra store column')
finally:
    for url in restore:
        req(url)
    for logout in logouts:
        req(logout)
    if logouts: checks.append('Only temporary per-domain acceptance sessions were logged out')
report={'version':'0.3.5','passed':len(checks),'checks':checks}
(reports/'http-native-reviews.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
