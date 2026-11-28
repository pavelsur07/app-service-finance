"""Проверки предложения: счёт только из списка компании; счёт и «не учитывать» вместе — нельзя."""

from ai_service.tasks.moysklad_store_account_suggest.schemas import SuggestInput, SuggestOutput
from ai_service.verification.checks import CheckContext, CheckResult, Severity


def account_known(inp: SuggestInput, out: SuggestOutput, _ctx: CheckContext) -> CheckResult:
    """Выдуманный счёт отвергается: счёт под склад не создаётся."""
    passed = out.account_code is None or out.account_code in {a.code for a in inp.accounts}
    return CheckResult("account_known", passed, Severity.BLOCK, "" if passed else f"unknown={out.account_code}")


def single_decision(_inp: SuggestInput, out: SuggestOutput, _ctx: CheckContext) -> CheckResult:
    passed = not (out.account_code is not None and out.excluded)
    return CheckResult("single_decision", passed, Severity.BLOCK, "" if passed else "account and excluded")


def suggestion_confidence(_inp: SuggestInput, out: SuggestOutput) -> float | None:
    """«Не знаю» не бывает уверенным: без решения уверенности нет."""
    return out.confidence if out.account_code is not None or out.excluded else None
