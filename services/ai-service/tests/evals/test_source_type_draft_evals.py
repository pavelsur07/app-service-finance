"""Набор черновика вида для нового кода маркетплейса: кейсы валидны и согласованы с промптом, без сетевых вызовов.

Ответ модели здесь не проверяется — набор для живого прогона. Ключевой кейс: начисление продавцу (credit)
по смыслу затраты получает вид этой затраты, а не новый вид (ozon:service:62, 22.11.2026).
"""

import json
from pathlib import Path

import pytest

from ai_service.tasks.marketplace_source_type_draft import task as draft
from ai_service.tasks.marketplace_source_type_draft.schemas import DraftInput

pytestmark = pytest.mark.evals
DIR = Path(__file__).resolve().parents[2] / "evals" / draft.TASK_TYPE
KINDS = json.loads((DIR / "kinds.json").read_text(encoding="utf-8"))
CASES = [json.loads(line) for line in (DIR / "cases.jsonl").read_text(encoding="utf-8").splitlines() if line]


@pytest.mark.parametrize("case", CASES, ids=[c["id"] for c in CASES])
def test_case_is_valid_input_with_known_expected_kind(case: dict) -> None:
    inp = DraftInput.model_validate(case["input"] | {"kinds": KINDS})
    assert case["expected_kind"] in {k.code for k in inp.kinds}


def test_credit_on_cost_kind_is_covered() -> None:
    levels = {k["code"]: k["level"] for k in KINDS}
    assert any(c["input"]["sign"] == "credit" and levels[c["expected_kind"]] == "md1" for c in CASES)


def test_prompt_separates_sign_from_kind() -> None:
    text = draft.build().prompt.text
    assert draft.PROMPT_VERSION == "v2"
    assert "sign не выбирает вид" in text
    assert "компенсация от маркетплейса продавцу, а в списке только затраты" not in text
