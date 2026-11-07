### Stage 1: сериализация реализации Ozon — DONE

Risk: HIGH-LOCAL. Stage base: `6a6f7d945`.

Обработка raw для всех точек входа и переоткрытие сериализованы с закрытием
месяца через существующий PostgreSQL lock. Начальная обработка и переобработка
атомарны, финальный/заблокированный период защищён. После lock raw перечитан;
непустой header обязан совпасть с полным месяцем документа.

Проверки:
- Baseline: ProcessOzonRealizationRegressionTest — 2 / 8 PASS.
- RED: обе параллельные обработки дали 6 вместо 3 строк и удвоенные суммы.
- RED: 4 регрессии (частичный commit первой обработки, финальное закрытие,
  finance lock, отсутствующий header) подтверждены на старом коде.
- GREEN: Marketplace integration + logging unit — 559 / 2505 PASS.
- Дополнительные real-process tests: 4 / 42 PASS; manual/manual, manual/handler,
  отдельный месяц/компания, перечитывание финального закрытия после ожидания.
- Focused PHPStan Actions/new integration tests — No errors.
- Focused CS и diff check — проверены перед commit.

Internal review complete diff: BLOCKER 0 / IMPORTANT 0. Исправлены безопасные
локальные вопросы: пустой raw остаётся no-op; foreign-company lookup не раскрывает
существование raw; финальная инвалидация cache после переоткрытия.
External review на Stage не требуется: diff <500 строк; обязателен на handoff.
Полные gates не запускались; production не изменён.

Next: Stage 2 автоматически — verifier + одноразовый atomic repair.
