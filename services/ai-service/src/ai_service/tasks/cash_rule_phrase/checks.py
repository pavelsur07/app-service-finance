"""Предложенный фрагмент должен повторяться на целевых операциях."""

from ai_service.tasks.cash_rule_phrase.schemas import PhraseInput, PhraseOutput
from ai_service.verification.checks import CheckContext, CheckResult, Severity


def repeated_span(inp: PhraseInput, out: PhraseOutput, _ctx: CheckContext) -> CheckResult:
    phrase = out.phrase
    if phrase is None:
        return CheckResult("repeated_span", True, Severity.BLOCK)
    words = phrase.split(" ")
    valid = 1 <= len(words) <= 8 and all(word and "#" not in word for word in words)
    matching = sum(example.target and f" {phrase} " in f" {example.text} " for example in inp.examples)
    return CheckResult("repeated_span", valid and matching >= 2, Severity.BLOCK)
