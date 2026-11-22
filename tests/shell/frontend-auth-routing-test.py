#!/usr/bin/env python3
"""Public SPA API boundary and required actual-backend release gate."""
from pathlib import Path
import os
import re
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[2]

class AuthRoutingTest(unittest.TestCase):
    def test_exact_api_routers_preserve_host_and_prefix(self):
        compose = (ROOT / 'docker-compose.prod.yml').read_text()
        for realm in ('account', 'admin'):
            self.assertIn(f'traefik.http.routers.{realm}-api.rule=Host(`{realm}.vashfindir.ru`) && PathPrefix(`/api/{realm}/v1/`)', compose)
            self.assertIn(f'traefik.http.routers.{realm}-api.priority=100', compose)
            self.assertIn(f'traefik.http.routers.{realm}-api.service=myapp', compose)
        self.assertNotIn('stripprefix', compose.lower())

    def test_static_nginx_reserves_backend_paths(self):
        nginx = (ROOT / 'docker/frontend/nginx.conf').read_text()
        self.assertIn('^/(api|internal|admin)(/|$)', nginx)
        self.assertIn(r'\.php', nginx)

    def test_cors_uses_original_request_across_internal_redirect(self):
        nginx = (ROOT / 'site/docker/common/nginx/conf.d/default.conf').read_text()
        self.assertIn('map $request_uri $spa_api_request', nginx)
        self.assertIn("add_header 'Access-Control-Allow-Origin' $legacy_cors_origin", nginx)
        self.assertIn("add_header 'Access-Control-Allow-Credentials' $legacy_cors_credentials", nginx)

    def test_real_backend_suite_is_a_required_deploy_gate(self):
        workflow = (ROOT / '.github/workflows/deploy.yml').read_text()
        self.assertIn('    spa-auth-e2e:', workflow)
        gate = workflow.split('    release-manifest:', 1)[1].split('    production:', 1)[0]
        self.assertIn('spa-auth-e2e', gate)
        self.assertIn("needs.spa-auth-e2e.result == 'success'", gate)
        production = workflow.split('    production:', 1)[1].split('        steps:', 1)[0]
        self.assertIn('needs: release-manifest', production)
        self.assertIn("needs.release-manifest.result == 'success'", production)

    def test_every_mode_runs_as_its_own_parallel_leg(self):
        job = self.workflow().split('    spa-auth-e2e:\n', 1)[1].split('    build-and-push:', 1)[0]
        self.assertIn('mode: [dev, preview, production]', job)
        self.assertIn('fail-fast: false', job)
        self.assertIn('make front-auth-e2e MODE=${{ matrix.mode }}', job)

    def test_job_is_gated_by_the_dedicated_output(self):
        job = self.workflow().split('    spa-auth-e2e:\n', 1)[1].split('        steps:', 1)[0]
        self.assertIn("needs.changes.outputs.auth_e2e == 'true'", job)
        self.assertNotIn('needs.changes.outputs.backend', job)

    def test_pull_request_filter_covers_the_backend_the_suite_exercises(self):
        block = self.workflow().split('                      auth_e2e:\n', 1)[1].split('                      landing:', 1)[0]
        for path in ('site/src/Shared/Controller/Spa/**', 'site/src/**/Controller/Api/**',
                     'site/src/**/Security/**', 'site/src/Company/Entity/**', 'site/config/**',
                     'site/migrations/**', 'site/docker/**', 'site/tests/Support/Spa/**',
                     'site/composer.lock'):
            self.assertIn(f"- '{path}'", block)

    def test_suite_is_skipped_only_on_pull_requests_outside_its_scope(self):
        cases = [
            # event, ref, changed filters, dispatch action, reuse -> auth_e2e
            ('pull_request', 'refs/pull/1/merge', {'backend'}, '', 'false', 'false'),
            ('pull_request', 'refs/pull/1/merge', {'backend', 'auth_e2e'}, '', 'false', 'true'),
            ('pull_request', 'refs/pull/1/merge', {'spa'}, '', 'false', 'true'),
            ('pull_request', 'refs/pull/1/merge', {'landing'}, '', 'false', 'false'),
            ('push', 'refs/heads/master', {'backend'}, '', 'false', 'true'),
            ('push', 'refs/heads/master', {'landing'}, '', 'false', 'true'),
            ('push', 'refs/heads/master', set(), '', 'false', 'false'),
            ('workflow_dispatch', 'refs/heads/master', set(), 'none', 'false', 'false'),
            ('workflow_dispatch', 'refs/heads/master', set(), 'release', 'false', 'true'),
            ('workflow_dispatch', 'refs/heads/master', set(), 'release', 'true', 'false'),
        ]
        for event, ref, changed, action, reuse, expected in cases:
            with self.subTest(event=event, changed=sorted(changed), action=action, reuse=reuse):
                outputs = self.run_matrix_step(event, ref, changed, action, reuse)
                self.assertEqual(outputs['auth_e2e'], expected)
                # Release manifest требует success этого job'а — он обязан идти со сборкой backend.
                if event != 'pull_request' and outputs['backend'] == 'true':
                    self.assertEqual(outputs['auth_e2e'], 'true')

    def workflow(self):
        return (ROOT / '.github/workflows/deploy.yml').read_text()

    def run_matrix_step(self, event, ref, changed, action, reuse):
        step = self.workflow().split('              id: matrix\n', 1)[1].split('              run: |\n', 1)[1]
        lines = []
        for line in step.splitlines():
            if line.strip() and not line.startswith(' ' * 18):
                break
            lines.append(line)
        script = re.sub(r"\$\{\{ steps\.f\.outputs\.(\w+) \}\}",
                        lambda m: 'true' if m[1] in changed else 'false', textwrap.dedent('\n'.join(lines)))
        self.assertNotIn('${{', script)
        with tempfile.NamedTemporaryFile('r') as out:
            env = dict(os.environ, GITHUB_EVENT_NAME=event, GITHUB_REF=ref, GITHUB_OUTPUT=out.name,
                       PRODUCTION_ACTION=action, REUSE_RELEASE=reuse)
            subprocess.run(['bash', '-euo', 'pipefail', '-c', script], env=env, check=True)
            return dict(line.split('=', 1) for line in out.read().splitlines())

if __name__ == '__main__':
    unittest.main()
