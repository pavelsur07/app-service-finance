"""Проверки черновика: вид только из списка; новый вид — только когда вид не выбран."""

from ai_service.tasks.marketplace_source_type_draft.schemas import DraftInput, DraftOutput
from ai_service.verification.checks import CheckContext, CheckResult, Severity


def kind_known(inp: DraftInput, out: DraftOutput, _ctx: CheckContext) -> CheckResult:
    """Выдуманный вид отвергается: администратор выбирает из существующих."""
    passed = out.kind is None or out.kind in {k.code for k in inp.kinds}
    return CheckResult("kind_known", passed, Severity.BLOCK, "" if passed else f"unknown={out.kind}")


def kind_or_new_kind(_inp: DraftInput, out: DraftOutput, _ctx: CheckContext) -> CheckResult:
    """Ровно одно из двух: вид из списка или предложение нового вида."""
    passed = (out.kind is None) != (out.new_kind is None)
    return CheckResult("kind_or_new_kind", passed, Severity.REVIEW, "" if passed else "both or none")


def draft_confidence(_inp: DraftInput, out: DraftOutput) -> float | None:
    return out.confidence if out.kind is not None else None
