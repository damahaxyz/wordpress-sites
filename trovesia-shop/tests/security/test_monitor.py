"""Scheduling and failure/recovery tests; no Docker, mail or production state access."""
import contextlib
import importlib.util
import io
import json
import pathlib
import tempfile
import time
import types
import unittest
import urllib.error
from unittest import mock

spec = importlib.util.spec_from_file_location('trovesia_watch', pathlib.Path(__file__).resolve().parents[2] / 'scripts/security-watch.py')
watch = importlib.util.module_from_spec(spec)
spec.loader.exec_module(watch)


class SchedulingTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.project = pathlib.Path(self.temporary.name) / 'trovesia-shop'
        self.reports = self.project / 'security-reports'
        self.reports.mkdir(parents=True)
        self.state = self.reports / 'monitor-state.json'
        self.now = time.time()
        self.commands = []
        self.full_return = 0
        self.events = ''
        self.state.write_text(json.dumps({'healthy': True, 'baseline_healthy': True, 'last_full_check': self.now - 60}))
        for patch in [mock.patch.object(watch, 'project', self.project), mock.patch.object(watch, 'state_file', self.state),
                      mock.patch.object(watch.time, 'time', return_value=self.now)]:
            patch.start()
            self.addCleanup(patch.stop)
        self.light = mock.patch.object(watch, 'light_check', return_value=True).start()
        self.notify = mock.patch.object(watch, 'notify', return_value=True).start()
        mock.patch.object(watch, 'command', side_effect=self.command).start()
        self.addCleanup(mock.patch.stopall)

    def command(self, args, **kwargs):
        self.commands.append(args)
        if args[0] == 'bash':
            return types.SimpleNamespace(returncode=self.full_return, stdout='', stderr='')
        if args[:4] == ['docker', 'compose', 'ps', '-q']:
            return types.SimpleNamespace(returncode=0, stdout='fake-container', stderr='')
        if args[:2] == ['docker', 'logs']:
            return types.SimpleNamespace(returncode=0, stdout=self.events, stderr='')
        raise AssertionError('Unexpected external command')

    def cycle(self):
        with contextlib.redirect_stdout(io.StringIO()):
            watch.cycle()
        return json.loads(self.state.read_text())

    def full_runs(self):
        return sum(args[0] == 'bash' for args in self.commands)

    def test_recent_success_skips_full_scan_but_checks_health_and_auth(self):
        state = self.cycle()
        self.assertTrue(state['healthy'])
        self.assertEqual(self.full_runs(), 0)
        self.light.assert_called_once()
        self.assertTrue(any(args[:2] == ['docker', 'logs'] for args in self.commands))
        self.notify.assert_not_called()

    def test_hourly_scan_runs(self):
        self.state.write_text(json.dumps({'healthy': True, 'baseline_healthy': True, 'last_full_check': self.now - 3601}))
        state = self.cycle()
        self.assertEqual(self.full_runs(), 1)
        self.assertEqual(state['last_full_check'], self.now)
        self.assertTrue(state['healthy'])

    def test_first_cycle_establishes_full_baseline(self):
        self.state.unlink()
        state = self.cycle()
        self.assertEqual(self.full_runs(), 1)
        self.assertTrue(state['healthy'])

    def test_failed_full_check_retries_next_cycle_and_reports_recovery(self):
        self.state.write_text(json.dumps({'healthy': True, 'baseline_healthy': True, 'last_full_check': self.now - 3601}))
        self.full_return = 1
        self.assertFalse(self.cycle()['healthy'])
        self.full_return = 0
        self.assertTrue(self.cycle()['healthy'])
        self.assertEqual(self.full_runs(), 2)
        self.assertEqual(self.notify.call_args_list, [mock.call('failure'), mock.call('recovery')])

    def test_light_failure_notifies_once_then_recovers(self):
        self.light.return_value = False
        self.assertFalse(self.cycle()['healthy'])
        self.assertFalse(self.cycle()['healthy'])
        self.light.return_value = True
        self.assertTrue(self.cycle()['healthy'])
        self.assertEqual(self.full_runs(), 0)
        self.assertEqual(self.notify.call_args_list, [mock.call('failure'), mock.call('recovery')])

    def test_deployment_defers_checks(self):
        marker = self.reports / 'deployment-in-progress'
        marker.touch()
        original = self.state.read_text()
        self.cycle()
        self.assertEqual(self.state.read_text(), original)
        self.assertEqual(self.commands, [])
        self.light.assert_not_called()

    def test_auth_alert_and_deduplication_preserved(self):
        self.events = '[trovesia-security] ' + json.dumps({'event': 'role_changed', 'result': 'privileged_change'})
        self.cycle()
        self.cycle()
        self.notify.assert_called_once_with('authentication')


class LightHealthTests(unittest.TestCase):
    def services(self, unhealthy=None):
        return [{'Service': name, 'State': 'running', 'Health': 'unhealthy' if name == unhealthy else 'healthy'}
                for name in ('wordpress', 'nginx', 'db', 'redis')]

    def check(self, rows, body=b'<html>Trovesia</html>', status=200):
        response = mock.MagicMock()
        response.__enter__.return_value.status = status
        response.__enter__.return_value.read.return_value = body
        opener = mock.Mock()
        opener.open.return_value = response
        with mock.patch.object(watch, 'command', return_value=types.SimpleNamespace(returncode=0, stdout=rows)), \
             mock.patch.object(watch.urllib.request, 'build_opener', return_value=opener):
            result = watch.light_check()
        return result, opener

    def test_healthy_json_lines_and_array_supported(self):
        for rows in [json.dumps(self.services()), '\n'.join(json.dumps(row) for row in self.services())]:
            result, opener = self.check(rows)
            self.assertTrue(result)
            request = opener.open.call_args.args[0]
            self.assertTrue(request.full_url.startswith('http://127.0.0.1:8083/?trovesia-health='))

    def test_unhealthy_or_missing_service_fails_without_http(self):
        for services in [self.services('db'), self.services()[:-1]]:
            result, opener = self.check(json.dumps(services))
            self.assertFalse(result)
            opener.open.assert_not_called()

    def test_invalid_docker_response_fails(self):
        for rows in ['not json', '["invalid"]', '']:
            self.assertFalse(self.check(rows)[0])

    def test_wrong_html_or_status_fails(self):
        for body, status in [(b'Trovesia but incomplete', 200), (b'<html>Another site</html>', 200), (b'<html>Trovesia</html>', 503)]:
            self.assertFalse(self.check(json.dumps(self.services()), body, status)[0])

    def test_http_failure_is_unhealthy(self):
        opener = mock.Mock()
        opener.open.side_effect = urllib.error.URLError('unavailable')
        with mock.patch.object(watch, 'command', return_value=types.SimpleNamespace(returncode=0, stdout=json.dumps(self.services()))), \
             mock.patch.object(watch.urllib.request, 'build_opener', return_value=opener):
            self.assertFalse(watch.light_check())


if __name__ == '__main__':
    unittest.main()
