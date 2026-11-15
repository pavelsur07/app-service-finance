"""Execute deployment/cron against local fakes; no network or production access."""

import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import tempfile
import textwrap
import unittest

REPO = Path(__file__).resolve().parents[3]
WORKFLOW = REPO / '.github/workflows/deploy.yml'
RETIRED = ['AI_SERVICE_ENABLED', 'OZON_FETCHER_ENABLED', 'WB_FETCHER_ENABLED',
           'SOURCE_FACTS_COMPANIES', 'TELEGRAM_PAYMENT_INTAKE_ENABLED',
           'TELEGRAM_PAYMENT_INTAKE_COMPANIES']
SECRETS = ['OZON_FETCHER_DB_PASSWORD', 'INTERNAL_AUTH_TOKEN', 'OZON_FETCHER_API_TOKEN',
           'WB_FETCHER_DB_PASSWORD', 'WB_FETCHER_API_TOKEN', 'OZON_FETCHER_S3_ACCESS_KEY_ID',
           'AI_SERVICE_DB_PASSWORD', 'AI_SERVICE_API_TOKEN', 'AI_GATEWAY_TOKEN']


class PaymentIntakeRuntimeTest(unittest.TestCase):
    def deploy(self, nightly='false', stale=False, missing=None):
        workflow = WORKFLOW.read_text()
        start = workflow.index('                    echo "🐳 [4/5]')
        end = workflow.index('                    echo "🐳 Обновляем account/admin SPA"', start)
        script = 'docker() { printf "DOCKER %s\\n" "$*"; }\n' + textwrap.dedent(workflow[start:end])
        env = {'PATH': os.environ['PATH'], 'AI_SERVICE_EVAL_ENABLED': nightly,
               **{key: 'synthetic-test-' + 'a' * 32 for key in SECRETS}}
        if stale:
            env.update({key: 'false' if key.endswith('_ENABLED') else '' for key in RETIRED})
        if missing:
            env[missing] = ''
        result = subprocess.run(['bash', '-eu', '-c', script], cwd=REPO, env=env,
                                capture_output=True, text=True, check=False)
        calls = [shlex.split(line[7:]) for line in result.stdout.splitlines() if line.startswith('DOCKER ')]
        return result, calls

    def test_services_start_without_rollout_settings_and_ignore_old_values(self):
        for stale in [False, True]:
            with self.subTest(stale=stale):
                result, calls = self.deploy(stale=stale)
                self.assertEqual(result.returncode, 0, result.stderr)
                for service in ['ozon-fetcher', 'wb-fetcher', 'ai-service', 'ai-service-worker',
                                'site-messenger-worker-payment-intake']:
                    starts = [call for call in calls if 'up' in call and service in call]
                    self.assertEqual(len(starts), 1, service)
                    self.assertFalse(any('stop' in call and service in call for call in calls))
                for service, migrate in [('ozon-fetcher', '-migrate'), ('wb-fetcher', '-migrate'),
                                         ('ai-service', 'migrate')]:
                    migration = next(i for i, call in enumerate(calls) if 'run' in call and service in call and migrate in call)
                    start = next(i for i, call in enumerate(calls) if 'up' in call and service in call)
                    self.assertLess(migration, start)

    def test_missing_required_secret_fails_before_corresponding_service_start(self):
        for secret in SECRETS:
            with self.subTest(secret=secret):
                result, calls = self.deploy(missing=secret)
                self.assertNotEqual(result.returncode, 0)
                service = ('ai-service' if secret.startswith('AI_') else
                           'wb-fetcher' if secret.startswith('WB_') or secret == 'OZON_FETCHER_S3_ACCESS_KEY_ID'
                           else 'ozon-fetcher')
                self.assertFalse(any('up' in call and service in call for call in calls))

    def test_nightly_flag_only_controls_eval_worker(self):
        for nightly in ['', 'false', 'true']:
            with self.subTest(nightly=nightly):
                result, calls = self.deploy(nightly=nightly)
                self.assertEqual(result.returncode, 0, result.stderr)
                starts = [call for call in calls if 'up' in call and 'ai-service-eval-worker' in call]
                stops = [call for call in calls if 'stop' in call and 'ai-service-eval-worker' in call]
                self.assertEqual(len(starts), int(nightly == 'true'))
                self.assertEqual(len(stops), int(nightly != 'true'))
                for call in starts:
                    self.assertIn('--profile', call)
                    self.assertEqual(call[call.index('--profile') + 1], 'ai-service-eval')
                    self.assertNotIn('--wait', call)
                business_start = next(i for i, call in enumerate(calls) if 'up' in call and 'ai-service-worker' in call)
                gateway = next(i for i, call in enumerate(calls) if 'gateway-check' in call)
                self.assertLess(gateway, business_start)
                if starts:
                    self.assertLess(gateway, calls.index(starts[0]))

    def test_cron_recovers_all_without_rollout_environment(self):
        entries = [line for line in (REPO / 'docker/cron/app.cron').read_text().splitlines()
                   if not line.startswith('#') and 'recover-payment-intake' in line]
        self.assertEqual(len(entries), 1)
        command = entries[0].split('cd /app && ', 1)[1].split(' >> ', 1)[0]
        with tempfile.TemporaryDirectory(prefix='vf-intake-cron-') as directory:
            root = Path(directory)
            calls = root / 'calls.json'
            php = root / 'php'
            php.write_text('#!/usr/bin/env python3\nimport json,os,sys\n'
                           'with open(os.environ["CALLS"], "w") as out: json.dump(sys.argv[1:], out)\n')
            php.chmod(0o755)
            result = subprocess.run(['bash', '-eu', '-c', command], cwd=REPO / 'site',
                                    env={'PATH': directory + os.pathsep + os.environ['PATH'], 'CALLS': str(calls)},
                                    capture_output=True, text=True, check=False)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(calls.read_text()), ['bin/console', 'app:telegram:recover-payment-intake',
                                                           '--all', '--no-interaction', '--quiet'])

    def test_shell_worker_checker_requires_payment_intake_in_step_four(self):
        workflow = WORKFLOW.read_text()
        mutation = re.sub(r'(echo "🐳 \[4/5\].*?\n[^\n]*up[^\n]*) site-messenger-worker-payment-intake',
                          r'\1', workflow, count=1)
        with tempfile.TemporaryDirectory(prefix='vf-intake-workflow-') as directory:
            path = Path(directory) / 'deploy.yml'
            path.write_text(mutation)
            result = subprocess.run(['bash', str(REPO / 'tests/shell/deploy-workflow-test.sh')],
                                    env=dict(os.environ, DEPLOY_WORKFLOW=str(path)),
                                    capture_output=True, text=True, check=False)
            self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__':
    unittest.main()
