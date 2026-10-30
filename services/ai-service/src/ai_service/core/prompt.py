"""Промпты — версионируемые файлы задачи; версия попадает в результат и трассу (ТЗ §2, §5)."""

from dataclasses import dataclass
from pathlib import Path

DATA_OPEN = "<data>"
DATA_CLOSE = "</data>"


@dataclass(frozen=True, slots=True)
class Prompt:
    task_type: str
    version: str
    text: str

    @property
    def id(self) -> str:
        return f"{self.task_type}/{self.version}"

    @classmethod
    def load(cls, task_type: str, version: str, prompts_dir: Path) -> "Prompt":
        return cls(task_type, version, (prompts_dir / f"{version}.md").read_text(encoding="utf-8"))


def data_block(content: str) -> str:
    """Данные клиента — отдельным блоком: модель не трактует их как инструкцию (ТЗ §7).

    Закрывающий тег внутри данных экранируется, чтобы текст не мог «выйти» из блока.
    """
    safe = content.replace(DATA_CLOSE, "</ data>")
    return f"{DATA_OPEN}\n{safe}\n{DATA_CLOSE}"


def extract_data_block(user: str) -> str:
    start = user.find(DATA_OPEN)
    end = user.rfind(DATA_CLOSE)
    if start < 0 or end < start:
        return ""
    return user[start + len(DATA_OPEN) : end].strip("\n")
