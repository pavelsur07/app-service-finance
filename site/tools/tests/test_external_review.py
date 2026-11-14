"""The read-only review collector must cover AI code while excluding fixture payloads."""

import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / "bin/external-review.sh"


class ReviewCoverageTest(unittest.TestCase):
    def test_python_only_changes_reach_reviewer_without_eval_payloads(self):
        with tempfile.TemporaryDirectory(prefix="vf-review-paths-") as directory:
            root = Path(directory)
            subprocess.run(["git", "init", "-q", str(root)], check=True)
            module = root / "services/ai-service/src/ai_service/quality.py"
            module.parent.mkdir(parents=True)
            module.write_text("SAFE_CODE = 1\n")
            data = root / "services/ai-service/evals/private.jsonl"
            data.parent.mkdir(parents=True)
            data.write_text('"PRIVATE_FIXTURE_MARKER"\n')
            migration = root / "services/ai-service/migrations/0002_quality.sql"
            migration.parent.mkdir(parents=True)
            migration.write_text("SELECT 1;\n")
            schema = root / "services/_contracts/ai-eval-run/v1/schema.json"
            schema.parent.mkdir(parents=True)
            schema.write_text('{"title":"old"}\n')
            subprocess.run(["git", "add", "."], cwd=root, check=True)
            subprocess.run(["git", "-c", "user.name=ReviewTest", "-c", "user.email=review@example.invalid", "commit", "-qm", "base"], cwd=root, check=True)
            base = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip()
            module.write_text("SAFE_CODE = 2\n")
            migration.write_text("SELECT 2;\n")
            schema.write_text('{"title":"CONTRACT_CHANGED"}\n')
            data.write_text('"CHANGED_PRIVATE_FIXTURE_MARKER"\n')
            tools = root / "test-bin"
            tools.mkdir()
            reviewer = tools / "claude"
            reviewer.write_text("#!/bin/sh\ncat >/dev/null\nprintf 'REVIEW_GREEN\\n'\n")
            reviewer.chmod(0o755)
            environment = dict(os.environ)
            environment["PATH"] = str(tools) + os.pathsep + environment["PATH"]
            result = subprocess.run(["bash", str(SCRIPT), base, "--reviewer", "claude", "--out", str(root / "report")], cwd=root, env=environment, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            patch = (root / "report/diff.patch").read_text()
            self.assertIn("SAFE_CODE = 2", patch)
            self.assertIn("SELECT 2", patch)
            self.assertIn("CONTRACT_CHANGED", patch)
            self.assertNotIn("PRIVATE_FIXTURE_MARKER", patch)


if __name__ == "__main__":
    unittest.main()
