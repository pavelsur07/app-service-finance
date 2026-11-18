"""Предложение фразы для черновика автоправила ДДС."""

import json
from pathlib import Path

from ai_service.core.prompt import Prompt, data_block
from ai_service.core.task import Risk, TaskSpec
from ai_service.tasks.cash_rule_phrase.checks import repeated_span
from ai_service.tasks.cash_rule_phrase.schemas import PhraseInput, PhraseOutput

TASK_TYPE = "cash_rule_phrase"


def build_user(inp: PhraseInput) -> str:
    return data_block(json.dumps(inp.model_dump(), ensure_ascii=False))


def build() -> TaskSpec[PhraseInput, PhraseOutput]:
    return TaskSpec(
        task_type=TASK_TYPE,
        input_model=PhraseInput,
        output_model=PhraseOutput,
        prompt=Prompt.load(TASK_TYPE, "v1", Path(__file__).parent / "prompts"),
        risk=Risk.HIGH,
        build_user=build_user,
        checks=(repeated_span,),
        max_tokens=256,
    )
