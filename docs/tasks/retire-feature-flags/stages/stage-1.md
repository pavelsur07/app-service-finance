### Stage 1: ИИ и SourceFacts — DONE

Risk: HIGH-LOCAL. Stage base: `0e14475c9`.
Work items: 1.1 AiServiceAccess, 1.2 SourceFacts.

- Удалён общий флаг ИИ; URL/token, admin grant, platform exception сохранены.
- SourceFacts принимает все действительные подключения; health не выключается scope.
- RED: два теста упали на старом коде (false вместо true; 0 вместо 2 пачек).
- GREEN: ServiceGateway/SourceFacts — 263 tests, 1186 assertions.
- Baseline: deployment shell + 10 Python PASS; gateway 184 unit PASS;
  SourceFacts/Telegram 74 tests PASS.
- Focused CS выполнен; посторонние форматные правки откатили.
- Focused PHPStan: `src/SourceFacts`, `AiServiceAccess` (без unmatched чужого baseline).
- Внутреннее ревью полного diff: BLOCKER 0, IMPORTANT 0; company mapping,
  quarantine, commit-before-ack и platform exception проверены.
- Внешнее ревью Stage не требуется: diff <500 строк. Обязательное на handoff.
- Следующий шаг: Stage 2 автоматически.
