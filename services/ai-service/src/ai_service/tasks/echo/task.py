"""echo — диагностика пути Symfony → сервис → Symfony через весь pipeline (контракт ai-task/v1)."""

from pathlib import Path

from ai_service.core.prompt import Prompt, data_block
from ai_service.core.task import Risk, TaskSpec
from ai_service.tasks.echo.checks import echo_matches_input
from ai_service.tasks.echo.schemas import EchoInput, EchoOutput

TASK_TYPE = "echo"


def build() -> TaskSpec[EchoInput, EchoOutput]:
    return TaskSpec(
        task_type=TASK_TYPE,
        input_model=EchoInput,
        output_model=EchoOutput,
        prompt=Prompt.load(TASK_TYPE, "v1", Path(__file__).parent / "prompts"),
        risk=Risk.LOW,
        build_user=lambda inp: "Верни текст из блока данных.\n\n" + data_block(inp.text),
        checks=(echo_matches_input,),
        confidence=lambda _inp, _out: 1.0,
        confidence_threshold=0.5,
        max_tokens=2048,
    )
