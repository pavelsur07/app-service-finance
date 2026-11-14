"""Review-only payment message extraction through the existing pipeline."""

import json
from dataclasses import replace
from pathlib import Path

from ai_service.core.prompt import Prompt, data_block
from ai_service.core.task import Risk, TaskSpec
from ai_service.tasks.parse_payment_message.checks import reference_date, verify
from ai_service.tasks.parse_payment_message.preparation import prepare_output
from ai_service.tasks.parse_payment_message.schemas import PaymentInput, PaymentOutput

TASK_TYPE = "parse_payment_message"
PROMPT_VERSION = "v5"


def build_user(inp: PaymentInput) -> str:
    data = inp.model_dump(mode="json") | {"reference_local_date": reference_date(inp).isoformat()}
    return data_block(json.dumps(data, ensure_ascii=False))


def build() -> TaskSpec[PaymentInput, PaymentOutput]:
    prompt = Prompt.load(TASK_TYPE, PROMPT_VERSION, Path(__file__).parent / "prompts")
    prompt = replace(
        prompt,
        text=prompt.text
        + "\n\nИспользуй точные типы и enum из обязательной схемы ниже."
        + "\n\nOUTPUT_JSON_SCHEMA\n"
        + json.dumps(PaymentOutput.model_json_schema(), ensure_ascii=False, sort_keys=True),
    )
    return TaskSpec(
        task_type=TASK_TYPE,
        input_model=PaymentInput,
        output_model=PaymentOutput,
        prompt=prompt,
        risk=Risk.HIGH,
        build_user=build_user,
        checks=(verify,),
        mask_skip_keys=frozenset(
            {"id", "ref", "project_id", "cfo_id", "direction", "currency", "reference_at", "timezone"}
        ),
        max_tokens=16000,
        prepare_output=prepare_output,
    )
