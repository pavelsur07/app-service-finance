import pytest

from ai_service.llm.gateway import LlmGateway, UnknownRouteError
from ai_service.llm.routes import parse_routing
from ai_service.llm.types import LlmError, LlmRequest, LlmResponse, LlmUnavailableError


class ScriptedAdapter:
    def __init__(self, provider: str, script: list[LlmError | str]) -> None:
        self.provider = provider
        self.script = list(script)
        self.calls = 0

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        self.calls += 1
        step = self.script.pop(0)
        if isinstance(step, LlmError):
            raise step
        return LlmResponse(step, f"{self.provider}/{model}", 10, 5, 1)


def routing(mode: str, models: list[str], env: dict[str, str] | None = None):  # type: ignore[no-untyped-def]
    return parse_routing(
        {
            "providers": {
                "a": {"type": "openai_compatible", "base_url": "http://a", "api_key_env": "A"},
                "b": {"type": "openai_compatible", "base_url": "http://b", "api_key_env": "B"},
                "c": {"type": "openai_compatible", "base_url": "http://c", "api_key_env": "C"},
            },
            "tasks": {"t": {"mode": mode, "models": models}},
        },
        env if env is not None else {"A": "k", "B": "k", "C": "k"},
    )


REQ = LlmRequest(system="s", user="u")


def test_fallback_moves_to_next_model_after_retryable_errors() -> None:
    a = ScriptedAdapter("a", [LlmError("x", retryable=True, code="http_503")] * 2)
    b = ScriptedAdapter("b", ["ok"])
    result = LlmGateway(routing("fallback", ["a/m1", "b/m2"]), {"a": a, "b": b}, retries_per_model=1).complete("t", REQ)
    assert result.response.model == "b/m2"
    assert a.calls == 2  # исходный вызов + один повтор
    assert [(c.model, c.ok, c.error) for c in result.calls] == [
        ("a/m1", False, "http_503"),
        ("a/m1", False, "http_503"),
        ("b/m2", True, None),
    ]


def test_non_retryable_error_skips_repeat_of_same_model() -> None:
    a = ScriptedAdapter("a", [LlmError("x", retryable=False, code="http_400")])
    b = ScriptedAdapter("b", ["ok"])
    LlmGateway(routing("fallback", ["a/m1", "b/m2"]), {"a": a, "b": b}, retries_per_model=3).complete("t", REQ)
    assert a.calls == 1


def test_disabled_provider_is_skipped_in_fallback() -> None:
    b = ScriptedAdapter("b", ["ok"])
    gw = LlmGateway(routing("fallback", ["a/m1", "b/m2"], env={"B": "k"}), {"b": b})
    result = gw.complete("t", REQ)
    assert result.calls[0].error == "provider_disabled"
    assert result.response.model == "b/m2"


def test_single_mode_does_not_fall_back() -> None:
    a = ScriptedAdapter("a", [LlmError("x", retryable=False, code="http_401")])
    b = ScriptedAdapter("b", ["ok"])
    with pytest.raises(LlmUnavailableError) as e:
        LlmGateway(routing("single", ["a/m1"]), {"a": a, "b": b}).complete("t", REQ)
    assert [c.error for c in e.value.calls] == ["http_401"]
    assert b.calls == 0


def test_all_three_models_failing_raises_unavailable() -> None:
    err = LlmError("x", retryable=False, code="refusal")
    adapters = {p: ScriptedAdapter(p, [err]) for p in "abc"}
    with pytest.raises(LlmUnavailableError) as e:
        LlmGateway(routing("fallback", ["a/m", "b/m", "c/m"]), dict(adapters)).complete("t", REQ)
    assert len(e.value.calls) == 3


def test_unknown_route() -> None:
    with pytest.raises(UnknownRouteError):
        LlmGateway(routing("fallback", ["a/m"]), {}).complete("other", REQ)
