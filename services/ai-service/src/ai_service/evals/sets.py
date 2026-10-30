"""Eval-наборы категоризации: синтетический (корень каталога задачи) и из истории (`history/<имя>/`).

Набор — справочник статей в форме `categorize_payload` и кейсы «операция → ожидаемая статья»;
`expected_category_id: null` — ловушка «недостаточно данных».
"""

import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any

CATEGORIES_FILE = "categories.json"
CASES_FILE = "cases.jsonl"
HISTORY_DIR = "history"


@dataclass(frozen=True, slots=True)
class EvalSet:
    name: str
    categories: list[dict[str, Any]]
    cases: list[dict[str, Any]]


def load_sets(task_dir: Path) -> list[EvalSet]:
    sets: list[EvalSet] = []
    if (task_dir / CATEGORIES_FILE).is_file():
        sets.append(_load("synthetic", task_dir))
    history = task_dir / HISTORY_DIR
    if history.is_dir():
        sets.extend(_load(f"{HISTORY_DIR}/{d.name}", d) for d in sorted(history.iterdir()) if d.is_dir())
    return sets


def write_set(directory: Path, categories: list[dict[str, Any]], cases: list[dict[str, Any]]) -> None:
    directory.mkdir(parents=True, exist_ok=True)
    (directory / CATEGORIES_FILE).write_text(
        json.dumps(categories, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    (directory / CASES_FILE).write_text(
        "".join(json.dumps(c, ensure_ascii=False) + "\n" for c in cases), encoding="utf-8"
    )


def _load(name: str, directory: Path) -> EvalSet:
    categories = json.loads((directory / CATEGORIES_FILE).read_text(encoding="utf-8"))
    lines = (directory / CASES_FILE).read_text(encoding="utf-8").splitlines()
    return EvalSet(name, categories, [json.loads(line) for line in lines if line])
