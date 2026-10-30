from pathlib import Path

import pytest

from ai_service.llm.routes import (
    Mode,
    ModelRef,
    Route,
    RoutingConfigError,
    load_routing,
    parse_routing,
    worst_case_seconds,
)

ROUTES_FILE = Path(__file__).resolve().parents[2] / "config" / "routes.yaml"


def test_repository_routes_load_and_only_stub_is_available_without_keys() -> None:
    routing = load_routing(ROUTES_FILE, env={})
    assert routing.routes["echo"].mode is Mode.SINGLE
    assert {name for name, p in routing.providers.items() if p.available} == {"stub"}


def test_provider_becomes_available_with_key_env() -> None:
    routing = load_routing(ROUTES_FILE, env={"AI_OPENAI_API_KEY": "k", "AI_YANDEX_API_KEY": "k"})
    assert routing.providers["openai"].available
    assert not routing.providers["yandex"].available  # нужен ещё folder_id


@pytest.mark.parametrize(
    ("raw", "message"),
    [
        ({"providers": {"p": {"type": "nope"}}}, "unknown type"),
        ({"providers": {"p": {"type": "stub"}}, "tasks": {"t": {"mode": "vote", "models": ["p/m"]}}}, "vote"),
        ({"providers": {"p": {"type": "stub"}}, "tasks": {"t": {"models": ["x/m"]}}}, "unknown provider"),
        ({"providers": {"p": {"type": "stub"}}, "tasks": {"t": {"models": []}}}, "must not be empty"),
        ({"providers": {"p": {"type": "stub"}}, "tasks": {"t": {"mode": "single", "models": ["p/a", "p/b"]}}}, "one"),
        ({"providers": {"p": {"type": "stub"}}, "tasks": {"t": {"models": ["nomodel"]}}}, "provider/model"),
    ],
)
def test_invalid_config_is_rejected(raw: object, message: str) -> None:
    with pytest.raises(RoutingConfigError, match=message):
        parse_routing(raw, {})


def test_worst_case_counts_models_retries_and_parse_attempts() -> None:
    route = Route(task_type="t", mode=Mode.FALLBACK, models=(ModelRef.parse("a/x"), ModelRef.parse("b/y")))
    assert worst_case_seconds(route, timeout_seconds=60, retries_per_model=1) == 2 * 2 * 2 * 60
