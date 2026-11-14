"""Nightly runtime wiring: bounded worker, short cron commands and guarded deploy."""

import re
import subprocess
from pathlib import Path

import pytest
import yaml

REPO = Path(__file__).resolve().parents[4]
WORKFLOW = REPO / ".github/workflows/deploy.yml"


@pytest.mark.parametrize(
    ("filename", "prefix", "profile"),
    [("docker-compose.yml", "services-", "services"), ("docker-compose.prod.yml", "", "ai-service")],
)
def test_nightly_worker_shares_runtime_and_has_bounded_shutdown(filename: str, prefix: str, profile: str) -> None:
    services = yaml.safe_load((REPO / filename).read_text(encoding="utf-8"))["services"]
    api = services[f"{prefix}ai-service"]
    business = services[f"{prefix}ai-service-worker"]
    nightly = services[f"{prefix}ai-service-eval-worker"]
    assert nightly["environment"] == business["environment"] == api["environment"]
    assert nightly.get("image", nightly.get("build")) == api.get("image", api.get("build"))
    assert nightly.get("networks", ["default"]) == api.get("networks", ["default"])
    assert nightly["profiles"] == [profile]
    assert nightly["command"] == ["eval-worker"]
    assert nightly["restart"] == "on-failure"
    assert nightly["healthcheck"] == {"disable": True}
    assert nightly["stop_grace_period"] == "960s"
    assert nightly["deploy"]["resources"]["limits"]["memory"] == "256M"
    assert not nightly.get("ports")
    assert not nightly.get("expose")
    assert not nightly.get("labels")
    env = api["environment"]
    assert env["AI_SERVICE_EVAL_ENABLED"] == "${AI_SERVICE_EVAL_ENABLED:-false}"
    assert str(env["AI_SERVICE_EVAL_DAILY_LIMIT_RUB"]) == "30"
    assert str(env["AI_SERVICE_EVAL_MONTHLY_LIMIT_RUB"]) == "900"


def test_symfony_and_scheduler_share_default_off_nightly_flag() -> None:
    compose = yaml.safe_load((REPO / "docker-compose.prod.yml").read_text(encoding="utf-8"))
    for env in [compose["x-php-env"], compose["services"]["scheduler"]["environment"]]:
        assert env["AI_SERVICE_EVAL_ENABLED"] == "${AI_SERVICE_EVAL_ENABLED:-false}"
        assert env["AI_SERVICE_ENABLED"] == "${AI_SERVICE_ENABLED:-false}"


@pytest.mark.parametrize(
    ("name", "schedule"),
    [("request", "*/5 * * * *"), ("pull", "*/5 * * * *"), ("health-check", "45 * * * *")],
)
def test_nightly_cron_only_invokes_short_symfony_commands(name: str, schedule: str) -> None:
    lines = (REPO / "docker/cron/app.cron").read_text(encoding="utf-8").splitlines()
    selected = [line for line in lines if not line.startswith("#") and f"app:ai-service:quality:{name} " in line]
    assert len(selected) == 1
    assert selected[0].startswith(f"{schedule} cd /app && php bin/console ")
    assert "--no-interaction" in selected[0]
    assert "eval-worker" not in "\n".join(lines)


def test_deploy_exports_and_validates_nonsecret_nightly_flag() -> None:
    deploy = WORKFLOW.read_text(encoding="utf-8")
    assert "AI_SERVICE_EVAL_ENABLED: ${{ vars.AI_SERVICE_EVAL_ENABLED }}" in deploy
    assert "export AI_SERVICE_EVAL_ENABLED='${{ vars.AI_SERVICE_EVAL_ENABLED }}'" in deploy
    assert "secrets.AI_SERVICE_EVAL_ENABLED" not in deploy
    check = re.search(r'case "\$AI_SERVICE_EVAL_ENABLED" in.*?esac', deploy, re.DOTALL)
    assert check is not None
    for value in ["", "true", "false", "TRUE", "yes", "true\nEOF"]:
        result = subprocess.run(  # noqa: S603 — trusted repository validation, values passed only via env.
            ["/bin/bash", "-c", check[0]], env={"AI_SERVICE_EVAL_ENABLED": value}, capture_output=True, check=False
        )
        assert result.returncode == (0 if value in ("", "true", "false") else 1)


@pytest.mark.parametrize("service_enabled", ["true", "false"])
@pytest.mark.parametrize("eval_enabled", ["true", "false", ""])
def test_deploy_starts_nightly_only_after_migrations_when_both_flags_enabled(
    service_enabled: str, eval_enabled: str
) -> None:
    deploy = WORKFLOW.read_text(encoding="utf-8")
    start = deploy.index('if [ "$AI_SERVICE_ENABLED" = "true" ]; then')
    end = deploy.index('echo "🐳 Обновляем account/admin SPA"', start)
    script = 'docker() { echo "DOCKER $*"; }\n' + deploy[start:end]
    result = subprocess.run(  # noqa: S603 — trusted repository block, docker replaced with a local echo function.
        ["/bin/bash", "-eu", "-c", script],
        cwd=REPO,
        env={
            "AI_SERVICE_ENABLED": service_enabled,
            "AI_SERVICE_EVAL_ENABLED": eval_enabled,
            "AI_SERVICE_DB_PASSWORD": "fake-test-password",
            "AI_SERVICE_API_TOKEN": "a" * 32,
            "AI_GATEWAY_TOKEN": "b" * 32,
        },
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, result.stderr
    commands = [line for line in result.stdout.splitlines() if line.startswith("DOCKER ")]
    nightly_start = [line for line in commands if " up " in line and "ai-service-eval-worker" in line]
    nightly_pull = [line for line in commands if " pull " in line and "ai-service-eval-worker" in line]
    nightly_stop = [line for line in commands if " stop " in line and "ai-service-eval-worker" in line]
    if service_enabled == eval_enabled == "true":
        assert len(nightly_start) == len(nightly_pull) == 1
        assert nightly_stop == []
        assert "--wait" not in nightly_start[0]
        migration = next(line for line in commands if "ai-service migrate" in line)
        gateway = next(line for line in commands if "ai-service gateway-check" in line)
        assert commands.index(nightly_pull[0]) < commands.index(migration) < commands.index(gateway)
        assert commands.index(gateway) < commands.index(nightly_start[0])
    else:
        assert nightly_start == nightly_pull == []
        assert len(nightly_stop) == 1
