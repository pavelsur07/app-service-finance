"""Проверки категоризации (ТЗ §6, MVP): полнота, статья из справочника, направление статьи."""

from ai_service.tasks.categorize_transactions.schemas import CategorizeInput, CategorizeOutput
from ai_service.verification.checks import CheckContext, CheckResult, Severity


def completeness(inp: CategorizeInput, out: CategorizeOutput, _ctx: CheckContext) -> CheckResult:
    """Каждая входная операция — ровно один ответ, лишних нет."""
    expected = {o.id for o in inp.operations}
    answered = [i.operation_id for i in out.items]
    missing = expected - set(answered)
    extra = set(answered) - expected
    duplicated = len(answered) != len(set(answered))
    passed = not missing and not extra and not duplicated
    details = "" if passed else f"missing={len(missing)} extra={len(extra)} duplicated={duplicated}"
    return CheckResult("completeness", passed, Severity.BLOCK, details)


def category_in_tree(inp: CategorizeInput, out: CategorizeOutput, _ctx: CheckContext) -> CheckResult:
    """Статья — только из справочника компании: выдуманная или чужая статья отвергается."""
    known = {c.id for c in inp.categories}
    unknown = sum(1 for i in out.items if i.category_id is not None and i.category_id not in known)
    return CheckResult("category_in_tree", unknown == 0, Severity.BLOCK, "" if unknown == 0 else f"unknown={unknown}")


def direction_matches(inp: CategorizeInput, out: CategorizeOutput, _ctx: CheckContext) -> CheckResult:
    """Статья поступлений у списания (и наоборот) — показать человеку, а не принимать."""
    categories = {c.id: c.direction for c in inp.categories}
    operations = {o.id: o.direction for o in inp.operations}
    mismatched = sum(
        1
        for i in out.items
        if i.category_id is not None
        and categories.get(i.category_id, "any") not in ("any", operations.get(i.operation_id))
    )
    passed = mismatched == 0
    return CheckResult("direction_matches", passed, Severity.REVIEW, "" if passed else f"mismatched={mismatched}")


def batch_confidence(_inp: CategorizeInput, out: CategorizeOutput) -> float | None:
    """Уверенность пачки — минимальная по операциям с ответом; без ответов — None (показать человеку)."""
    answered = [i.confidence for i in out.items if i.category_id is not None]
    return min(answered) if answered else None
