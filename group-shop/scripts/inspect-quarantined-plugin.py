"""Static triage only: never imports or executes quarantined PHP/JS."""
import collections
import hashlib
import json
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1]).resolve()
if not root.is_dir() or '/quarantine/' not in str(root):
    raise SystemExit('Expected an existing root-private quarantine directory')
patterns = {
    'execution': r'\b(?:eval|assert|shell_exec|exec|system|passthru|proc_open|popen|pcntl_exec|create_function)\s*\(',
    'encoding': r'\b(?:base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(',
    'dynamic_call': r'\$[A-Za-z_]\w*\s*\(',
    'process_payload': r'Phl4nk|Maintenance Tools|wp-3hppkwvobt|wp-4jtash8wf0|/sbin/syslogd|/usr/sbin/sshd',
    'anonymous_hook': r'wp_ajax_nopriv|register_rest_route',
}
findings = []
domains = collections.Counter()
extensions = collections.Counter()
manifest = []
for file in sorted(root.rglob('*')):
    if file.is_symlink():
        findings.append({'file': str(file.relative_to(root)), 'kind': 'symlink'})
        continue
    if not file.is_file():
        continue
    data = file.read_bytes()
    relative = str(file.relative_to(root))
    manifest.append({'file': relative, 'sha256': hashlib.sha256(data).hexdigest(), 'size': len(data)})
    extensions[file.suffix.lower()] += 1
    if file.suffix.lower() not in ('.php', '.js'):
        continue
    text = data.decode('utf-8', errors='replace')
    for number, line in enumerate(text.splitlines(), 1):
        for kind, pattern in patterns.items():
            if re.search(pattern, line, re.I):
                findings.append({'file': relative, 'line': number, 'kind': kind})
    for domain in re.findall(r'https?://([A-Za-z0-9.-]+)', text):
        domains[domain.lower()] += 1
print(json.dumps({'root_name': root.name, 'file_count': len(manifest), 'extensions': dict(extensions),
                  'findings': findings, 'domains': domains.most_common(40), 'manifest': manifest}, indent=2))
