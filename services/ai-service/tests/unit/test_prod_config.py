"""Прод-конфигурация ai-service (gateway/g2.md, A10–A11): ключи провайдеров — только на шлюзе.

Читает файлы из корня репозитория: в dev-контейнере и в CI он смонтирован в /repo.
"""

import re
from pathlib import Path
from typing import Any

import yaml

from ai_service.llm.routes import assert_via_gateway, load_routing

REPO = Path(__file__).resolve().parents[4]
ROUTES_FILE = Path(__file__).resolve().parents[2] / "config" / "routes.yaml"
PROVIDER_SECRETS = re.compile(r"AI_(OPENAI|DEEPSEEK|KIMI|ANTHROPIC|YANDEX|LOCAL)_[A-Z_]+")


def _ai_service_env() -> dict[str, Any]:
    compose = yaml.safe_load((REPO / "docker-compose.prod.yml").read_text(encoding="utf-8"))
    services = compose["services"]
    env: dict[str, Any] = services["ai-service"]["environment"]
    assert services["ai-service-worker"]["environment"] == env
    return env


def test_prod_compose_has_gateway_token_and_no_provider_keys() -> None:
    env = _ai_service_env()
    assert env["AI_GATEWAY_TOKEN"] == "${AI_GATEWAY_TOKEN:-}"
    assert [k for k in env if PROVIDER_SECRETS.fullmatch(k) or k.endswith("_API_KEY")] == []


def test_prod_routes_pass_guard_with_compose_gateway_url() -> None:
    gateway = _ai_service_env()["AI_SERVICE_LLM_GATEWAY_URL"]
    assert gateway == "https://ai.vashfindir.ru"  # литерал: пустая переменная не выключит сторож
    assert_via_gateway(load_routing(ROUTES_FILE, env={}), gateway)


def test_deploy_passes_gateway_token_not_provider_keys() -> None:
    deploy = (REPO / ".github" / "workflows" / "deploy.yml").read_text(encoding="utf-8")
    assert PROVIDER_SECRETS.findall(deploy) == []
    assert "export AI_GATEWAY_TOKEN='${{ secrets.AI_GATEWAY_TOKEN }}'" in deploy
    # Алфавит проверяется в обоих циклах проверки секретов (раннер и хост).
    loops = [
        line for line in deploy.splitlines() if line.strip().startswith("for v in ") and "AI_SERVICE_API_TOKEN" in line
    ]
    assert len(loops) == 2
    assert all(" AI_GATEWAY_TOKEN" in line for line in loops)
    # Включённый сервис проверяет шлюз (проверки 2 и 4) до запуска воркера.
    assert deploy.index("ai-service gateway-check") < deploy.index("up -d --no-deps --wait ai-service\n")
    assert deploy.index("ai-service gateway-check") < deploy.index("up -d --no-deps ai-service-worker\n")


def test_deploy_waits_only_for_services_with_healthcheck() -> None:
    """`up --wait` падает на сервисе без healthcheck (G3, 31.10: воркер ai-service)."""
    compose = yaml.safe_load((REPO / "docker-compose.prod.yml").read_text(encoding="utf-8"))
    no_healthcheck = {
        name for name, svc in compose["services"].items() if (svc.get("healthcheck") or {}).get("disable") is True
    }
    assert "ai-service-worker" in no_healthcheck
    deploy = (REPO / ".github" / "workflows" / "deploy.yml").read_text(encoding="utf-8")
    waited = [
        (line.strip(), set(line.split("--wait", 1)[1].split()) & no_healthcheck)
        for line in deploy.splitlines()
        if "docker compose" in line and " up " in line and "--wait" in line
    ]
    assert waited
    assert [line for line, bad in waited if bad] == []
