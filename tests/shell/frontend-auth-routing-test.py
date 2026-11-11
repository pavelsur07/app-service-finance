#!/usr/bin/env python3
"""Public SPA API boundary and required actual-backend release gate."""
from pathlib import Path
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
        gate = workflow.split('    schema-ready:', 1)[1].split('    deploy:', 1)[0]
        self.assertIn('spa-auth-e2e', gate)
        self.assertIn("needs['spa-auth-e2e'].result == 'success'", gate)
        for mode in ('dev', 'preview', 'production'):
            self.assertIn(f'make front-auth-e2e MODE={mode}', workflow)

if __name__ == '__main__':
    unittest.main()
