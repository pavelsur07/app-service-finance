from pathlib import Path

import pytest

from ai_service.llm.routes import (
    AdapterType,
    Mode,
    ModelRef,
    Route,
    RoutingConfig,
    RoutingConfigError,
    assert_via_gateway,
    load_routing,
    parse_routing,
    worst_case_seconds,
)

ROUTES_FILE = Path(__file__).resolve().parents[2] / "config" / "routes.yaml"
GATEWAY = "https://ai.vashfindir.ru"


def test_repository_routes_load_and_only_stub_is_available_without_keys() -> None:
    routing = load_routing(ROUTES_FILE, env={})
    assert routing.routes["echo"].mode is Mode.SINGLE
    assert {name for name, p in routing.providers.items() if p.available} == {"stub"}


def test_repository_providers_share_gateway_token() -> None:
    routing = load_routing(ROUTES_FILE, env={"AI_GATEWAY_TOKEN": "t"})
    assert {name for name, p in routing.providers.items() if p.available} == {"stub", "openai", "deepseek", "kimi"}
    assert {p.api_key for p in routing.providers.values() if p.type is not AdapterType.STUB} == {"t"}


def test_repository_routes_go_through_gateway() -> None:
    assert_via_gateway(load_routing(ROUTES_FILE, env={}), GATEWAY)


def test_repository_route_uses_only_configured_gateway_providers() -> None:
    # Kimi — вне маршрута до ключа на шлюзе (#2837): общий токен, шлюз ответил бы 503.
    route = load_routing(ROUTES_FILE, env={}).routes["categorize_transactions"]
    assert [ref.provider for ref in route.models] == ["deepseek", "openai"]


def test_repository_route_uses_deepseek_flash_without_thinking() -> None:
    # deepseek-chat выводится DeepSeek; у deepseek-flash размышление по умолчанию включено (g3.md).
    routing = load_routing(ROUTES_FILE, env={})
    assert routing.routes["categorize_transactions"].models[0].key == "deepseek/deepseek-flash"
    assert routing.providers["deepseek"].extra_body == {"thinking": {"type": "disabled"}}
    assert routing.providers["openai"].extra_body == {}


@pytest.mark.parametrize(
    "extra_body", ["thinking", ["thinking"], {"model": "x"}, {"messages": []}, {"max_tokens": 1}, {"temperature": 1}]
)
def test_invalid_extra_body_is_rejected(extra_body: object) -> None:
    with pytest.raises(RoutingConfigError, match="provider p: extra_body"):
        _one_provider({"type": "openai_compatible", "base_url": GATEWAY + "/x/v1", "extra_body": extra_body})


def _one_provider(spec: dict[str, object]) -> RoutingConfig:
    return parse_routing({"providers": {"stub": {"type": "stub"}, "p": spec}}, {})


@pytest.mark.parametrize(
    "spec",
    [
        {"type": "openai_compatible", "base_url": "https://api.openai.com/v1"},
        {"type": "openai_compatible", "base_url": "https://ai.vashfindir.ru.evil/openai/v1"},
        {"type": "openai_compatible", "base_url": "http://ai.vashfindir.ru/openai/v1"},
        {"type": "openai_compatible", "base_url": "https://ai.vashfindir.ru"},
        {"type": "openai_compatible"},
        {"type": "anthropic"},
        {"type": "yandexgpt"},
    ],
)
def test_provider_bypassing_gateway_is_rejected(spec: dict[str, object]) -> None:
    with pytest.raises(RoutingConfigError, match="provider p"):
        assert_via_gateway(_one_provider(spec), GATEWAY)


@pytest.mark.parametrize("gateway", [GATEWAY, GATEWAY + "/"])
def test_provider_behind_gateway_passes(gateway: str) -> None:
    assert_via_gateway(_one_provider({"type": "openai_compatible", "base_url": GATEWAY + "/x/v1"}), gateway)


@pytest.mark.parametrize("gateway", [None, ""])
def test_gateway_guard_is_off_without_gateway_url(gateway: str | None) -> None:
    assert_via_gateway(_one_provider({"type": "openai_compatible", "base_url": "https://api.openai.com/v1"}), gateway)


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


def test_default_lease_covers_worst_case_of_repository_routes() -> None:
    from ai_service.config import Settings

    defaults = Settings.model_fields
    routing = load_routing(ROUTES_FILE, env={})
    lease = defaults["worker_lease_seconds"].default
    for route in routing.routes.values():
        worst = worst_case_seconds(
            route, defaults["llm_timeout_seconds"].default, defaults["llm_retries_per_model"].default
        )
        assert worst < lease, route.task_type


def test_dev_routes_put_every_task_on_stub() -> None:
    routing = load_routing(ROUTES_FILE.with_name("routes.dev.yaml"), env={})
    assert all(ref.provider == "stub" for route in routing.routes.values() for ref in route.models)


@pytest.mark.parametrize("name", ["routes.yaml", "routes.dev.yaml"])
def test_every_registered_task_has_a_route(name: str) -> None:
    from ai_service.tasks.registry import build_registry

    routing = load_routing(ROUTES_FILE.with_name(name), env={})
    assert set(build_registry().task_types()) <= set(routing.routes)
