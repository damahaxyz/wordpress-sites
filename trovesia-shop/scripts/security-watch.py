"""Five-minute health/auth monitoring; full integrity checks hourly and on deployment."""
import datetime as dt
import fcntl
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import time
import urllib.error
import urllib.request

project = pathlib.Path(__file__).resolve().parent.parent
state_file = project / 'security-reports/monitor-state.json'
CHECK_INTERVAL = 300
FULL_CHECK_INTERVAL = 3600

def command(args, timeout=180):
    return subprocess.run(args, text=True, capture_output=True, timeout=timeout)

def notify(kind):
    try:
        result = command(['docker', 'compose', '--profile', 'tools', 'run', '--rm', '-T',
                          '-v', str(project / 'security') + ':/security:ro', 'wpcli',
                          'eval-file', '/security/send-alert.php', kind])
    except subprocess.TimeoutExpired:
        print('mail=' + kind + ' accepted=False (timeout)', flush=True)
        return False
    print('mail=' + kind + ' accepted=' + str(result.returncode == 0), flush=True)
    return result.returncode == 0

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, url):
        return None

def light_check():
    """Use existing Docker health results plus one uncached HTTP request; no WP-CLI."""
    try:
        result = command(['docker', 'compose', 'ps', '--format', 'json'], timeout=20)
        if result.returncode != 0:
            return False
        raw = result.stdout.strip()
        rows = json.loads(raw) if raw.startswith('[') else [json.loads(line) for line in raw.splitlines() if line.strip()]
        if not isinstance(rows, list) or any(not isinstance(row, dict) for row in rows):
            return False
        services = {row.get('Service'): row for row in rows}
        for service in ('wordpress', 'nginx', 'db', 'redis'):
            row = services.get(service, {})
            if row.get('State') != 'running' or row.get('Health') != 'healthy':
                return False
        request = urllib.request.Request(
            'http://127.0.0.1:8083/?trovesia-health=' + str(int(time.time())),
            headers={'Host': 'www.trovesia.com', 'X-Forwarded-Proto': 'https'})
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
        with opener.open(request, timeout=15) as response:
            body = response.read(2 * 1024 * 1024)
            return response.status == 200 and b'</html>' in body.lower() and b'Trovesia' in body
    except (OSError, ValueError, TypeError, subprocess.TimeoutExpired):
        return False

def cycle():
    now = dt.datetime.now(dt.timezone.utc)
    state = json.loads(state_file.read_text()) if state_file.exists() else {}
    marker = project / 'security-reports/deployment-in-progress'
    if marker.exists() and time.time() - marker.stat().st_mtime < 600:
        print('Scheduled deployment in progress; defer monitor check.', flush=True)
        return
    healthy = light_check()
    # Retry a failed integrity check next cycle; a successful check is reused for
    # at most one hour. Deployment pre/post checks still run unconditionally.
    full_due = not state.get('baseline_healthy', False) or time.time() - state.get('last_full_check', 0) >= FULL_CHECK_INTERVAL
    if full_due:
        try:
            result = command(['bash', 'scripts/security-baseline.sh', 'check'])
            state['baseline_healthy'] = result.returncode == 0
        except subprocess.TimeoutExpired:
            state['baseline_healthy'] = False
        state['last_full_check'] = time.time()
    healthy = healthy and state.get('baseline_healthy', False)
    print('healthy=' + str(healthy) + ' full_check=' + ('run' if full_due else 'cached'), flush=True)
    previous = state.get('healthy')
    pending = state.get('pending')
    if not healthy and previous is not False:
        pending = 'failure'
    elif healthy and previous is False:
        pending = 'recovery'
    # Keep retrying an undelivered transition; don't repeatedly mail unchanged state.
    if pending and notify(pending):
        pending = None
    since = state.get('last_scan', (now - dt.timedelta(minutes=5)).isoformat())
    container = command(['docker', 'compose', 'ps', '-q', 'wordpress'])
    events = command(['docker', 'logs', '--since', since, '--tail', '3000', container.stdout.strip()], timeout=30)
    new_security_event = False
    seen = set(state.get('seen', []))
    for line in (events.stdout + events.stderr).splitlines():
        if '[trovesia-security] ' not in line:
            continue
        raw = line.split('[trovesia-security] ', 1)[1]
        fingerprint = hashlib.sha256(raw.encode()).hexdigest()
        if fingerprint in seen:
            continue
        seen.add(fingerprint)
        try:
            # FPM may append a worker-log quote after the JSON record.
            event, _ = json.JSONDecoder().raw_decode(raw.lstrip())
        except ValueError:
            continue
        privileged_role_change = event.get('event') == 'role_changed' and event.get('result') == 'privileged_change'
        if privileged_role_change or event.get('event') in ('admin_login_blocked', 'plugin_activated', 'plugin_deactivated', 'code_upload_denied') or event.get('reason') in ('trovesia_login_throttled', 'trovesia_admin_secure_login'):
            new_security_event = True
    auth_pending = state.get('auth_pending', False) or new_security_event
    last_auth = state.get('last_auth_alert', 0)
    if auth_pending and time.time() - last_auth >= 1800 and notify('authentication'):
        auth_pending = False
        last_auth = time.time()
    state.update(healthy=healthy, pending=pending, auth_pending=auth_pending,
                 last_auth_alert=last_auth, last_scan=now.isoformat(), seen=list(seen)[-3000:])
    temporary = state_file.with_suffix('.tmp')
    temporary.write_text(json.dumps(state))
    temporary.chmod(0o600)
    temporary.replace(state_file)

def main():
    if os.geteuid() != 0 or project.name != 'trovesia-shop':
        raise SystemExit('Server root operator required')
    os.chdir(project)
    os.umask(0o077)
    if '--test-email' in sys.argv:
        raise SystemExit(0 if notify('test') else 1)
    while True:
        try:
            # A manual --once check must not race the resident monitor's state.
            with state_file.with_suffix('.lock').open('a') as lock:
                try:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError:
                    print('Another monitor cycle is running; defer this cycle.', flush=True)
                else:
                    cycle()
        except Exception as error:
            # Captured output may contain private logs; only report the error type.
            print('Monitor cycle failed: ' + type(error).__name__ + '; will retry.', flush=True)
        if '--once' in sys.argv:
            break
        time.sleep(CHECK_INTERVAL)

if __name__ == '__main__':
    main()
