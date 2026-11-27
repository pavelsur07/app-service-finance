"""Набор предложения счёта складу МойСклад: кейсы валидны и согласованы с промптом, без сетевых вызовов.

Ответ модели здесь не проверяется — набор для живого прогона (только с app-сервера, через шлюз).
Ключевые кейсы: FBS — не склад маркетплейса; оператор узнаётся по назначенным складам; «не знаю» вместо догадки.
"""

import json
from pathlib import Path

import pytest

from ai_service.tasks.moysklad_store_account_suggest import task as suggest
from ai_service.tasks.moysklad_store_account_suggest.schemas import SuggestInput, SuggestOutput

pytestmark = pytest.mark.evals
DIR = Path(__file__).resolve().parents[2] / "evals" / suggest.TASK_TYPE
ACCOUNTS = json.loads((DIR / "accounts.json").read_text(encoding="utf-8"))
CASES = [json.loads(line) for line in (DIR / "cases.jsonl").read_text(encoding="utf-8").splitlines() if line]


@pytest.mark.parametrize("case", CASES, ids=[c["id"] for c in CASES])
def test_case_is_valid_input_with_consistent_expectation(case: dict) -> None:
    inp = SuggestInput.model_validate(case["input"] | {"accounts": ACCOUNTS})
    expected = case["expected"]
    assert expected["account_code"] is None or expected["account_code"] in {a.code for a in inp.accounts}
    assert not (expected["account_code"] is not None and expected["excluded"])


def test_set_size_and_traps() -> None:
    assert len(CASES) >= 20
    unknown = [c for c in CASES if c["expected"]["account_code"] is None and not c["expected"]["excluded"]]
    assert len(unknown) >= 5, "ловушки «недостаточно данных»"
    assert any(c["input"]["store"]["name"].startswith("FBS") and c in unknown for c in CASES)


def test_stub_answers_from_examples_or_says_unknown() -> None:
    from ai_service.llm.adapters.stub import _store_account

    known = next(c for c in CASES if c["id"] == "fbs-same-operator-k2")
    out = SuggestOutput.model_validate(_store_account(known["input"] | {"accounts": ACCOUNTS}))
    assert out.account_code == "ACC_INV_FF"
    trap = next(c for c in CASES if c["id"] == "trap-city")
    assert SuggestOutput.model_validate(_store_account(trap["input"] | {"accounts": ACCOUNTS})).account_code is None


def test_prompt_keeps_key_rules() -> None:
    text = suggest.build().prompt.text
    assert suggest.PROMPT_VERSION == "v1"
    assert "FBS — товар продавца" in text
    assert "Не угадывай" in text
