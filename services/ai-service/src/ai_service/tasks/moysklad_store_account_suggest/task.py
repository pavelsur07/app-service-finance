"""moysklad_store_account_suggest — предложение счёта Баланса для склада МойСклад
(docs/tasks/moysklad-store-account-suggest/plan.md). Только предложение: назначение принимает человек."""

import json
from pathlib import Path

from ai_service.core.prompt import Prompt, data_block
from ai_service.core.task import Risk, TaskSpec
from ai_service.tasks.moysklad_store_account_suggest.checks import account_known, single_decision, suggestion_confidence
from ai_service.tasks.moysklad_store_account_suggest.schemas import SuggestInput, SuggestOutput

TASK_TYPE = "moysklad_store_account_suggest"
PROMPT_VERSION = "v1"

INSTRUCTION = "Предложи счёт Баланса для склада из блока данных."


def build_user(inp: SuggestInput) -> str:
    return INSTRUCTION + "\n\n" + data_block(json.dumps(inp.model_dump(mode="json"), ensure_ascii=False))


def build() -> TaskSpec[SuggestInput, SuggestOutput]:
    return TaskSpec(
        task_type=TASK_TYPE,
        input_model=SuggestInput,
        output_model=SuggestOutput,
        prompt=Prompt.load(TASK_TYPE, PROMPT_VERSION, Path(__file__).parent / "prompts"),
        # Предложение показывается с пометкой «ИИ»; accepted — уверенное, входит в «Принять все».
        risk=Risk.MEDIUM,
        build_user=build_user,
        checks=(account_known, single_decision),
        confidence=suggestion_confidence,
        confidence_threshold=0.8,
        # Коды счетов — идентификаторы. Названия складов маскируются: ФИО, телефон или ИНН в названии не уходят модели.
        mask_skip_keys=frozenset({"code", "account_code", "excluded"}),
        max_tokens=512,
    )
