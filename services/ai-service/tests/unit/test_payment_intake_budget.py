"""Nightly opt-ins preserve the historical conservative runner defaults."""

from decimal import Decimal

import pytest

from ai_service.evals.payment_intake import BoundedAdapter, Limits, evaluate, load_holdout
from ai_service.llm.types import LlmError, LlmRequest, LlmResponse


class KnownUsage:
    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        return LlmResponse("{}", "stub/test", 10, 20, 1)


@pytest.mark.parametrize(("settle", "expected"), [(False, Decimal("0.081536")), (True, Decimal("0.00003"))])
def test_known_usage_can_release_only_the_unused_reservation(settle: bool, expected: Decimal) -> None:
    limits = Limits(2, 65536, 16000, Decimal("1"), Decimal("1"), Decimal("1"), settle_known_usage=settle)
    adapter = BoundedAdapter(KnownUsage(), limits, "stub/test")
    adapter.complete("test", LlmRequest("system", "user"))
    assert adapter.reserved == expected


def test_provider_cutoff_is_opt_in_and_unknown_usage_keeps_reservation() -> None:
    class Broken:
        calls = 0

        def complete(self, model: str, request: LlmRequest) -> LlmResponse:
            self.calls += 1
            raise LlmError("sensitive provider text", retryable=True, code="provider_error")

    broken = Broken()
    limits = Limits(
        5,
        65536,
        16000,
        Decimal("1"),
        Decimal("1"),
        Decimal("1"),
        settle_known_usage=True,
        stop_on_provider_error=True,
    )
    report = evaluate(load_holdout()[:3], broken, "stub/test", limits)
    assert broken.calls == 1
    assert report["stop_reason"] == "provider_error"
    assert Decimal(report["reserved_cost_rub_estimate"]) == limits.reservation
    assert not report["complete"]


def test_zero_default_usage_cannot_release_unknown_reservation() -> None:
    class MissingUsage:
        def complete(self, model: str, request: LlmRequest) -> LlmResponse:
            return LlmResponse("a completion", "stub/test", 0, 0, 1)

    limits = Limits(2, 65536, 16000, Decimal("1"), Decimal("1"), Decimal("1"), settle_known_usage=True)
    adapter = BoundedAdapter(MissingUsage(), limits, "stub/test")
    adapter.complete("test", LlmRequest("system", "user"))
    assert adapter.reserved == limits.reservation


def test_legacy_runner_does_not_opt_into_provider_cutoff() -> None:
    class Broken:
        calls = 0

        def complete(self, model: str, request: LlmRequest) -> LlmResponse:
            self.calls += 1
            raise LlmError("provider error", retryable=True)

    provider = Broken()
    limits = Limits(5, 65536, 16000, Decimal("1"), Decimal("1"), Decimal("1"))
    evaluate(load_holdout()[:3], provider, "stub/test", limits)
    assert provider.calls == 3
