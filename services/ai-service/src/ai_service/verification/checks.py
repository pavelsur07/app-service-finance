"""Проверки результата: функция (input, output, context) → CheckResult с уровнем block/review/info (ТЗ §5)."""

from collections.abc import Callable
from dataclasses import dataclass, field
from enum import StrEnum
from typing import Any


class Severity(StrEnum):
    BLOCK = "block"  # провал → rejected
    REVIEW = "review"  # провал → needs_review
    INFO = "info"  # только в трассу


class Verdict(StrEnum):
    ACCEPTED = "accepted"
    NEEDS_REVIEW = "needs_review"
    REJECTED = "rejected"
    FAILED = "failed"


@dataclass(frozen=True, slots=True)
class CheckResult:
    name: str
    passed: bool
    severity: Severity
    details: str = ""

    def as_dict(self) -> dict[str, Any]:
        return {"name": self.name, "passed": self.passed, "severity": self.severity.value, "details": self.details}


@dataclass(frozen=True, slots=True)
class CheckContext:
    company_id: str
    task_type: str
    extra: dict[str, Any] = field(default_factory=dict)


type Check[In, Out] = Callable[[In, Out, CheckContext], CheckResult]


def decide(checks: list[CheckResult], confidence: float | None, threshold: float) -> Verdict:
    """Вердикт: проваленный block → rejected; review или уверенность ниже порога → needs_review."""
    if any(not c.passed and c.severity is Severity.BLOCK for c in checks):
        return Verdict.REJECTED
    if any(not c.passed and c.severity is Severity.REVIEW for c in checks):
        return Verdict.NEEDS_REVIEW
    if confidence is None or confidence < threshold:
        return Verdict.NEEDS_REVIEW
    return Verdict.ACCEPTED
