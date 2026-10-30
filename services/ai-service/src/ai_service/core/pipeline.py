"""Общий pipeline задачи: prepare → generate → parse → verify → decide → finalize (ТЗ §5).

Ни одна задача не обходит проверки: шаги заданы здесь, задача поставляет только
схемы, промпт и свои проверки. Запись результата и трассы — у worker'а.
"""

import time
from dataclasses import dataclass, field
from typing import Any

import structlog
from pydantic import BaseModel, ValidationError

from ai_service.core.task import Risk, TaskRegistry, TaskSpec
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.types import CallRecord, LlmRequest
from ai_service.privacy.masking import Masker
from ai_service.verification.checks import CheckContext, CheckResult, Severity, Verdict, decide

log = structlog.get_logger(__name__)

SCHEMA_CHECK = "output_schema"
CONFIDENCE_CHECK = "confidence_range"


@dataclass(slots=True)
class PipelineOutcome:
    verdict: Verdict
    output: dict[str, Any] | None = None
    checks: list[CheckResult] = field(default_factory=list)
    confidence: float | None = None
    models: list[str] = field(default_factory=list)
    prompt_version: str | None = None
    input_tokens: int = 0
    output_tokens: int = 0
    error_code: str | None = None
    trace: dict[str, Any] = field(default_factory=dict)


class Pipeline:
    def __init__(self, registry: TaskRegistry, gateway: LlmGateway) -> None:
        self._registry = registry
        self._gateway = gateway

    def run(self, task_type: str, company_id: str, payload: dict[str, Any]) -> PipelineOutcome:
        """LlmUnavailableError пробрасывается: решение о повторе принимает worker."""
        started = time.monotonic()
        spec = self._registry.get(task_type)
        if spec is None or not self._gateway.has_route(task_type):
            return _failed("unknown_task_type", {"task_type": task_type})
        outcome = self._run(spec, company_id, payload)
        outcome.trace["latency_ms"] = int((time.monotonic() - started) * 1000)
        return outcome

    def _run[In: BaseModel, Out: BaseModel](
        self, spec: TaskSpec[In, Out], company_id: str, payload: dict[str, Any]
    ) -> PipelineOutcome:
        # prepare: вход проверяется до маскирования, модель видит только маскированный.
        try:
            spec.input_model.model_validate(payload)
        except ValidationError as e:
            return _failed("invalid_payload", {"task_type": spec.task_type, "errors": e.error_count()})
        masker = Masker()
        masked_payload = masker.mask_json(payload, spec.mask_skip_keys)
        try:
            masked_input = spec.input_model.model_validate(masked_payload)
        except ValidationError as e:
            # Токен длиннее значения или число стало строкой — схема задачи не допускает маскирования.
            return _failed("masking_invalid", {"task_type": spec.task_type, "errors": e.error_count()})

        trace: dict[str, Any] = {
            "task_type": spec.task_type,
            "prompt_version": spec.prompt.id,
            "risk": spec.risk.value,
            "input": masked_payload,
            "masked_values": masker.replacements,
            "calls": [],
            "responses": [],
        }
        calls: list[CallRecord] = []
        request = LlmRequest(system=spec.prompt.text, user=spec.build_user(masked_input), max_tokens=spec.max_tokens)

        # generate + parse: при невалидном ответе — один повтор с указанием ошибки.
        output: Out | None = None
        parse_error = ""
        for attempt in range(2):
            result = self._gateway.complete(spec.task_type, request)
            calls.extend(result.calls)
            # NUL PostgreSQL не принимает в jsonb: без замены финализация падала бы на трассе.
            text = result.response.text.replace("\x00", "")
            trace["responses"].append({"model": result.response.model, "text": text})
            try:
                output = spec.output_model.model_validate_json(_strip_fences(text))
                break
            except ValidationError as e:
                parse_error = f"{e.error_count()} validation error(s)"
                log.warning("llm_output_invalid", task_type=spec.task_type, attempt=attempt)
                request = LlmRequest(
                    system=request.system,
                    user=request.user
                    + "\n\nПредыдущий ответ не прошёл проверку схемы. Ответь только JSON по описанной схеме.",
                    max_tokens=request.max_tokens,
                )

        trace["calls"] = [_call_dict(c) for c in calls]
        models = [c.model for c in calls if c.ok]
        usage_in = sum(c.input_tokens for c in calls)
        usage_out = sum(c.output_tokens for c in calls)

        if output is None:
            checks = [CheckResult(SCHEMA_CHECK, False, Severity.BLOCK, parse_error)]
            trace.update(checks=[c.as_dict() for c in checks], verdict=Verdict.REJECTED.value)
            return PipelineOutcome(
                verdict=Verdict.REJECTED,
                checks=checks,
                models=models,
                prompt_version=spec.prompt.id,
                input_tokens=usage_in,
                output_tokens=usage_out,
                trace=trace,
            )

        # verify + decide
        context = CheckContext(company_id=company_id, task_type=spec.task_type)
        checks = [CheckResult(SCHEMA_CHECK, True, Severity.BLOCK)]
        checks += [check(masked_input, output, context) for check in spec.checks]
        confidence: float | None = spec.confidence(masked_input, output)
        if confidence is not None and not 0.0 <= confidence <= 1.0:
            checks.append(CheckResult(CONFIDENCE_CHECK, False, Severity.BLOCK, "confidence outside 0..1"))
            confidence = None
        verdict = decide(checks, confidence, spec.confidence_threshold)
        if spec.risk is Risk.HIGH and verdict is Verdict.ACCEPTED:
            verdict = Verdict.NEEDS_REVIEW

        trace.update(checks=[c.as_dict() for c in checks], confidence=confidence, verdict=verdict.value)
        return PipelineOutcome(
            verdict=verdict,
            output=output.model_dump(mode="json") if verdict is not Verdict.REJECTED else None,
            checks=checks,
            confidence=confidence,
            models=models,
            prompt_version=spec.prompt.id,
            input_tokens=usage_in,
            output_tokens=usage_out,
            trace=trace,
        )


def _failed(error_code: str, trace: dict[str, Any]) -> PipelineOutcome:
    return PipelineOutcome(verdict=Verdict.FAILED, error_code=error_code, trace={**trace, "error_code": error_code})


def _call_dict(call: CallRecord) -> dict[str, Any]:
    return {
        "model": call.model,
        "ok": call.ok,
        "error": call.error,
        "latency_ms": call.latency_ms,
        "input_tokens": call.input_tokens,
        "output_tokens": call.output_tokens,
    }


def _strip_fences(text: str) -> str:
    """Модели часто оборачивают JSON в ```json … ```; содержимое не меняется."""
    stripped = text.strip()
    if stripped.startswith("```"):
        stripped = stripped.split("\n", 1)[1] if "\n" in stripped else ""
        if stripped.rstrip().endswith("```"):
            stripped = stripped.rstrip()[:-3]
    return stripped.strip()
