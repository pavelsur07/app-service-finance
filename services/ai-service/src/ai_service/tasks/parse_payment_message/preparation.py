"""Неподтверждённые предложения становятся вопросами, а не финансовыми фактами."""

from ai_service.tasks.parse_payment_message.checks import verify
from ai_service.tasks.parse_payment_message.schemas import PaymentInput, PaymentOutput
from ai_service.verification.checks import CheckContext

_PREPARABLE = frozenset(
    {
        "paid_unanchored",
        "date_unanchored",
        "date_ambiguous",
        "date_question",
        "direction_contradiction",
        "direction_ambiguous",
        "direction_question",
        "category_direction",
        "execution_question",
        "grouping_question",
        "context_total_amount",
        "ambiguous_occurrence",
    }
)


def _question(questions: list[str], text: str) -> bool:
    if text in questions:
        return True
    if len(questions) >= 50:
        return False
    questions.append(text)
    return True


def prepare_output(inp: PaymentInput, out: PaymentOutput, ctx: CheckContext) -> PaymentOutput:
    if out.intent != "payments":
        return out

    def errors(candidate: PaymentOutput) -> set[str]:
        result = verify(inp, candidate, ctx)
        return set(result.details.split(",")) if not result.passed else set()

    initial = errors(out)
    if not initial or not initial <= _PREPARABLE:
        return out
    prepared = out.model_copy(deep=True)
    categories = {category.id: category.direction for category in inp.categories}
    kept = []
    for row in prepared.items:
        candidate = out.model_copy(update={"items": [row]})
        failed = errors(candidate)
        if failed & {"paid_unanchored", "execution_question"}:
            if "paid_unanchored" in failed:
                row.execution_status = "unknown"
            if not _question(row.questions, "Подтвердите факт оплаты этого платежа."):
                return out
        if failed & {"date_unanchored", "date_ambiguous", "date_question"}:
            row.date = None
            if not _question(row.questions, "Уточните дату этого платежа."):
                return out
        if failed & {"direction_contradiction", "direction_ambiguous", "direction_question"}:
            row.direction = None
            if not _question(row.questions, "Уточните направление: приход или расход?"):
                return out
        if "category_direction" in failed or (
            row.category_id is not None and categories[row.category_id] not in ("any", row.direction)
        ):
            row.category_id = None
            if not _question(row.questions, "Уточните статью ДДС с учётом направления платежа."):
                return out
        remaining = errors(candidate)
        if "context_total_amount" in remaining and remaining <= {"context_total_amount", "ambiguous_occurrence"}:
            if not _question(
                prepared.questions,
                "Справочный итог исключён. Есть ли отдельный платёж, который нужно добавить?",
            ):
                return out
            continue
        kept.append(row)
    prepared.items = kept
    if len(kept) > 1 and not _question(prepared.questions, "Это отдельные платежи или части одного платежа?"):
        return out
    return prepared
