"""marketplace_source_type_draft — черновик решения по новому коду маркетплейса для администратора
платформы (docs/tasks/marketplace-profit-pipeline/DATA.md §9.4). Только черновик: решает человек."""

import json
from pathlib import Path

from ai_service.core.prompt import Prompt, data_block
from ai_service.core.task import Risk, TaskSpec
from ai_service.tasks.marketplace_source_type_draft.checks import draft_confidence, kind_known, kind_or_new_kind
from ai_service.tasks.marketplace_source_type_draft.schemas import DraftInput, DraftOutput

TASK_TYPE = "marketplace_source_type_draft"
PROMPT_VERSION = "v1"

INSTRUCTION = "Предложи вид операции для кода маркетплейса из блока данных."


def build_user(inp: DraftInput) -> str:
    return INSTRUCTION + "\n\n" + data_block(json.dumps(inp.model_dump(mode="json"), ensure_ascii=False))


def build() -> TaskSpec[DraftInput, DraftOutput]:
    return TaskSpec(
        task_type=TASK_TYPE,
        input_model=DraftInput,
        output_model=DraftOutput,
        prompt=Prompt.load(TASK_TYPE, PROMPT_VERSION, Path(__file__).parent / "prompts"),
        risk=Risk.HIGH,
        build_user=build_user,
        checks=(kind_known, kind_or_new_kind),
        confidence=draft_confidence,
        confidence_threshold=0.8,
        # Коды и виды — не ПДн; названия услуг маркетплейса тоже.
        mask_skip_keys=frozenset({"channel", "source_type", "name", "sign", "code", "level", "title", "kind"}),
        max_tokens=1024,
    )
