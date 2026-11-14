"""Payment-intake runtime guards run locally with fake PHP/docker, never production."""

import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import textwrap
import unittest

REPO = Path(__file__).resolve().parents[3]
WORKFLOW = REPO / ".github/workflows/deploy.yml"
RECOVERY = REPO / "site/bin/recover-payment-intake.sh"
COMPANY_A = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
COMPANY_B = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"
INVALID_SCOPES = [
    "*", "not-a-uuid", COMPANY_A + ",", "," + COMPANY_A,
    COMPANY_A + ",," + COMPANY_B, COMPANY_A + ",broken", COMPANY_A + "\n", COMPANY_A + "\r",
    COMPANY_A + "\nEOF\n", "'" + COMPANY_A, '"' + COMPANY_A,
    COMPANY_A + ", " + COMPANY_B, " " + COMPANY_A,
    COMPANY_A.replace("-", ""), COMPANY_A + ";touch /tmp/not-executed",
    COMPANY_A + ",$(false)", "{" + COMPANY_A + "}",
]


class PaymentIntakeRuntimeTest(unittest.TestCase):
    def run_recovery(self, enabled=None, companies=None, fail_command="", fail_company=""):
        self.assertTrue(RECOVERY.is_file(), "company-scoped recovery runner is missing")
        with tempfile.TemporaryDirectory(prefix="vf-intake-runtime-") as directory:
            root = Path(directory)
            calls = root / "calls.jsonl"
            php = root / "php"
            php.write_text(
                "#!/usr/bin/env python3\n"
                "import json, os, sys\n"
                "with open(os.environ['CALLS'], 'a') as out:\n"
                "    out.write(json.dumps(sys.argv[1:]) + '\\n')\n"
                "sys.exit(1 if sys.argv[2] == os.environ['FAIL_COMMAND'] "
                "and sys.argv[3] == os.environ['FAIL_COMPANY'] else 0)\n"
            )
            php.chmod(0o755)
            env = dict(os.environ, PATH=str(root) + os.pathsep + os.environ["PATH"],
                       CALLS=str(calls), FAIL_COMMAND=fail_command, FAIL_COMPANY=fail_company)
            for key, value in [("TELEGRAM_PAYMENT_INTAKE_ENABLED", enabled),
                               ("TELEGRAM_PAYMENT_INTAKE_COMPANIES", companies)]:
                env.pop(key, None)
                if value is not None:
                    env[key] = value
            result = subprocess.run(["bash", str(RECOVERY)], cwd=REPO / "site", env=env,
                                    capture_output=True, text=True, check=False)
            recorded = [json.loads(line) for line in calls.read_text().splitlines()] if calls.exists() else []
            return result, recorded

    def test_recovery_defaults_off_and_empty_scope_disables_calls(self):
        for flag, scope in [(None, None), ("", COMPANY_A), ("0", COMPANY_A), ("1", "")]:
            with self.subTest(flag=flag, scope=scope):
                result, calls = self.run_recovery(flag, scope)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(calls, [])

    def test_recovery_rejects_whole_invalid_scope_before_first_call(self):
        for scope in INVALID_SCOPES:
            with self.subTest(scope=scope):
                result, calls = self.run_recovery("1", scope)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(calls, [])

    def test_recovery_rejects_noncanonical_flag(self):
        for flag in ["true", "false", "01", " 1", "1\n", "$(false)"]:
            with self.subTest(flag=flag):
                result, calls = self.run_recovery(flag, COMPANY_A)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(calls, [])

    def expected_calls(self):
        return [
            ["bin/console", command, company, *options, "--no-interaction", "--quiet"]
            for company in [COMPANY_A, COMPANY_B]
            for command, options in [
                ("app:cash:recover-payment-intake", []),
                ("app:telegram:recover-payment-intake", ["--skip-cash-recovery"]),
            ]
        ]

    def test_recovery_normalizes_and_deduplicates_companies(self):
        result, calls = self.run_recovery("1", f"{COMPANY_A.upper()},{COMPANY_B},{COMPANY_A}")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(calls, self.expected_calls())

    def test_recovery_continues_both_channels_and_companies_after_either_error(self):
        for command in ["app:cash:recover-payment-intake", "app:telegram:recover-payment-intake"]:
            with self.subTest(command=command):
                result, calls = self.run_recovery("1", f"{COMPANY_A},{COMPANY_B}", command, COMPANY_A)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(calls, self.expected_calls())

    def test_deploy_validates_data_on_runner_before_ssh_interpolation(self):
        workflow = WORKFLOW.read_text()
        self.assertLess(workflow.index("- name: 🔎 Check service secrets alphabet"),
                        workflow.index("- name: 🚀 Deploy application (rolling update)"))
        step = workflow.split("- name: 🔎 Check service secrets alphabet", 1)[1].split("- name:", 1)[0]
        env_block, script = step.split("run: |", 1)
        for key in ["TELEGRAM_PAYMENT_INTAKE_ENABLED", "TELEGRAM_PAYMENT_INTAKE_COMPANIES"]:
            self.assertIn(f"{key}: ${{{{ vars.{key} }}}}", env_block)
            self.assertIn(f"export {key}='${{{{ vars.{key} }}}}'", workflow)
        env = {key: "" for key in re.findall(r"^\s+([A-Z_0-9]+):", env_block, re.MULTILINE)}
        for flag, scope, valid in [
            ("", "", True), ("0", COMPANY_A, True), ("1", COMPANY_A, True),
            ("1", f"{COMPANY_A.upper()},{COMPANY_B}", True),
            *[(flag, COMPANY_A, False) for flag in ["true", "01", "1\n", "'\nEOF"]],
            *[("1", scope, False) for scope in INVALID_SCOPES],
        ]:
            with self.subTest(flag=flag, scope=scope):
                values = dict(env, TELEGRAM_PAYMENT_INTAKE_ENABLED=flag, TELEGRAM_PAYMENT_INTAKE_COMPANIES=scope)
                result = subprocess.run(["bash", "-eu", "-c", textwrap.dedent(script)],
                                        env=values, capture_output=True, text=True, check=False)
                self.assertEqual(result.returncode == 0, valid, result.stderr + result.stdout)

    def test_deploy_explicitly_starts_or_stops_worker_and_final_up_keeps_profile_closed(self):
        workflow = WORKFLOW.read_text()
        match = re.search(r'if \[ "\$TELEGRAM_PAYMENT_INTAKE_ENABLED" = "1" \].*?\n\s*fi',
                          workflow, re.DOTALL)
        self.assertIsNotNone(match, "conditional payment-intake worker deployment is missing")
        block = textwrap.dedent(match[0])
        # The profile must stay local to this block; the final ordinary up must not enable it.
        remainder = workflow.replace(match[0], "")
        self.assertNotIn("site-messenger-worker-payment-intake", remainder)
        self.assertNotIn("--profile payment-intake", remainder)
        self.assertNotRegex(workflow, r"COMPOSE_PROFILES=.*payment-intake")
        self.assertIn("docker compose -f docker-compose.prod.yml up -d --remove-orphans", remainder)
        for flag, scope, start in [("1", COMPANY_A, True), ("1", "", False),
                                   ("0", COMPANY_A, False), ("", COMPANY_A, False)]:
            with self.subTest(flag=flag, scope=scope):
                script = 'docker() { printf "DOCKER %s\\n" "$*"; }\n' + block
                result = subprocess.run(["bash", "-eu", "-c", script],
                                        env={"TELEGRAM_PAYMENT_INTAKE_ENABLED": flag,
                                             "TELEGRAM_PAYMENT_INTAKE_COMPANIES": scope},
                                        capture_output=True, text=True, check=False)
                self.assertEqual(result.returncode, 0, result.stderr)
                calls = [line for line in result.stdout.splitlines() if line.startswith("DOCKER ")]
                self.assertEqual(len(calls), 1)
                self.assertIn("--profile payment-intake", calls[0])
                self.assertIn("up -d --no-deps" if start else "stop", calls[0])
                self.assertNotIn("--wait", calls[0])
                self.assertTrue(calls[0].endswith("site-messenger-worker-payment-intake"))

    def test_shell_worker_checker_keeps_existing_workers_and_intake_guard_mandatory(self):
        workflow = WORKFLOW.read_text()
        mutations = [
            workflow.replace('if [ "$TELEGRAM_PAYMENT_INTAKE_ENABLED" = "1" ]', 'if true'),
            workflow.replace("site-php-cli site-messenger-worker-pipeline site-messenger-worker-ads",
                             "site-php-cli site-messenger-worker-ads"),
        ]
        with tempfile.TemporaryDirectory(prefix="vf-intake-workflow-") as directory:
            path = Path(directory) / "deploy.yml"
            for mutation in mutations:
                with self.subTest(mutation=mutations.index(mutation)):
                    path.write_text(mutation)
                    result = subprocess.run(["bash", str(REPO / "tests/shell/deploy-workflow-test.sh")],
                                            env=dict(os.environ, DEPLOY_WORKFLOW=str(path)),
                                            capture_output=True, text=True, check=False)
                    self.assertNotEqual(result.returncode, 0)

    def test_cron_runs_only_scoped_runner_every_five_minutes(self):
        lines = (REPO / "docker/cron/app.cron").read_text().splitlines()
        entries = [line for line in lines if not line.startswith("#") and "recover-payment-intake" in line]
        self.assertEqual(len(entries), 1)
        self.assertTrue(entries[0].startswith("*/5 * * * * cd /app && bash bin/recover-payment-intake.sh"))


if __name__ == "__main__":
    unittest.main()
