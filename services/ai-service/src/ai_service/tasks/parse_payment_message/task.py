"""Review-only payment message extraction through the existing pipeline."""

import json
from pathlib import Path

from ai_service.core.prompt import Prompt, data_block
from ai_service.core.task import Risk, TaskSpec
from ai_service.tasks.parse_payment_message.checks import verify
from ai_service.tasks.parse_payment_message.schemas import PaymentInput, PaymentOutput

TASK_TYPE = "parse_payment_message"
PROMPT_VERSION = "v1"


def build_user(inp: PaymentInput) -> str:
    return data_block(json.dumps(inp.model_dump(mode="json"), ensure_ascii=False))


def build() -> TaskSpec[PaymentInput, PaymentOutput]:
    return TaskSpec(
        task_type=TASK_TYPE,
        input_model=PaymentInput,
        output_model=PaymentOutput,
        prompt=Prompt.load(TASK_TYPE, PROMPT_VERSION, Path(__file__).parent / "prompts"),
        risk=Risk.HIGH,
        build_user=build_user,
        checks=(verify,),
        mask_skip_keys=frozenset(
            {"id", "ref", "project_id", "cfo_id", "direction", "currency", "reference_at", "timezone"}
        ),
        max_tokens=16000,
    )
