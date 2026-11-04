"""Read-only incident correlation. Run on the server; never print raw IPs or query payloads."""
import collections
import datetime as dt
import glob
import gzip
import hashlib
import json
import re
import sys
from urllib.parse import parse_qs, urlsplit

TZ = dt.timezone(dt.timedelta(hours=8))
SHELLS = ('wp-3hppkwvobt', 'wp-4jtash8wf0')
ACCESS = re.compile(r'^(\S+) .*?\[([^\]]+)\] "([A-Z]+) (\S+) [^"]+" (\d{3}) (\S+) "([^"]*)" "([^"]*)"(?: "([^"]*)")?')
KNOWN_ACTORS = set()

def records(path, filtered=False):
    opener = gzip.open if path.endswith('.gz') else open
    with opener(path, 'rt', errors='replace') as stream:
        for line in stream:
            if filtered and not any(token in line for token in (*SHELLS, '/wp-admin/', '/wp-login.php', '/xmlrpc.php', '/wp-json/wp/v2/')) and not any(ip in line for ip in KNOWN_ACTORS):
                continue
            match = ACCESS.search(line)
            if not match:
                continue
            ip, timestamp, method, uri, status, size, referer, ua, forwarded = match.groups()
            try:
                time = dt.datetime.strptime(timestamp, '%d/%b/%Y:%H:%M:%S %z').astimezone(TZ)
                url = urlsplit(uri)
            except ValueError:
                continue
            if ip.startswith(('172.', '127.')) and forwarded:
                ip = forwarded.split(',')[0].strip()
            yield dict(ip=ip, time=time.isoformat(), method=method, path=url.path,
                       query=parse_qs(url.query, keep_blank_values=True), status=int(status),
                       referer=referer, ua=ua[:180], source=path.rsplit('/', 1)[-1])

def public(row):
    query = row['query']
    result = {k: row[k] for k in ('time', 'method', 'path', 'status', 'source')}
    result['actor'] = hashlib.sha256(row['ip'].encode()).hexdigest()[:12]
    result['query_keys'] = sorted(query)
    for key in ('action', 'task', 'r'):
        value = query.get(key, [''])[0]
        if re.fullmatch(r'[A-Za-z0-9_-]{1,60}', value):
            result[key] = value
    result['trovesia_referer'] = 'trovesia.com' in row['referer']
    result['ua'] = row['ua']
    return result

evidence = '/root/trovesia-incident-20261029/evidence/nginx.log'
known = list(records(evidence))
shell_rows = [r for r in known if any(s in r['path'] for s in SHELLS)]
actors = {r['ip'] for r in shell_rows if r['status'] in (200, 499, 504)}
KNOWN_ACTORS.update(actors)
summary = {'evidence_coverage': [known[0]['time'], known[-1]['time']] if known else [],
           'actor_count': len(actors), 'shell_events': [public(r) for r in shell_rows]}
host_shells = []
actor_sensitive = []
uploads = []
counts = collections.Counter()
paths = sys.argv[1:] or sorted(glob.glob('/var/log/nginx/access.log*'))
for path in paths:
    for row in records(path, filtered=True):
        is_shell = any(s in row['path'] for s in SHELLS)
        if is_shell:
            host_shells.append(public(row))
            if row['status'] in (200, 499, 504):
                actors.add(row['ip'])
                KNOWN_ACTORS.add(row['ip'])
        sensitive = row['path'].startswith(('/wp-login.php', '/xmlrpc.php', '/wp-admin/', '/wp-json/wp/v2/users', '/wp-json/wp/v2/plugins'))
        if row['ip'] in actors and sensitive:
            actor_sensitive.append(public(row))
        if row['method'] == 'POST' and row['path'] in ('/wp-admin/update.php', '/wp-admin/async-upload.php', '/wp-admin/plugin-install.php'):
            uploads.append(public(row))
        if row['ip'] in actors:
            counts[(hashlib.sha256(row['ip'].encode()).hexdigest()[:12], row['path'], row['status'])] += 1
summary['host_shell_events'] = host_shells[:100]
summary['actor_sensitive_events'] = actor_sensitive[:160]
summary['plugin_upload_events'] = uploads[:100]
summary['actor_top_paths'] = [{'actor': a, 'path': p, 'status': s, 'count': c} for (a,p,s),c in counts.most_common(35)]
print(json.dumps(summary, ensure_ascii=False, indent=2))
