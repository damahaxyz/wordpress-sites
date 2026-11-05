#!/usr/bin/env python3
"""Read-only availability checks, restricted to the seven existing loopback brands."""
import json,socket
from pathlib import Path
from urllib.request import build_opener,ProxyHandler,HTTPRedirectHandler
from urllib.parse import urlsplit
root=Path.cwd()
assert root==Path('/private/tmp/group-shop-manager-qa')
env=(root/'.qa.env').read_text()
assert 'GSM_QA_DOMAIN=www.main.local' in env and 'GSM_QA_MULTISITE=0' in env
hosts={'www.main.local','www.branda.local','www.brandb.local','www.brandc.local','www.brande.local','www.brandf.local','www.brandg.local'}
for host in hosts:assert socket.gethostbyname(host)=='127.0.0.1'
def allowed(url):
    p=urlsplit(url);assert p.scheme=='http' and p.hostname in hosts and p.port in (None,80)
class Redirect(HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):
        allowed(newurl);return super().redirect_request(req,fp,code,msg,headers,newurl)
op=build_opener(ProxyHandler({}),Redirect());checks=[]
for host in sorted(hosts):
    for path in ['/','/my-account/']:
        url='http://'+host+path;allowed(url)
        with op.open(url,timeout=30) as r:status=r.status;body=r.read().decode()
        assert status==200 and 'Fatal error' not in body and 'critical error' not in body
        if path=='/my-account/':assert 'woocommerce-form-login' in body
        checks.append({'host':host,'path':path,'status':status})
report={'version':'0.5.0','passed':len(checks),'checks':checks}
(Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v050-local/availability.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
