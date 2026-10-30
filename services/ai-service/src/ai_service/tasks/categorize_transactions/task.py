"""categorize_transactions — подсказка статьи ДДС для операций, которые не распознали автоправила (ТЗ §11, этап 2)."""

import json
from pathlib import Path

from ai_service.core.prompt import Prompt, data_block
from ai_service.core.task import Risk, TaskSpec
from ai_service.tasks.categorize_transactions.checks import (
    batch_confidence,
    category_in_tree,
    completeness,
    direction_matches,
)
from ai_service.tasks.categorize_transactions.schemas import CategorizeInput, CategorizeOutput

TASK_TYPE = "categorize_transactions"

INSTRUCTION = (
    "Подбери статью ДДС для каждой операции из блока данных. "
    "Статья — только id из списка categories. Если данных недостаточно, верни category_id null."
)


def build_user(inp: CategorizeInput) -> str:
    return INSTRUCTION + "\n\n" + data_block(json.dumps(inp.model_dump(mode="json"), ensure_ascii=False))


def build() -> TaskSpec[CategorizeInput, CategorizeOutput]:
    return TaskSpec(
        task_type=TASK_TYPE,
        input_model=CategorizeInput,
        output_model=CategorizeOutput,
        prompt=Prompt.load(TASK_TYPE, "v1", Path(__file__).parent / "prompts"),
        risk=Risk.LOW,
        build_user=build_user,
        checks=(completeness, category_in_tree, direction_matches),
        confidence=batch_confidence,
        confidence_threshold=0.8,
        # Идентификаторы и суммы — не ПДн, а маскирование цифр испортило бы их (12 цифр похожи на ИНН).
        mask_skip_keys=frozenset({"id", "direction", "amount", "currency", "date"}),
        max_tokens=4096,
    )
