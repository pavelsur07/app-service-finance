#!/usr/bin/env python3
"""Opt-in rehearsal: real temporary PostgreSQL, real dump/read/checksum, no external APIs."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import uuid

ROOT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(os.environ.get('RELEASE_TEST_POSTGRES_IMAGE'), 'opt-in temporary PostgreSQL')
class PostgresRehearsal(unittest.TestCase):
    def test_full_archive_read_and_checksum_do_not_restore_or_modify_database(self):
        with tempfile.TemporaryDirectory(prefix='vf-release-postgres-') as temporary:
            root = Path(temporary)
            name = 'vf-release-pg-' + uuid.uuid4().hex[:12]
            compose = root / 'compose.json'
            compose.write_text(json.dumps({'services': {'postgres': {
                'image': os.environ['RELEASE_TEST_POSTGRES_IMAGE'], 'container_name': name,
                'network_mode': 'none', 'tmpfs': ['/var/lib/postgresql/data'],
                'environment': {'POSTGRES_USER': 'app', 'POSTGRES_PASSWORD': 'local-rehearsal-only', 'POSTGRES_DB': 'app'},
                'healthcheck': {'test': ['CMD', 'pg_isready', '-U', 'app', '-d', 'app'],
                                'interval': '1s', 'timeout': '1s', 'retries': 30}}}}))
            command = ['docker', 'compose', '-p', name, '-f', str(compose)]
            try:
                subprocess.run(command + ['up', '-d', '--wait', '--wait-timeout', '60'], check=True,
                               stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
                docker = shutil.which('docker')
                subprocess.run([docker, 'exec', name, 'psql', '-U', 'app', '-d', 'app',
                                '-v', 'ON_ERROR_STOP=1', '-c',
                                'CREATE TABLE rehearsal(id integer PRIMARY KEY); INSERT INTO rehearsal VALUES (17);'],
                               check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
                (root / 'bin').mkdir()
                wrapper = root / 'bin/docker'
                wrapper.write_text('''#!/usr/bin/env python3
import os, sys
a=[os.environ['REHEARSAL_PG'] if v == 'symfony-postgres' else v for v in sys.argv[1:]]
os.execv(os.environ['REAL_DOCKER'], [os.environ['REAL_DOCKER']] + a)
''')
                wrapper.chmod(0o755)
                env = dict(os.environ, PATH=f'{root / "bin"}:{os.environ["PATH"]}',
                           REHEARSAL_PG=name, REAL_DOCKER=docker, RELEASE_SHA='a' * 40,
                           RELEASE_RUN='42-1', RELEASE_BACKUP_ROOT=str(root / 'backups'))
                result = subprocess.run(['bash', '-euo', 'pipefail', '-c',
                                         f'source "{ROOT}/docker/release/production.sh"; release_backup'],
                                        cwd=root, env=env, capture_output=True, text=True)
                self.assertEqual(result.returncode, 0, result.stderr)
                files = list(root.glob('backups/**/*.dump'))
                self.assertEqual({p.name for p in files}, {'app.dump', 'postgres.dump'})
                for file in files:
                    self.assertTrue(file.read_bytes().startswith(b'PGDMP'))
                    subprocess.run(['sha256sum', '--check', str(file) + '.sha256'],
                                   check=True, stdout=subprocess.DEVNULL)
                count = subprocess.check_output([docker, 'exec', name, 'psql', '-U', 'app', '-d', 'app',
                                                 '-At', '-c', 'SELECT id FROM rehearsal'], text=True)
                self.assertEqual(count.strip(), '17')
                corrupted = subprocess.run([docker, 'exec', '-i', name, 'pg_restore', '--file=/dev/null'],
                                           input=b'corrupted archive', capture_output=True)
                self.assertNotEqual(corrupted.returncode, 0)
            finally:
                subprocess.run(command + ['down', '--volumes'], check=True,
                               stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)


if __name__ == '__main__':
    unittest.main()
