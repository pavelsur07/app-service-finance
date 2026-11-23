#!/usr/bin/env python3
"""Execute acceptance with Docker replaced at the process boundary."""
import ast
import json
import os
import re
from pathlib import Path
import shutil
import signal
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]


class AcceptanceTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        (self.root / 'bin').mkdir()
        (self.root / 'site/bin').mkdir(parents=True)
        (self.root / 'source.php').write_text('original')
        subprocess.run(['git', 'init', '-q', str(self.root)], check=True)
        (self.root / '.gitignore').write_text('/site/var/\n/commands.jsonl\n')
        self.docker = self.root / 'bin/docker'
        self.docker.write_text('''#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
a = sys.argv[1:]
if os.environ.get('FAIL_IF_STDIN') and sys.stdin.read(): sys.exit(19)
with open('commands.jsonl', 'a') as f:
    f.write(json.dumps(a) + '\\n')
if os.environ.get('MUTATE') and 'test:unit' in a:
    Path('source.php').write_text('changed')
if os.environ.get('FAIL') and os.environ['FAIL'] in ' '.join(a):
    sys.exit(17)
if a[:1] == ['info']:
    print('6442450944')
if os.environ.get('DELAY_CREATE') and 'run' in a:
    import subprocess
    subprocess.Popen([sys.executable, '-c', "import time; from pathlib import Path; time.sleep(.5); Path('created').touch()"],
                     start_new_session=True, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    Path('creating').touch()
    import time
    time.sleep(1)
if a[:2] == ['rm', '-f']:
    if os.environ.get('DELAY_CLEANUP'):
        Path('cleaning').touch()
        import time
        time.sleep(.5)
    if os.environ.get('DELAY_CREATE') and not Path('created').exists():
        sys.exit(1)
    Path('created').unlink(missing_ok=True)
if 'resource-snapshot' in ' '.join(a):
    print('{"cgroup_limit_bytes": 6442450944, "cgroup_current_bytes": 1234}')
''')
        self.docker.chmod(0o755)
        # A copy runs against an isolated repository, including real git fingerprinting.
        self.runner = self.root / 'site/bin/site-accept.py'
        if (ROOT / 'site/bin/site-accept.py').exists():
            shutil.copy(ROOT / 'site/bin/site-accept.py', self.runner)

    def run_accept(self, **extra):
        env = dict(os.environ, PATH=f'{self.root / "bin"}:{os.environ["PATH"]}', **extra)
        result = subprocess.run(['python3', str(self.runner)], cwd=self.root,
                                env=env, input='caller input', text=True, capture_output=True)
        log = self.root / 'commands.jsonl'
        commands = [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []
        summaries = list(self.root.glob('site/var/accept/*/summary.json'))
        summary = json.loads(summaries[0].read_text()) if summaries else {}
        return result, commands, summary

    def test_suites_run_sequentially_in_separate_processes_and_one_container(self):
        result, commands, summary = self.run_accept()
        self.assertEqual(result.returncode, 0, result.stderr)
        runs = [c for c in commands if 'run' in c]
        self.assertEqual(len(runs), 1)
        self.assertIn('XDEBUG_MODE=off', runs[0])
        self.assertIn('COMPOSER_PROCESS_TIMEOUT=0', runs[0])
        suites = [c for c in commands if any(x.startswith('test:') for x in c)]
        self.assertEqual([c[-1] for c in suites], ['test:unit', 'test:integration', 'test:functional'])
        self.assertTrue(all(c[0] == 'exec' for c in suites))
        phases = [c[-1] for c in commands if 'composer' in c]
        self.assertEqual(phases, ['cs:check', 'cs:strict-types', 'analyse',
                                  'test:unit', 'test:integration', 'test:functional'])
        self.assertEqual(sum('doctrine:migrations:migrate' in c for c in commands), 1)
        self.assertEqual(sum('cache:warmup' in c for c in commands), 1)
        self.assertEqual(commands[-1][:2], ['rm', '-f'])
        self.assertTrue(summary['valid'])
        self.assertEqual(summary['exit_code'], 0)
        events = list(self.root.glob('site/var/accept/*/commands.jsonl'))
        records = [json.loads(s) for s in events[0].read_text().splitlines()]
        self.assertTrue(all('duration_seconds' in r and 'exit_code' in r for r in records))

    def test_first_failed_gate_stops_without_retry_and_cleans_container(self):
        result, commands, summary = self.run_accept(FAIL='cs:strict-types')
        self.assertEqual(result.returncode, 17, result.stderr)
        self.assertFalse(any('test:unit' in c for c in commands))
        self.assertEqual(sum('cs:strict-types' in c for c in commands), 1)
        self.assertEqual(commands[-1][:2], ['rm', '-f'])
        self.assertEqual(summary['exit_code'], 17)

    def test_changed_source_invalidates_otherwise_green_checks(self):
        result, commands, summary = self.run_accept(MUTATE='1')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(summary.get('valid', True))
        self.assertNotEqual(summary['source_start'], summary['source_end'])
        self.assertEqual(commands[-1][:2], ['rm', '-f'])

    def test_failed_preflight_never_starts_long_checks(self):
        for failure in ['info', 'pg_isready', 'vendor/autoload.php']:
            with self.subTest(failure=failure):
                result, commands, _ = self.run_accept(FAIL=failure)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(any('cs:check' in c for c in commands))
                log = self.root / 'commands.jsonl'
                log.unlink(missing_ok=True)

    def test_signal_during_daemon_create_leaves_no_container(self):
        self.signal_during_create(False)

    def test_terminal_interrupt_during_daemon_create_leaves_no_container(self):
        self.signal_during_create(True)

    def test_second_signal_during_cleanup_still_cleans_and_writes_summary(self):
        self.signal_during_create(True, double=True)

    def test_commands_cannot_consume_callers_stdin(self):
        result, _, _ = self.run_accept(FAIL_IF_STDIN='1')
        self.assertEqual(result.returncode, 0, result.stderr)

    def signal_during_create(self, terminal, double=False):
        env = dict(os.environ, PATH=f'{self.root / "bin"}:{os.environ["PATH"]}', DELAY_CREATE='1',
                   DELAY_CLEANUP='1' if double else '')
        process = subprocess.Popen(['python3', str(self.runner)], cwd=self.root, env=env,
                                   start_new_session=True,
                                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            deadline = time.monotonic() + 5
            while not (self.root / 'creating').exists() and time.monotonic() < deadline:
                time.sleep(.01)
            self.assertTrue((self.root / 'creating').exists())
            if terminal:
                os.killpg(process.pid, signal.SIGINT)
            else:
                process.send_signal(signal.SIGTERM)
            if double:
                deadline = time.monotonic() + 5
                while not (self.root / 'cleaning').exists() and time.monotonic() < deadline:
                    time.sleep(.01)
                self.assertTrue((self.root / 'cleaning').exists())
                os.killpg(process.pid, signal.SIGINT)
            self.assertNotEqual(process.wait(timeout=5), 0)
            time.sleep(.6)
            self.assertFalse((self.root / 'created').exists(), 'daemon created container after cleanup')
            self.assertTrue(list(self.root.glob('site/var/accept/*/summary.json')))
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()

    @unittest.skipUnless(os.environ.get('SITE_ACCEPT_TEST_IMAGE'), 'opt-in real PHP preflight in Docker')
    def test_phpstan_local_configuration_does_not_spawn_a_second_analyser(self):
        command = ['docker', 'run', '--rm', '--network', 'none', '-e', 'XDEBUG_MODE=off',
                   '-v', f'{ROOT / "site"}:/app', '-w', '/app', os.environ['SITE_ACCEPT_TEST_IMAGE']]
        result = subprocess.run(command + ['php', 'vendor/bin/phpstan', 'dump-parameters',
                                '--configuration=phpstan.local.neon', '--memory-limit=2G', '--json'],
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        # This PHPStan version's full dump may contain non-JSON escaped class names.
        parallel = json.loads(re.search(r'"parallel"\s*:\s*(\{[^}]+\})', result.stdout)[1])
        script = '''
            $root = 'phar:///app/vendor/phpstan/phpstan/phpstan.phar/';
            require $root . 'src/Diagnose/DiagnoseExtension.php';
            require $root . 'src/Parallel/Schedule.php';
            require $root . 'src/Parallel/Scheduler.php';
            $scheduler = new PHPStan\\Parallel\\Scheduler((int)$argv[1], (int)$argv[2], 2);
            echo $scheduler->scheduleWork(2, array_map('strval', range(1, 5000)),
                static fn(string $file): int => 1)->getNumberOfProcesses();
        '''
        schedule = subprocess.run(command + ['php', '-r', script, str(parallel['jobSize']),
                                  str(parallel['maximumNumberOfProcesses'])],
                                  capture_output=True, text=True)
        self.assertEqual(schedule.returncode, 0, schedule.stderr)
        self.assertEqual(schedule.stdout, '0', 'one main analyser, no second PHP worker')

    @unittest.skipUnless(os.environ.get('SITE_ACCEPT_TEST_IMAGE'), 'opt-in real PHP preflight in Docker')
    def test_unwritable_cache_is_rejected_even_when_system_tmp_is_writable(self):
        # Run the actual PHP preflight as an unprivileged uid with read-only fixture.
        tree = ast.parse((ROOT / 'site/bin/site-accept.py').read_text())
        call = next(n for n in ast.walk(tree) if isinstance(n, ast.Call)
                    and isinstance(n.func, ast.Attribute) and n.func.attr == 'cli'
                    and n.args and isinstance(n.args[0], ast.Constant)
                    and n.args[0].value == 'preflight')
        script = ast.literal_eval(call.args[3])
        self.root.chmod(0o755)
        for name in ['vendor/autoload.php', 'vendor/bin/phpstan', 'vendor/bin/php-cs-fixer',
                     'vendor/phpunit/phpunit/phpunit', '.env.test']:
            file = self.root / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_text('')
        for directory in ['var/cache', 'var/log', 'var/test']:
            (self.root / directory).mkdir(parents=True)
        result = subprocess.run(['docker', 'run', '--rm', '--read-only', '--user', '65534:65534',
                                 '--tmpfs', '/tmp:mode=1777', '-v', f'{self.root}:/fixture:ro',
                                 '-w', '/fixture', os.environ['SITE_ACCEPT_TEST_IMAGE'],
                                 'php', '-r', script], capture_output=True, text=True)
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
