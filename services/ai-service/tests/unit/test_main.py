"""Сторож шлюза в точке входа: провайдер мимо шлюза — команда не стартует (выход 2) и не трогает БД."""

from pathlib import Path

import pytest

from ai_service import __main__ as entry

BYPASS = """
providers:
  stub: {type: stub}
  openai: {type: openai_compatible, base_url: "https://api.openai.com/v1", api_key_env: AI_GATEWAY_TOKEN}
tasks:
  echo: {mode: single, models: [stub/echo]}
  categorize_transactions: {mode: single, models: [openai/gpt]}
"""


@pytest.fixture
def bypass_routes(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    path = tmp_path / "routes.yaml"
    path.write_text(BYPASS, encoding="utf-8")
    monkeypatch.setenv("AI_SERVICE_LLM_GATEWAY_URL", "https://ai.vashfindir.ru")
    monkeypatch.setenv("AI_GATEWAY_TOKEN", "t" * 32)
    monkeypatch.setenv("AI_SERVICE_ROUTES_FILE", str(path))
    monkeypatch.setenv("AI_SERVICE_DB_DSN", "postgresql://nobody@127.0.0.1:1/none")
    monkeypatch.setenv("AI_SERVICE_API_TOKEN", "a" * 32)

    def no_db(*_args: object, **_kwargs: object) -> None:
        raise AssertionError("must not connect to the database")

    monkeypatch.setattr(entry.psycopg, "connect", no_db)
    monkeypatch.setattr(entry, "ConnectionPool", no_db)
    return path


@pytest.mark.parametrize("command", ["api", "worker"])
def test_service_does_not_start_with_provider_bypassing_gateway(command: str, bypass_routes: Path) -> None:
    assert entry.main([command]) == 2


@pytest.mark.parametrize("command", ["gateway-check", "llm-ping", "evals-compare"])
def test_model_commands_refuse_provider_bypassing_gateway(
    command: str, bypass_routes: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    assert entry.main([command, "--routes", str(bypass_routes)]) == 2
    assert "provider openai" in capsys.readouterr().err


def test_repository_routes_pass_guard_in_gateway_check(monkeypatch: pytest.MonkeyPatch) -> None:
    calls: list[str] = []
    monkeypatch.setenv("AI_SERVICE_LLM_GATEWAY_URL", "https://ai.vashfindir.ru")
    monkeypatch.delenv("AI_GATEWAY_TOKEN", raising=False)
    monkeypatch.setattr(
        entry, "gateway_check", lambda routing, **_: calls.append("x") or [entry.CheckLine("openai", True, "200/401")]
    )
    assert entry.main(["gateway-check"]) == 0
    assert calls == ["x"]


@pytest.mark.parametrize("command", ["gateway-check", "llm-ping", "evals-compare"])
def test_model_commands_default_to_routes_file_from_env(
    command: str, bypass_routes: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    # В образе пакет установлен в site-packages: маршруты — только по AI_SERVICE_ROUTES_FILE (/app/config).
    monkeypatch.setattr(entry, "gateway_check", lambda routing, **_: [entry.CheckLine("openai", True, "200/401")])
    monkeypatch.setattr(entry, "llm_ping", lambda routing, adapters: [entry.CheckLine("openai", True, "m 1ms")])
    assert entry.main([command]) == 2


def test_evals_compare_reads_sets_from_evals_dir_env(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    # В образе наборы — в /app/evals (AI_SERVICE_EVALS_DIR), не рядом с пакетом в site-packages.
    dev_routes = Path(entry.__file__).resolve().parents[2] / "config" / "routes.dev.yaml"
    args = ["evals-compare", "--models", "stub/categorize", "--routes", str(dev_routes), "--sets", "synthetic"]
    monkeypatch.setenv("AI_SERVICE_EVALS_DIR", str(tmp_path))
    assert entry.main(args) == 2  # пустой каталог — наборов нет
    monkeypatch.setenv("AI_SERVICE_EVALS_DIR", str(dev_routes.parents[1] / "evals"))
    assert entry.main(args) == 0
