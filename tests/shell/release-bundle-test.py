#!/usr/bin/env python3
"""Release resolver at GitHub/registry process boundaries; no network."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
SHA = 'a' * 40
IMAGES = ['site-nginx', 'site-php-fpm', 'site-php-cli', 'land-nginx',
          'ozon-fetcher', 'wb-fetcher', 'ai-service', 'account-nginx', 'admin-nginx',
          'traefik', 'postgres', 'redis']
GATES = ['🧪 Unit tests', '🎨 PHP code style', '🔬 Static analysis',
         '🧪 Integration tests 1/3', '🧪 Integration tests 2/3', '🧪 Integration tests 3/3',
         '🧪 Functional tests 1/3', '🧪 Functional tests 2/3',
         '🧪 Functional tests 3/3', '🐹 Go gates (shared/fetchkit)',
         '🐹 Go gates (ozon-fetcher)', '🐹 Go gates (wb-fetcher)',
         '🐍 ai-service gates', 'SPA checks (account)', 'SPA checks (admin)',
         'SPA real backend and HTTPS isolation (dev)',
         'SPA real backend and HTTPS isolation (preview)',
         'SPA real backend and HTTPS isolation (production)', 'migrations-empty-db', '🔍 Check API types sync']


def fixture_manifest():
    images = {name: f'ghcr.io/pavelsur07/{name}@sha256:{"b" * 64}' for name in IMAGES[:9]}
    for name in IMAGES[9:]:
        images[name] = f'docker.io/library/{name}@sha256:{"b" * 64}'
    return dict(schema=1, sha=SHA, repository='pavelsur07/app-service-finance',
                workflow_id=12, run_id=73, run_attempt=1, images=images,
                build_attempts={name: 1 for name in IMAGES[:9]})


class BundleTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.manifest = fixture_manifest()
        self.run = dict(id=73, workflow_id=12, head_sha=SHA, head_branch='master',
                        event='push', run_attempt=1,
                        path='.github/workflows/deploy.yml',
                        head_repository={'full_name': 'pavelsur07/app-service-finance'})
        self.jobs = [dict(name=n, conclusion='success', status='completed')
                     for n in GATES + [f'Build image ({n})' for n in IMAGES[:9]] + ['Release manifest']]
        self.jobs_by_attempt = {}
        self.stub = '''#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
a=sys.argv[1:]
with open('calls.jsonl','a') as f: f.write(json.dumps([Path(sys.argv[0]).name]+a)+'\\n')
if Path(sys.argv[0]).name == 'gh':
    if os.environ.get('API_ERROR'): sys.exit(1)
    endpoint=a[1]
    data=json.loads(Path('api.json').read_text())
    if endpoint.endswith('/zip'): sys.stdout.buffer.write(Path('artifact.zip').read_bytes())
    elif '/jobs?' in endpoint:
        import re
        attempt=re.search(r'/attempts/(\\d+)/jobs', endpoint)[1]
        print(json.dumps({'jobs':data['jobs_by_attempt'].get(attempt,data['jobs'])}))
    elif '/artifacts?' in endpoint: print(json.dumps({'artifacts': data['artifacts']}))
    elif '/runs?' in endpoint: print(json.dumps({'workflow_runs':data['runs']}))
    elif '/runs/73' in endpoint: print(json.dumps(data['run']))
    else: print(json.dumps({'id':12}))
else:
    if os.environ.get('REGISTRY_ERROR'):
        print('unauthorized', file=sys.stderr); sys.exit(1)
    if os.environ.get('REGISTRY_MISSING'):
        print(('ERROR: '+a[3]+': not found') if os.environ['REGISTRY_MISSING'] == '404' else 'manifest unknown', file=sys.stderr)
        sys.exit(1)
    print(json.dumps({'digest':'sha256:'+'b'*64}))
'''
        for name in ['gh', 'docker']:
            p = self.bin / name
            p.write_text(self.stub)
            p.chmod(0o755)

    def resolve(self, *, missing=False, **extra):
        attempt = self.manifest.get('run_attempt', 1) if isinstance(self.manifest, dict) else 1
        artifact = dict(id=91, name=f'release-{SHA}-{attempt}', expired=False,
                        workflow_run=dict(id=73, head_sha=SHA, head_branch='master'))
        data = dict(run=self.run, runs=[self.run], jobs=self.jobs, jobs_by_attempt=self.jobs_by_attempt,
                    artifacts=[] if missing else [artifact])
        (self.root / 'api.json').write_text(json.dumps(data))
        with zipfile.ZipFile(self.root / 'artifact.zip', 'w') as archive:
            archive.writestr('manifest.json', json.dumps(self.manifest))
        env = dict(os.environ, PATH=f'{self.bin}:{os.environ["PATH"]}', GITHUB_SHA=SHA,
                   GITHUB_REF='refs/heads/master', GITHUB_EVENT_NAME='workflow_dispatch',
                   GITHUB_REPOSITORY='pavelsur07/app-service-finance', RELEASE_SHA=SHA,
                   PRODUCTION_ACTION='release', GITHUB_OUTPUT=str(self.root / 'outputs'), **extra)
        result = subprocess.run(['python3', str(ROOT / 'site/bin/release-bundle.py'),
                                 'resolve', '--directory', str(self.root / 'bundle')],
                                cwd=self.root, env=env, capture_output=True, text=True)
        outputs = (self.root / 'outputs').read_text() if (self.root / 'outputs').exists() else ''
        return result, outputs

    def test_trusted_complete_bundle_is_reused(self):
        result, outputs = self.resolve()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('reuse=true', outputs)
        self.assertEqual(json.loads((self.root / 'bundle/manifest.json').read_text()), self.manifest)

    def test_missing_untrusted_or_incomplete_bundle_requires_build(self):
        for mutation in ['missing', 'branch', 'workflow', 'gate', 'image']:
            with self.subTest(mutation=mutation):
                self.run.update(head_branch='master', workflow_id=12)
                self.jobs[0]['conclusion'] = 'success'
                self.manifest = fixture_manifest()
                if mutation == 'branch': self.run['head_branch'] = 'feature'
                if mutation == 'workflow': self.run['workflow_id'] = 999
                if mutation == 'gate': self.jobs[0]['conclusion'] = 'failure'
                if mutation == 'image': self.manifest['images'].pop('site-nginx')
                result, outputs = self.resolve(missing=mutation == 'missing')
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn('reuse=false', outputs)
                (self.root / 'outputs').unlink()

    def test_registry_access_error_is_not_treated_as_cache_miss(self):
        result, outputs = self.resolve(REGISTRY_ERROR='1')
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('reuse=false', outputs)

    def test_api_access_error_is_not_treated_as_cache_miss(self):
        result, outputs = self.resolve(API_ERROR='1')
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('reuse=false', outputs)

    def test_malformed_untrusted_manifest_requires_new_gates_and_build(self):
        self.manifest = ['not a manifest']
        result, outputs = self.resolve()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('reuse=false', outputs)

    def test_partial_rerun_trusts_old_builds_but_only_latest_gate_results(self):
        self.manifest['run_attempt'] = 2
        self.run['run_attempt'] = 2
        original = [dict(job) for job in self.jobs]
        original[0]['conclusion'] = 'failure'
        original[-1]['conclusion'] = 'skipped'
        self.jobs_by_attempt = {'1': original, '2': [dict(self.jobs[0]), dict(self.jobs[-1])]}
        result, outputs = self.resolve()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('reuse=true', outputs)
        (self.root / 'outputs').unlink()
        self.jobs_by_attempt['2'][0]['conclusion'] = 'failure'
        result, outputs = self.resolve()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('reuse=false', outputs)

    def test_confirmed_missing_digest_requires_fresh_build(self):
        result, outputs = self.resolve(REGISTRY_MISSING='1')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('reuse=false', outputs)

    def test_registry_manifest_not_found_requires_fresh_build(self):
        result, outputs = self.resolve(REGISTRY_MISSING='404')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('reuse=false', outputs)

    def test_foreign_or_short_sha_never_contacts_production_or_registry(self):
        for sha in ['c' * 40, 'a' * 7, '']:
            with self.subTest(sha=sha):
                # RELEASE_SHA is part of the boundary, not an inferred branch HEAD.
                result, _ = self.resolve_override(sha)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse((self.root / 'calls.jsonl').exists())

    def resolve_override(self, sha):
        # Same entrypoint, override the externally supplied dispatch SHA.
        env = dict(os.environ, PATH=f'{self.bin}:{os.environ["PATH"]}', GITHUB_SHA=SHA,
                   GITHUB_REF='refs/heads/master', GITHUB_EVENT_NAME='workflow_dispatch',
                   RELEASE_SHA=sha, PRODUCTION_ACTION='release')
        return subprocess.run(['python3', str(ROOT / 'site/bin/release-bundle.py'), 'resolve',
                               '--directory', str(self.root / 'bundle')],
                              cwd=self.root, env=env, capture_output=True, text=True), ''

    def test_override_covers_every_production_service_by_digest(self):
        result, _ = self.resolve()
        self.assertEqual(result.returncode, 0, result.stderr)
        compose = json.loads((self.root / 'bundle/compose.digest.json').read_text())
        self.assertEqual(len(compose['services']), 18)
        for value in compose['services'].values():
            self.assertRegex(value['image'], r'@sha256:[0-9a-f]{64}$')
        self.assertEqual(compose['services']['scheduler']['image'],
                         compose['services']['site-php-cli']['image'])

    def test_publication_binds_each_digest_to_current_build_not_a_mutable_tag(self):
        directory = self.root / 'bundle'
        (directory / 'descriptors').mkdir(parents=True)
        (directory / 'infrastructure.tsv').write_text(''.join(
            f'{name}\t{fixture_manifest()["images"][name]}\n' for name in IMAGES[9:]))
        for image in IMAGES[:9]:
            descriptor = dict(sha=SHA, run_id=73, run_attempt=1,
                              image=image, digest='sha256:' + 'b' * 64)
            if image == 'site-nginx': descriptor['sha'] = 'c' * 40
            (directory / 'descriptors' / f'{image}.json').write_text(json.dumps(descriptor))
        (self.root / 'api.json').write_text(json.dumps(dict(runs=[], artifacts=[], run=self.run, jobs=[])))
        needs = {job: {'result': 'success'} for job in ['unit-tests', 'cs-check', 'stan',
                 'backend-tests', 'services-go', 'services-python', 'spa-checks',
                 'spa-auth-e2e', 'migrations-empty-db', 'api-types-check', 'build-and-push']}
        env = dict(os.environ, PATH=f'{self.bin}:{os.environ["PATH"]}', GITHUB_SHA=SHA,
                   GITHUB_REF='refs/heads/master', GITHUB_EVENT_NAME='push',
                   GITHUB_REPOSITORY='pavelsur07/app-service-finance', GITHUB_RUN_ID='73',
                   GITHUB_RUN_ATTEMPT='1', NEEDS_JSON=json.dumps(needs))
        result = subprocess.run(['python3', str(ROOT / 'site/bin/release-bundle.py'), 'publish',
                                 '--directory', str(directory)], cwd=self.root, env=env,
                                capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0, 'foreign descriptor was accepted')
        self.assertFalse((directory / 'manifest.json').exists())
        descriptor = json.loads((directory / 'descriptors/site-nginx.json').read_text())
        descriptor['sha'] = SHA
        (directory / 'descriptors/site-nginx.json').write_text(json.dumps(descriptor))
        result = subprocess.run(['python3', str(ROOT / 'site/bin/release-bundle.py'), 'publish',
                                 '--directory', str(directory)], cwd=self.root, env=env,
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads((directory / 'manifest.json').read_text()), fixture_manifest())
        for path in list((directory / 'descriptors').glob('*.json')):
            nested = directory / 'descriptors' / f'descriptor-{path.stem}-1'
            nested.mkdir()
            path.rename(nested / path.name)
        env['GITHUB_RUN_ATTEMPT'] = '2'
        result = subprocess.run(['python3', str(ROOT / 'site/bin/release-bundle.py'), 'publish',
                                 '--directory', str(directory)], cwd=self.root, env=env,
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        retried = json.loads((directory / 'manifest.json').read_text())
        self.assertEqual(retried['run_attempt'], 2)
        self.assertEqual(set(retried['build_attempts'].values()), {1})
        nested = directory / 'descriptors/descriptor-site-nginx-2'
        nested.mkdir()
        descriptor['run_attempt'] = 2
        (nested / 'site-nginx.json').write_text(json.dumps(descriptor))
        env['GITHUB_RUN_ATTEMPT'] = '3'
        result = subprocess.run(['python3', str(ROOT / 'site/bin/release-bundle.py'), 'publish',
                                 '--directory', str(directory)], cwd=self.root, env=env,
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        retried = json.loads((directory / 'manifest.json').read_text())
        self.assertEqual(retried['build_attempts']['site-nginx'], 2)
        self.assertEqual(retried['build_attempts']['site-php-fpm'], 1)
        calls = [json.loads(line) for line in (self.root / 'calls.jsonl').read_text().splitlines()]
        self.assertTrue(all('@sha256:' in c[4] for c in calls if c[0] == 'docker'))

    def test_older_and_unrelated_versions_are_rejected_by_real_git_ancestry(self):
        subprocess.run(['git', 'init', '-q'], cwd=self.root, check=True)
        subprocess.run(['git', 'config', 'user.name', 'Release test'], cwd=self.root, check=True)
        subprocess.run(['git', 'config', 'user.email', 'release-test@example.invalid'], cwd=self.root, check=True)
        commits = []
        for index in range(2):
            subprocess.run(['git', 'commit', '-qm', str(index), '--allow-empty'], cwd=self.root, check=True)
            commits.append(subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=self.root, text=True).strip())
        for installed, target, expected in [(commits[1], commits[0], 1),
                                             (commits[0], commits[1], 0), (commits[1], commits[1], 0)]:
            env = dict(os.environ, GITHUB_SHA=target, GITHUB_EVENT_NAME='push')
            result = subprocess.run(['python3', str(ROOT / 'site/bin/release-bundle.py'), 'ancestry',
                                     '--directory', str(self.root / 'bundle'), '--installed', installed],
                                    cwd=self.root, env=env, capture_output=True, text=True)
            self.assertEqual(result.returncode, expected, result.stderr)


if __name__ == '__main__':
    unittest.main()
