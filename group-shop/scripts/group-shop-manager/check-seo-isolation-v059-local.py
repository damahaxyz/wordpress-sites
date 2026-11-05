#!/usr/bin/env python3
"""Real anonymous HTTP SEO checks against exact loopback hosts; no business writes."""
import http.client,json,re,sys,xml.etree.ElementTree as ET
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlsplit,urlencode

ROOT=Path('/private/tmp/group-shop-manager-qa')
assert Path.cwd()==ROOT,'Run from private local QA directory'
assert 'GSM_QA_DOMAIN=www.main.local' in (ROOT/'.qa.env').read_text()
assert 'GSM_QA_PORT=18087' in (ROOT/'.qa.env').read_text()
f=json.loads((ROOT/'.identity-private/seo-isolation-v059.json').read_text())
REPORT=Path(__file__).resolve().parents[2]/'qa/group-shop-manager/v059-local'
HOSTS={'www.main.local','www.branda.local','www.brandb.local','www.brandc.local','www.brande.local','www.brandf.local','www.brandg.local'}
checks=[];failures=[];requests=[]

def check(ok,label):
    checks.append({'check':label,'passed':bool(ok)})
    if not ok: failures.append(label)

def get(host,path,method='GET',body=None,headers=None):
    assert host in HOSTS and path.startswith('/') and not path.startswith('//')
    c=http.client.HTTPConnection('127.0.0.1',18087,timeout=25)
    h={'Host':host,'User-Agent':'GroupShopSEOAcceptance/0.5.9',**(headers or {})}
    if body is not None:body=json.dumps(body);h['Content-Type']='application/json'
    c.request(method,path,body=body,headers=h);r=c.getresponse();raw=r.read().decode('utf-8','replace');heads={k.lower():v for k,v in r.getheaders()};s=r.status;c.close()
    requests.append({'host':host,'path':path,'method':method,'status':s,'location':heads.get('location')})
    try:data=json.loads(raw)
    except ValueError:data=None
    if len(requests)%30==0: print('Checked',len(requests),'HTTP requests;',len(failures),'failures',flush=True)
    return s,heads,raw,data

class Page(HTMLParser):
    def __init__(self,raw):super().__init__();self.links=[];self.canon=[];self.feed(raw)
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='a' and a.get('href'):self.links.append(a['href'])
        if tag=='link' and a.get('rel')=='canonical':self.canon.append(a.get('href'))

def path(key):
    p=urlsplit(f['urls'][key]);return p.path+('?' + p.query if p.query else '')

def local_links(raw,host,label):
    p=Page(raw)
    bad=[u for u in p.links if urlsplit(u).hostname in HOSTS and urlsplit(u).hostname!=host and '/wp-content/' not in urlsplit(u).path]
    check(not bad,label+' business anchors stay on host')
    check(all(urlsplit(u).hostname==host for u in p.canon),label+' canonical stays on host')
    for block in re.findall(r'<script[^>]*type=[\'"]application/ld\+json[\'"][^>]*>(.*?)</script>',raw,re.S|re.I):
        # Image URL exceptions are intentional; inspect business URL fields only.
        try:obj=json.loads(block)
        except ValueError:check(False,label+' valid JSON-LD');continue
        def walk(v):
            if isinstance(v,dict):
                for k,x in v.items():
                    if k in ('url','@id') and isinstance(x,str) and '/wp-content/' not in urlsplit(x).path:
                        check(urlsplit(x).hostname not in HOSTS or urlsplit(x).hostname==host,label+' JSON-LD '+k+' stays on host')
                    elif k not in ('image','logo','thumbnailUrl'):walk(x)
            elif isinstance(v,list):
                for x in v:walk(x)
        walk(obj)

