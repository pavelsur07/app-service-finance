#!/usr/bin/env python3
"""Release contract: execute the actual matrix Bash; never contact production."""
import fnmatch
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / '.github/workflows/deploy.yml'


class FrontendReleaseTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text()
        section = cls.workflow.split('- name: 🧩 Build image matrix', 1)[1]
        section = section.split('\n    # Dev-образ', 1)[0]
        cls.matrix_script = textwrap.dedent(section.split('              run: |\n', 1)[1]).rstrip()

    def matrix(self, *, flags=(), event='pull_request', action='none', ref='refs/heads/master'):
        script = re.sub(r'\$\{\{\s*steps\.f\.outputs\.([a-z_]+)\s*\}\}',
                        lambda m: 'true' if m[1] in flags else 'false', self.matrix_script)
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / 'output'
            env = dict(os.environ, GITHUB_OUTPUT=str(output), GITHUB_EVENT_NAME=event,
                       GITHUB_REF=ref, PRODUCTION_ACTION=action)
            result = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', script],
                                    env=env, text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
            values = dict(line.split('=', 1) for line in output.read_text().splitlines())
            return values, json.loads(values['matrix'])['include']

    def test_spa_pull_request_builds_only_two_independent_images(self):
        values, matrix = self.matrix(flags=('spa',))
        self.assertEqual(values.get('spa'), 'true')
        self.assertEqual({entry['image'] for entry in matrix}, {'account-nginx', 'admin-nginx'})
        self.assertEqual({entry['frontend_app'] for entry in matrix}, {'account', 'admin'})
        self.assertTrue(all(entry['context'] == '.' for entry in matrix))

    def test_frontend_only_master_and_manual_release_build_complete_sha_set(self):
        for kwargs in [dict(flags=('spa',), event='push'),
                       dict(flags=('backend',), event='push'),
                       dict(event='workflow_dispatch', action='deploy'),
                       dict(event='workflow_dispatch', action='migrations')]:
            with self.subTest(**kwargs):
                values, matrix = self.matrix(**kwargs)
                self.assertEqual(values.get('spa'), 'true')
                self.assertEqual({entry['image'] for entry in matrix}, {
                    'site-nginx', 'site-php-fpm', 'site-php-cli', 'land-nginx',
                    'ozon-fetcher', 'wb-fetcher', 'ai-service', 'account-nginx', 'admin-nginx'})

    def test_unrelated_pr_and_docs_master_remain_selective(self):
        _, backend = self.matrix(flags=('backend',))
        self.assertEqual({entry['image'] for entry in backend}, {'site-nginx', 'site-php-fpm', 'site-php-cli'})
        _, landing = self.matrix(flags=('landing',))
        self.assertEqual({entry['image'] for entry in landing}, {'land-nginx'})
        for kwargs in [dict(), dict(event='push'), dict(event='workflow_dispatch')]:
            with self.subTest(**kwargs):
                values, matrix = self.matrix(**kwargs)
                self.assertEqual(matrix, [])
                self.assertEqual(values.get('spa'), 'false')

    def test_source_filters_cover_apps_shared_packages_styles_and_runtime(self):
        match = re.search(r'^                      spa:\n(.*?)(?=^                      \w+:|^            #)',
                          self.workflow, re.M | re.S)
        self.assertIsNotNone(match, 'SPA path filter is required')
        patterns = re.findall(r"^\s*- '([^']+)'", match[1], re.M)
        for path in ['apps/account/src/main.tsx', 'apps/admin/package-lock.json',
                     'site/packages/ui/src/Button.tsx', 'site/packages/api/src/index.ts',
                     'site/assets/styles/kit/tokens/colors.css', 'docker/frontend/nginx.conf',
                     'bin/frontend-production-test.sh', '.github/workflows/deploy.yml']:
            with self.subTest(path=path):
                self.assertTrue(any(fnmatch.fnmatchcase(path, p) for p in patterns), path)
        # The old React18 application also consumes these extracted packages.
        frontend = re.search(r'^                      frontend:\n(.*?)(?=^                      \w+:)',
                             self.workflow, re.M | re.S)
        self.assertIn("'site/packages/**'", frontend[1])

    def test_static_image_published_only_after_browser_smoke(self):
        build = self.workflow.split('    build-and-push:', 1)[1].split('    schema-ready:', 1)[0]
        self.assertIn("push: ${{ github.event_name != 'pull_request' && matrix.frontend_app == '' }}", build)
        self.assertLess(build.index('Browser smoke of the actual nginx image'),
                        build.index('Publish verified SPA image'))
        self.assertIn('docker push "$IMAGE_SHA"', build)

    def test_browser_failure_blocks_release_and_new_services_wait_for_health(self):
        build = self.workflow.split('    build-and-push:', 1)[1].split('    schema-ready:', 1)[0]
        self.assertIn('spa-checks', build)
        self.assertIn("needs['spa-checks'].result != 'failure'", build)
        self.assertIn('bin/frontend-production-test.sh', build)
        self.assertIn('load:', build)
        self.assertIn('frontend_app', build)
        commands = self.workflow.replace('\\\n', ' ')
        pull = re.search(r'^\s*docker compose -f docker-compose.prod.yml pull land site[^\n]+', commands, re.M)
        self.assertIsNotNone(pull)
        for app in ['account', 'admin']:
            self.assertRegex(pull[0], rf'\b{app}\b')
        up = re.search(r'^\s*docker compose -f docker-compose.prod.yml up -d --no-deps --wait account admin\s*$', commands, re.M)
        self.assertIsNotNone(up, 'Both new static services must pass health before deploy completion')
        self.assertLess(up.start(), commands.index('up -d --remove-orphans'))
        workers = commands.index('up -d --no-deps site-php-cli site-messenger-worker-pipeline')
        self.assertLess(workers, up.start(), 'New placeholders must not interrupt backend/worker rollout')


if __name__ == '__main__':
    unittest.main(verbosity=2)
