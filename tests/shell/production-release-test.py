#!/usr/bin/env python3
"""Execute production sequence locally with Docker/SSH replaced by stubs."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SHA = 'b' * 40
OLD = 'a' * 40


class ProductionTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        (self.root / 'bin').mkdir()
        (self.root / 'shared').mkdir()
        (self.root / 'shared/release-high-water').write_text(OLD + '\n')
        (self.root / 'infrastructure.tsv').write_text(''.join(
            f'{name}\tdocker.io/library/{name}@sha256:{"b" * 64}\n' for name in ['traefik', 'postgres', 'redis']))
        for path in ['services/ozon-fetcher/internal/ozonstore/migrations/00001_init.sql',
                     'services/ozon-fetcher/internal/ozonstore/migrations/00002_ingestion.sql',
                     'services/shared/fetchkit/store/migrations/00001_core.sql',
                     'services/wb-fetcher/internal/app/migrations/00002_running.sql',
                     'services/ai-service/migrations/0001_init.sql',
                     'docker/ozon-fetcher/db-bootstrap.sql', 'docker/wb-fetcher/db-bootstrap.sql',
                     'docker/ai-service/db-bootstrap.sql']:
            p = self.root / path
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text('-- fixture')
        docker = self.root / 'bin/docker'
        docker.write_text('''#!/usr/bin/env python3
import json, os, sys, time
from pathlib import Path
a=sys.argv[1:]
with open(os.environ.get('CALLS', 'calls.jsonl'),'a') as f: f.write(json.dumps(a)+'\\n')
if os.environ.get('FAIL') and os.environ['FAIL'] in ' '.join(a): sys.exit(23)
if 'inspect' in a:
    if os.environ.get('ASSERT_INSPECT_STDIN') and sys.stdin.read(): sys.exit(24)
    if a[0] == 'image':
        name={'symfony-postgres':'postgres', 'site-redis':'redis'}.get(a[-1],a[-1])
        print(name+'@sha256:'+'b'*64)
    elif '{{.Image}}' in a: print(a[-1])
    elif '{{.State.Status}}' in a:
        print('exited' if a[-1] == os.environ.get('EXITED_CONTAINER') else 'running')
    elif '{{.State.Health.Status}}' in a: print('healthy')
    elif '{{range .Config.Env}}{{println .}}{{end}}' in a: print('SENTRY_RELEASE='+'a'*40)
    else: print('current')
elif 'psql' in a:
    if 'goose_db_version' in ' '.join(a): print('1\\n2')
    elif 'schema_migrations' in ' '.join(a): print('0001_init')
    elif '-c' in a: print('app\\npostgres\\nozon_fetcher')
elif 'pg_dump' in ' '.join(a):
    if os.environ.get('SLOW_BACKUP'): time.sleep(.2)
    sys.stdout.buffer.write(b'custom-dump-content')
elif 'pg_restore' in a:
    assert sys.stdin.buffer.read() == b'custom-dump-content'
''')
        docker.chmod(0o755)
        self.env = dict(os.environ, PATH=f'{self.root / "bin"}:{os.environ["PATH"]}',
                        SERVER_DIR=str(self.root), RELEASE_SHA=SHA, EXPECTED_INSTALLED_SHA=OLD,
                        PRODUCTION_ACTION='release', RELEASE_RUN='42-1',
                        RELEASE_BACKUP_ROOT=str(self.root / 'backups'))
        self.env['CALLS'] = str(self.root / 'calls.jsonl')
        for name in ['INTERNAL_AUTH_TOKEN', 'OZON_FETCHER_API_TOKEN', 'OZON_FETCHER_DB_PASSWORD',
                     'OZON_FETCHER_S3_ACCESS_KEY_ID', 'OZON_FETCHER_S3_SECRET_ACCESS_KEY',
                     'WB_FETCHER_API_TOKEN', 'WB_FETCHER_DB_PASSWORD', 'AI_SERVICE_API_TOKEN',
                     'AI_SERVICE_DB_PASSWORD', 'AI_GATEWAY_TOKEN']:
            self.env[name] = 'synthetic-test-' + 'a' * 32

    def command(self):
        return ['bash', '-euo', 'pipefail', '-c',
                f'source "{ROOT}/docker/release/production.sh"; '
                'release_prepare; '
                'if [[ $PRODUCTION_ACTION != migrations ]]; then '
                'release_phase deploy; docker compose -f docker-compose.prod.yml -f compose.digest.json up -d; '
                'release_phase health; docker compose -f docker-compose.prod.yml -f compose.digest.json ps; fi; '
                'release_finish']

    def execute(self, **extra):
        result = subprocess.run(self.command(), cwd=self.root, env=dict(self.env, **extra),
                                capture_output=True, text=True)
        calls = self.root / 'calls.jsonl'
        return result, [json.loads(x) for x in calls.read_text().splitlines()] if calls.exists() else []

    def test_backup_migrate_schema_deploy_health_and_closed_backup_permissions(self):
        result, calls = self.execute()
        self.assertEqual(result.returncode, 0, result.stderr)
        flat = [' '.join(c) for c in calls]
        dump = next(i for i, c in enumerate(flat) if 'pg_dump' in c)
        migrate = next(i for i, c in enumerate(flat) if 'doctrine:migrations:migrate' in c)
        schema = next(i for i, c in enumerate(flat) if 'doctrine:migrations:up-to-date' in c)
        deploy = next(i for i, c in enumerate(flat) if ' up ' in c)
        self.assertLess(dump, migrate)
        self.assertLess(migrate, schema)
        self.assertLess(schema, deploy)
        backups = list(self.root.glob('backups/**/app.dump'))
        self.assertEqual(len(backups), 1)
        self.assertEqual(backups[0].stat().st_mode & 0o777, 0o600)
        self.assertEqual(backups[0].parent.stat().st_mode & 0o777, 0o700)
        self.assertEqual(len(list(backups[0].parent.glob('*.dump'))), 3)
        self.assertEqual((self.root / 'shared/release-high-water').read_text().strip(), SHA)

    def test_failed_backup_migration_and_schema_prevent_following_stages(self):
        for index, failure in enumerate(['pg_dump', 'pg_restore', 'doctrine:migrations:migrate', 'doctrine:migrations:up-to-date']):
            with self.subTest(failure=failure):
                self.env['RELEASE_RUN'] = f'42-{index + 1}'
                (self.root / 'calls.jsonl').unlink(missing_ok=True)
                result, calls = self.execute(FAIL=failure)
                self.assertEqual(result.returncode, 23, result.stderr)
                self.assertFalse(any('up' in c for c in calls))
                if failure in ('pg_dump', 'pg_restore'):
                    self.assertFalse(any('doctrine:migrations:migrate' in c for c in calls))
                self.env['EXPECTED_INSTALLED_SHA'] = (self.root / 'shared/release-high-water').read_text().strip()

    def test_push_checks_schema_without_running_migrations_or_backup(self):
        result, calls = self.execute(PRODUCTION_ACTION='deploy')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(any('doctrine:migrations:migrate' in c or 'pg_dump' in ' '.join(c) for c in calls))
        self.assertTrue(any('doctrine:migrations:up-to-date' in c for c in calls))

    def test_changed_installed_version_is_rejected_before_mutation(self):
        result, calls = self.execute(EXPECTED_INSTALLED_SHA='c' * 40)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(any('pg_dump' in ' '.join(c) or 'run' in c for c in calls))
        self.assertEqual((self.root / 'shared/release-high-water').read_text().strip(), OLD)

    def test_push_rejects_pending_service_schema_without_migrating(self):
        result, calls = self.execute(PRODUCTION_ACTION='deploy', FAIL='goose_db_version')
        self.assertEqual(result.returncode, 23, result.stderr)
        self.assertFalse(any('up' in c or '-migrate' in c for c in calls))

    def test_service_migration_failure_prevents_rolling_update(self):
        result, calls = self.execute(FAIL='-migrate')
        self.assertEqual(result.returncode, 23, result.stderr)
        self.assertFalse(any('up' in c for c in calls))

    def test_same_version_retry_is_allowed_and_standalone_migrations_never_deploy(self):
        (self.root / 'shared/release-high-water').write_text(SHA + '\n')
        result, calls = self.execute(EXPECTED_INSTALLED_SHA=SHA, PRODUCTION_ACTION='migrations')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(any('up' in c for c in calls))
        self.assertTrue(any('doctrine:migrations:migrate' in c for c in calls))
        self.assertFalse((self.root / 'shared/release-last-success').exists())

    def test_concurrent_sequences_do_not_interleave(self):
        (self.root / 'shared/release-high-water').write_text(SHA + '\n')
        env = dict(self.env, EXPECTED_INSTALLED_SHA=SHA, SLOW_BACKUP='1')
        first = subprocess.Popen(self.command(), cwd=self.root, env=env,
                                 stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        second = subprocess.Popen(self.command(), cwd=self.root, env=dict(env, RELEASE_RUN='43-1'),
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            self.assertEqual(first.wait(timeout=10), 0)
            self.assertEqual(second.wait(timeout=10), 0)
            calls = [json.loads(x) for x in (self.root / 'calls.jsonl').read_text().splitlines()]
            migrations = [i for i, c in enumerate(calls) if 'doctrine:migrations:migrate' in c]
            ups = [i for i, c in enumerate(calls) if 'up' in c]
            self.assertEqual(len(migrations), 2)
            self.assertLess(migrations[0], ups[0])
            self.assertLess(ups[0], migrations[1])
        finally:
            for process in (first, second):
                if process.poll() is None:
                    process.kill()
                    process.wait()


if __name__ == '__main__':
    unittest.main()
