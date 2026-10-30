"""Root-only application DB password rotation. Never prints or transmits secrets off-host."""
import datetime
import json
import os
import pathlib
import re
import secrets
import shutil
import subprocess

project = pathlib.Path(__file__).resolve().parent.parent
if os.geteuid() != 0 or project.name != 'trovesia-shop':
    raise SystemExit('Run from the managed server project as root')
os.chdir(project)
os.umask(0o077)

def run(args, data=None):
    result = subprocess.run(args, input=data, text=True, capture_output=True)
    if result.returncode:
        # SQL/config diagnostics could include credentials: keep them out of command output.
        raise RuntimeError('Command failed: ' + ' '.join(args[:3]) + '; consult protected maintenance evidence')
    return result.stdout

def sql(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "''") + "'"

config = json.loads(run(['docker', 'compose', 'config', '--format', 'json']))
environment = config['services']['wordpress']['environment']
username = environment['WORDPRESS_DB_USER']
old_password = environment['WORDPRESS_DB_PASSWORD']
if not re.fullmatch(r'[A-Za-z0-9_-]+', username):
    raise SystemExit('Unexpected DB username format; review manually')
env_file = project / '.env'
original = env_file.read_text()
if len(re.findall(r'^MARIADB_PASSWORD\s*=', original, re.M)) != 1:
    raise SystemExit('Expected exactly one MARIADB_PASSWORD entry; review manually')
mysql = ['docker', 'compose', 'exec', '-T', 'db', 'sh', '-c',
         'export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; exec mariadb -uroot --batch --skip-column-names']
hosts = run(mysql, 'SELECT Host FROM mysql.user WHERE User=' + sql(username) + ';\n').splitlines()
if not hosts or any(not re.fullmatch(r'[A-Za-z0-9_.:%-]+', host) for host in hosts):
    raise SystemExit('Unexpected DB host grants; review manually')
stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
backup = project / 'security-reports' / ('env-before-db-rotation-' + stamp)
shutil.copyfile(env_file, backup)
backup.chmod(0o600)
new_password = secrets.token_hex(32)
changed = False
try:
    run(mysql, ''.join('ALTER USER ' + sql(username) + '@' + sql(host) + ' IDENTIFIED BY ' + sql(new_password) + ';\n' for host in hosts))
    changed = True
    updated = re.sub(r'^MARIADB_PASSWORD\s*=.*$', 'MARIADB_PASSWORD=' + new_password, original, flags=re.M)
    temporary = project / '.env.rotation.tmp'
    with temporary.open('x') as stream:
        stream.write(updated)
    temporary.chmod(0o600)
    temporary.replace(env_file)
    run(['docker', 'compose', 'config', '--quiet'])
    # Recreate only this project's DB and web containers; persistent volumes remain intact.
    run(['docker', 'compose', 'up', '-d', '--force-recreate', '--pull', 'never', '--wait', '--wait-timeout', '180', 'db', 'wordpress', 'nginx'])
    run(['docker', 'compose', 'exec', '-T', 'wordpress', 'php', '/opt/trovesia/fpm-healthcheck.php'])
    print('Application database password rotated; DB/web health verified. Old env snapshot is root-private.')
except Exception:
    if changed:
        run(mysql, ''.join('ALTER USER ' + sql(username) + '@' + sql(host) + ' IDENTIFIED BY ' + sql(old_password) + ';\n' for host in hosts))
    env_file.write_text(original)
    env_file.chmod(0o600)
    run(['docker', 'compose', 'up', '-d', '--pull', 'never', '--wait', '--wait-timeout', '180', 'db', 'wordpress', 'nginx'])
    raise SystemExit('Rotation failed; restored previous DB credential/configuration. No secrets printed.')
