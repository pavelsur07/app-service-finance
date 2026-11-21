#!/usr/bin/env python3
"""Run the actual production SSH/heredoc locally, through a fake SSH process."""
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[2]
MCP_KEY_NAMES = ('MCP_OAUTH_PRIVATE_KEY', 'MCP_OAUTH_PUBLIC_KEY', 'MCP_OAUTH_ENCRYPTION_KEY')
MCP_SETTINGS = {
    'MCP_OAUTH_ORIGIN': 'https://app.vashfindir.ru',
    'MCP_OAUTH_CLIENTS': '{"codex-cli":["http://127.0.0.1:48765/callback"],"claude-code":["http://localhost:48766/callback"]}',
}
spec = importlib.util.spec_from_file_location('production_fixture', Path(__file__).with_name('production-release-test.py'))
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


class WorkflowTest(unittest.TestCase):
    def setUp(self):
        self.fixture = fixture.ProductionTest()
        self.fixture.setUp()
        self.addCleanup(self.fixture.doCleanups)
        self.root = self.fixture.root
        self.env = self.fixture.env
        shutil.copytree(ROOT / 'docker/release', self.root / 'docker/release')
        (self.root / 'current').mkdir()
        (self.root / 'current/old-config').touch()
        (self.root / 'docker-compose.prod.yml').write_text('services: {}\n')
        (self.root / 'compose.digest.json').write_text('{"services":{}}')
        (self.root / 'manifest.json').write_text(json.dumps({'sha': fixture.SHA}))
        archive = self.root / f'release-{fixture.SHA}-42-1.tar.gz'
        with tarfile.open(archive, 'w:gz') as bundle:
            for name in ['docker', 'services', 'docker-compose.prod.yml', 'compose.digest.json', 'manifest.json', 'infrastructure.tsv']:
                bundle.add(self.root / name, arcname=name)
        ssh = self.root / 'bin/ssh'
        ssh.write_text('''#!/usr/bin/env python3
import json, os, subprocess, sys
from pathlib import Path
script = sys.stdin.read()
Path('ssh-args.json').write_text(json.dumps(sys.argv[1:]))
remote_env = {name: value for name, value in os.environ.items() if not name.startswith('MCP_OAUTH_')}
sys.exit(subprocess.run(['bash', '-euo', 'pipefail', '-c', script], env=remote_env).returncode)
''')
        ssh.chmod(0o755)

        docker = self.root / 'bin/docker'
        docker.rename(self.root / 'bin/docker-stub')
        docker.write_text('''#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
args = sys.argv[1:]
snapshot = os.environ.get('OAUTH_TEST_SNAPSHOT')
if snapshot and args[:1] == ['compose'] and 'up' in args and args[-1:] == ['site-php-fpm']:
    settings = {name: value for name, value in os.environ.items() if name.startswith('MCP_OAUTH_')}
    Path(snapshot).write_text(json.dumps(settings))
os.execv(Path(__file__).with_name('docker-stub'), sys.argv)
''')
        docker.chmod(0o755)

    def test_helpers_execute_when_sent_to_remote_bash_stdin(self):
        with (ROOT / 'docker/release/installed-sha.sh').open('rb') as source:
            result = subprocess.run(['bash', '-s', '--', str(self.root)], stdin=source,
                                    env=self.env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), fixture.OLD)
        with (ROOT / 'docker/release/infrastructure-digests.sh').open('rb') as source:
            result = subprocess.run(['bash', '-s'], stdin=source, env=self.env,
                                    capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, (self.root / 'infrastructure.tsv').read_text())

    def test_installed_sha_bootstrap_does_not_consume_remote_script_stdin(self):
        (self.root / 'shared/release-high-water').unlink()
        script = (f'source "{ROOT}/docker/release/installed-sha.sh"; installed_sha "$1"; '
                  'IFS= read -r marker; printf "%s\\n" "$marker"')
        result = subprocess.run(['bash', '-euo', 'pipefail', '-c', script, 'bash', str(self.root)],
                                input='caller input\n', env=dict(self.env, ASSERT_INSPECT_STDIN='1'),
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.splitlines(), [fixture.OLD, 'caller input'])

    def run_workflow(self, action='release', event='workflow_dispatch', **extra):
        text = (ROOT / '.github/workflows/deploy.yml').read_text()
        section = text.split('- name: Backup, migrate, verify schema, deploy and check health under one lock', 1)[1]
        header, script = section.split('              run: |\n', 1)
        script = textwrap.dedent(script.split('\n    # 5.', 1)[0])
        key_values = {name: extra.pop(name, '') for name in MCP_KEY_NAMES}
        env = {'SERVER_USER': 'deploy', 'SERVER_HOST': 'local-ssh-stub'}
        for name, value in re.findall(r'^ +([A-Z_]+): (.*)$', header, re.M):
            if name in MCP_SETTINGS:
                env[name] = value.strip("'")
            elif name in MCP_KEY_NAMES:
                source = re.fullmatch(r'\$\{\{\s*secrets\.([A-Z_]+)\s*\}\}', value)
                self.assertIsNotNone(source, f'{name} must come from GitHub Secrets')
                env[name] = key_values.get(source[1], '')
        values = {'secrets.SERVER_DIR': str(self.root), 'github.sha': fixture.SHA,
                  'github.run_id': '42', 'github.run_attempt': '1', 'github.event_name': event,
                  'steps.installed.outputs.sha': fixture.OLD, 'inputs.production_action': action,
                  'vars.AI_SERVICE_EVAL_ENABLED': 'false'}
        values['vars.AI_SERVICE_EVAL_ENABLED'] = extra.pop('EVAL_ENABLED', 'false')
        env.update(extra)
        script = re.sub(r'\$\{\{\s*(.*?)\s*\}\}',
                        lambda m: values.get(m[1], 'synthetic-test-' + 'a' * 32), script)
        result = subprocess.run(['bash', '-euo', 'pipefail', '-c', script], cwd=self.root,
                                env=dict(self.env, **env), text=True, capture_output=True)
        log = self.root / 'calls.jsonl'
        calls = [json.loads(s) for s in log.read_text().splitlines()] if log.exists() else []
        return result, calls

    def test_actual_ssh_release_uses_digest_override_and_preserves_rolling_order(self):
        result, calls = self.run_workflow()
        self.assertEqual(result.returncode, 0, result.stderr)
        compose = [c for c in calls if c[:1] == ['compose']]
        self.assertTrue(compose)
        self.assertTrue(all('compose.digest.json' in c for c in compose))
        starts = [c for c in compose if 'up' in c]
        self.assertEqual(starts[0][-1], 'site-messenger-worker-sync')
        self.assertEqual(starts[1][-1], 'site-php-fpm')
        migrations = [i for i, c in enumerate(calls) if 'run' in c and ('-migrate' in c or 'migrate' in c)]
        first_up = next(i for i, c in enumerate(calls) if 'up' in c)
        self.assertTrue(all(i < first_up for i in migrations))
        self.assertTrue((self.root / 'current/compose.digest.json').exists())
        self.assertFalse((self.root / 'previous-config-42-1').exists())
        self.assertFalse((self.root / f'release-{fixture.SHA}-42-1.tar.gz').exists())
        self.assertIn(['image', 'prune', '-af', '--filter', 'until=336h'], calls)
        self.assertIn('health\t', (self.root / 'current/release-timings.tsv').read_text())

    def test_actual_ssh_backup_failure_preserves_current_configuration(self):
        result, calls = self.run_workflow(FAIL='pg_dump')
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue((self.root / 'current/old-config').exists())
        self.assertFalse(any('up' in c for c in calls))

    def test_enabled_nightly_worker_must_be_running_before_release_success(self):
        result, _ = self.run_workflow(EVAL_ENABLED='true', EXITED_CONTAINER='ai-service-eval-worker')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.root / 'shared/release-last-success').exists())

    def test_actual_push_never_runs_any_migration(self):
        result, calls = self.run_workflow(action='', event='push')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(any('-migrate' in c or 'migrate' in c or 'doctrine:migrations:migrate' in c for c in calls))

    def test_mcp_settings_survive_ssh_for_php_fpm_without_secret_output(self):
        marker = self.root / 'shell-injection'
        keys = dict(zip(MCP_KEY_NAMES, (
            f"synthetic-private\nEOF\n'$(touch {marker})'\n",
            'synthetic-public\nsecond line\n',
            'synthetic-encryption',
        )))
        snapshot = self.root / 'oauth-env.json'
        result, _ = self.run_workflow(action='', event='push', OAUTH_TEST_SNAPSHOT=str(snapshot), **keys)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(marker.exists())
        self.assertEqual(json.loads(snapshot.read_text()), MCP_SETTINGS | keys)
        ssh_args = json.loads((self.root / 'ssh-args.json').read_text())
        self.assertIn('StrictHostKeyChecking=yes', ssh_args)
        self.assertIn('HostKeyAlias=app-finance-production', ssh_args)
        self.assertIn('UserKnownHostsFile=docker/release/known_hosts', ssh_args)
        pinned_key = (self.root / 'docker/release/known_hosts').read_text().strip()
        self.assertRegex(pinned_key, r'^app-finance-production ssh-ed25519 [A-Za-z0-9+/=]+$')
        for value in keys.values():
            self.assertNotIn(value.splitlines()[0], result.stdout + result.stderr)
            self.assertNotIn(value.splitlines()[0], ' '.join(ssh_args))

    def test_partial_mcp_key_set_is_rejected_before_ssh(self):
        result, calls = self.run_workflow(action='', event='push', MCP_OAUTH_PRIVATE_KEY='synthetic-private')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('MCP_OAUTH_PUBLIC_KEY', result.stderr)
        self.assertNotIn('synthetic-private', result.stdout + result.stderr)
        self.assertEqual(calls, [])
        self.assertFalse((self.root / 'ssh-args.json').exists())
        self.assertTrue((self.root / 'current/old-config').exists())

    def test_compose_passes_mcp_environment_only_to_php_fpm(self):
        compose = (ROOT / 'docker-compose.prod.yml').read_text()
        service = compose.split('\n    site-php-fpm:\n', 1)[1].split('\n    site-php-cli:\n', 1)[0]
        for name in (*MCP_SETTINGS, *MCP_KEY_NAMES):
            with self.subTest(setting=name):
                declaration = f'${{{name}:-}}'
                self.assertEqual(compose.count(declaration), 1)
                self.assertIn(f'{name}: {declaration}', service)

    def test_only_one_job_acquires_the_production_concurrency_group(self):
        workflow = (ROOT / '.github/workflows/deploy.yml').read_text()
        self.assertEqual(workflow.count('group: production-release'), 1)
        production = workflow.split('    production:\n', 1)[1].split('        steps:', 1)[0]
        self.assertIn('cancel-in-progress: false', production)

    def test_release_uploads_include_hidden_files_with_narrow_paths(self):
        workflow = (ROOT / '.github/workflows/deploy.yml').read_text()
        uploads = re.findall(r'            - uses: actions/upload-artifact@v4\n(.*?)(?=^            -|^    \S|\Z)',
                             workflow, re.M | re.S)
        self.assertEqual(len(uploads), 4)
        for upload in uploads:
            with self.subTest(upload=upload):
                self.assertRegex(upload, r'(?m)^                  include-hidden-files: true$')
                paths = re.search(r'^                  path: (.*?)(?=^                  \w[\w-]*:|\Z)',
                                  upload, re.M | re.S)[1]
                paths = [line.strip() for line in paths.splitlines() if line.strip() != '|']
                self.assertIn(paths, [['.descriptor/*.json'],
                                     ['.release/manifest.json', '.release/compose.digest.json',
                                      '.release/infrastructure.tsv']])

    def test_disabled_healthchecks_are_never_passed_to_wait_and_infrastructure_is_never_recreated(self):
        result, calls = self.run_workflow()
        self.assertEqual(result.returncode, 0, result.stderr)
        compose = (ROOT / 'docker-compose.prod.yml').read_text()
        blocks = re.findall(r'^    ([a-z][a-z0-9-]+):\n(.*?)(?=^    [a-z][a-z0-9-]+:|\Z)', compose, re.M | re.S)
        disabled = {name for name, block in blocks if re.search(r'healthcheck:\s*\n\s*disable: true', block)}
        self.assertTrue(disabled)
        for call in calls:
            if call[:1] == ['compose'] and 'up' in call:
                if '--wait' in call:
                    self.assertFalse(disabled.intersection(call), call)
                self.assertIn('--no-deps', call)
                self.assertFalse({'traefik', 'site-postgres', 'site-redis'}.intersection(call), call)


if __name__ == '__main__':
    unittest.main()