for key,host,foreign in [('a','www.branda.local','b'),('b','www.brandb.local','a')]:
    other=f['marker_b'] if key=='a' else f['marker_a'];own=f['marker_a'] if key=='a' else f['marker_b'];expected=2 if key=='a' else 3
    for resource in [key,'v'+key,'page_'+key,'post_'+key,'shared','shared_child','shared_tag','collection_'+key]:
        s,h,raw,j=get(host,path(resource));label=key+' '+resource
        check(s==200,label+' returns 200');check(other not in raw,label+' excludes foreign content');local_links(raw,host,label)
        if resource in [key,'page_'+key,'post_'+key]:check(own in raw,label+' serves own content')
    # Same public path across brands must select the host's own native record.
    s,h,raw,j=get(host,path(foreign));check(s==200 and own in raw and other not in raw,key+' same-slug product resolves locally')
    s,h,raw,j=get(host,path('page_'+foreign));check(s==200 and own in raw and other not in raw,key+' same-slug page resolves locally')
    s,h,raw,j=get(host,'/qa059-'+f['suffix']+'-old-'+key+'/');check(s==301 and urlsplit(h.get('location','')).hostname==host,key+' valid own historical slug keeps native redirect')
    s,h,raw,j=get(host,'/qa059-'+f['suffix']+'-old-'+foreign+'/');check(s==404 and not h.get('location'),key+' foreign historical slug does not redirect')
    if key=='a':
        s,h,raw,j=get(host,path('page_a'));check(f['urls']['private_page_b'] not in raw,key+' legacy foreign rich-text anchor and iframe are removed')
    for query in [f'/?p={f[foreign]}',f'/?p={f[foreign]}&post_type=product',f'/?page_id={f["page_"+foreign]}',f'/?p={f["post_"+foreign]}',f'/?p={f["base"]}&post_type=product',f'/?page_id={f["draft_b"]}' if key=='a' else '/?page_id=999999999']:
        s,h,raw,j=get(host,query);check(s==404 and not h.get('location') and other not in raw,key+' rejects foreign/base/missing ID '+query);local_links(raw,host,key+' rejected ID')
    if key=='a':
        for resource in ['private_b','private_page_b']:
            for suffix in ['','?gsm_brand=7']:
                s,h,raw,j=get(host,path(resource)+suffix);check(s==404 and other not in raw and not h.get('location'),'A rejects B-only path '+resource+suffix)
    for endpoint in [f'/wp-json/wc/store/v1/products/{f[foreign]}',f'/wp-json/wc/store/v1/products/{f["base"]}',f'/wp-json/wp/v2/pages/{f["page_"+foreign]}',f'/wp-json/wp/v2/posts/{f["post_"+foreign]}',f'/wp-json/wc/store/v1/products/categories/{f["collection_"+foreign]}']:
        s,h,raw,j=get(host,endpoint);check(s==404 and other not in raw,key+' REST direct ID '+endpoint)
    s,h,raw,j=get(host,'/wp-json/wp/v2/comments/'+str(f['comments'][1 if key=='a' else 0]));check(s==404 and other not in raw,key+' REST foreign comment ID')
    for slug_key in [foreign,'v'+foreign,'v'+foreign+'_variation','base_variation']:
        s,h,raw,j=get(host,'/wp-json/wc/store/v1/products/'+f['native_slugs'][slug_key]);check(s==404 and other not in raw,key+' REST refuses foreign/base native slug '+slug_key)
    for slug_key in [key,'v'+key,'v'+key+'_variation']:
        s,h,raw,j=get(host,'/wp-json/wc/store/v1/products/'+f['native_slugs'][slug_key]);check(s==200 and isinstance(j,dict) and j.get('id')==f[slug_key],key+' REST native slug resolves own product '+slug_key)
    s,h,raw,j=get(host,'/wp-json/wc/store/v1/products/'+urlsplit(f['urls'][key]).path.strip('/').split('/')[-1]);check(s==200 and j.get('id')==f[key],key+' REST public slug resolves own edition')
    for endpoint in [path('collection_'+foreign),'/?'+urlencode({'taxonomy':'product_cat','term':f['slugs']['collection_'+foreign]}),'/wp-sitemap-users-1.xml']:
        s,h,raw,j=get(host,endpoint);check(s==404 and not h.get('location') and other not in raw,key+' rejects foreign collection/user sitemap '+endpoint)
    for endpoint in ['/wp-json/wc/store/v1/products?per_page=100',f'/wp-json/wc/store/v1/products?include={f[key]},{f[foreign]}','/wp-json/wp/v2/pages?per_page=100&_embed=1','/wp-json/wp/v2/posts?per_page=100','/wp-json/wp/v2/search?search=QA059&per_page=100','/wp-json/wc/store/v1/products/reviews?per_page=100']:
        s,h,raw,j=get(host,endpoint);check(s==200 and other not in raw,key+' REST list '+endpoint)
        if endpoint.endswith('products?per_page=100'):check(int(h.get('x-wp-total',-1))==expected,key+' product pagination total')
    for endpoint in ['/wp-json/wc/store/v1/products/categories?per_page=100&hide_empty=false',f'/wp-json/wc/store/v1/products/categories/{f["shared"]}',f'/wp-json/wc/store/v1/products/tags?include={f["shared_tag"]}']:
        s,h,raw,j=get(host,endpoint);rows=j if isinstance(j,list) else [j]
        check(s==200 and isinstance(rows,list),key+' taxonomy response succeeds')
        check(not any(isinstance(x,dict) and x.get('id')==f['collection_'+foreign] for x in rows),key+' taxonomy hides other collection')
        matched=[x for x in rows if isinstance(x,dict) and x.get('id') in (f['shared'],f['shared_tag'])]
        check(bool(matched) and all(x.get('count')==expected for x in matched),key+' taxonomy scoped product count '+endpoint)
        check(all(x.get('review_count',1 if key=='a' else 2)==(1 if key=='a' else 2) for x in matched),key+' taxonomy scoped review count '+endpoint)
    s,h,raw,j=get(host,f'/wp-json/wc/store/v1/products/categories?include={f["collection_"+foreign]}&hide_empty=false');check(s==200 and j==[] and h.get('x-wp-total','0')=='0',key+' foreign category include and total empty')
    aggregate='/wp-json/wc/store/v1/products/collection-data?'+urlencode({'calculate_price_range':'true','calculate_stock_status_counts':'true','calculate_rating_counts':'true','calculate_taxonomy_counts[]':'product_cat'})
    s,h,raw,j=get(host,aggregate);check(s==200 and isinstance(j,dict),key+' collection aggregates succeed')
    if isinstance(j,dict):
        price=j.get('price_range') or {};check(price.get('min_price')==('1100' if key=='a' else '2200') and price.get('max_price')==('2200' if key=='a' else '9900'),key+' price range excludes other store')
        check(sum(int(x['count']) for x in j.get('stock_status_counts',[]))==expected,key+' aggregate stock count')
        check(not any(int(x['term'])==f['collection_'+foreign] for x in j.get('taxonomy_counts',[])),key+' aggregate taxonomy excludes other collection')
    # A then B then A exercises persisted aggregate cache keys across hosts.
    for endpoint in ['/wp-sitemap.xml','/wp-sitemap-posts-product-1.xml','/wp-sitemap-posts-page-1.xml','/wp-sitemap-posts-post-1.xml','/wp-sitemap-taxonomies-product_cat-1.xml','/wp-sitemap-taxonomies-product_tag-1.xml']:
        s,h,raw,j=get(host,endpoint);check(s==200,key+' sitemap available '+endpoint)
        if s==200:
            try:tree=ET.fromstring(raw);urls=[e.text for e in tree.iter() if e.tag.endswith('}loc')]
            except ET.ParseError:urls=[];check(False,key+' sitemap valid XML')
            check(bool(urls) and all(urlsplit(u).hostname==host for u in urls),key+' sitemap locations stay on host '+endpoint)
            check(f['slugs']['collection_'+foreign] not in raw,key+' sitemap hides foreign collection')
            if 'posts-product' in endpoint:check(len(urls)==expected,key+' sitemap contains exact own product count')
    for endpoint in ['/','/feed/','/?s=QA059&post_type=product','/?s=QA059','/?post_type=product','/wp-json/wp/v2/pages?search=QA059','/feed/?post_type=product','/wp-json/wp/v2/comments?per_page=100']:
        s,h,raw,j=get(host,endpoint);check(s==200 and other not in raw,key+' discovery/feed/search '+endpoint)
        if s==200 and '<html' in raw:local_links(raw,host,key+' discovery '+endpoint)
    s,h,raw,j=get(host,'/wp-json/oembed/1.0/embed?'+urlencode({'url':f['urls']['page_'+foreign]}));check(s==404,key+' oEmbed cannot resolve other host page')
    batch={'requests':[{'method':'GET','path':f'/wc/store/v1/products/{f[foreign]}'},{'method':'GET','path':f'/wc/store/v1/products/categories/{f["collection_"+foreign]}'}]}
    for batch_route in ['/wp-json/batch/v1','/wp-json/wc/store/v1/batch']:
        s,h,raw,j=get(host,batch_route,'POST',batch);check(s==400 and isinstance(j,dict) and j.get('code')=='rest_invalid_param',key+' native batch rejects unsupported GET '+batch_route)
    s,h,raw,j=get(host,'/wp-json/wc/store/v1/products',headers={'X-GSM-Brand':'7' if key=='a' else '6'});check(s==403,key+' unsigned brand header rejected')

for host in sorted(HOSTS):
    s,h,raw,j=get(host,'/wp-json/wc/store/v1/products?per_page=100');check(s==200,'Seven-store product API '+host)
    check(not isinstance(j,list) or all(urlsplit(x.get('permalink','')).hostname==host for x in j),'Seven-store product URLs '+host)
    if host not in ('www.branda.local','www.brandb.local'):check(f['marker_a'] not in raw and f['marker_b'] not in raw,'Seven-store fixture separation '+host)

s,h,raw,j=get('www.branda.local','/wp-json/wc/store/v1/products/collection-data?calculate_taxonomy_counts[]=product_cat');check(s==200 and not any(x['term']==f['collection_b'] for x in (j or {}).get('taxonomy_counts',[])),'A aggregate remains isolated after B cache warmup')
REPORT.mkdir(parents=True,exist_ok=True)
result={'version':'0.5.9','passed':sum(c['passed'] for c in checks),'failed':len(failures),'http_requests':len(requests),'failures':failures,'checks':checks,'requests':requests}
(REPORT/'http-seo-isolation.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({k:result[k] for k in ('version','passed','failed','http_requests','failures')},ensure_ascii=False,indent=2))
sys.exit(bool(failures))
