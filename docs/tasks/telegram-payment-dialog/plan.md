# telegram-payment-dialog: управляемый ввод списка платежей

Task base: `c3286aabe`. Класс Large, cross-module Cash/Telegram.
Baseline: `docker exec vf-intake-dialog-php php bin/phpunit --filter PaymentIntake`
— PASS: 136 tests / 992 assertions (17.11.2026).
Эталоны: PaymentIntakeAction (Cash transaction/reply receipts), Telegram
PaymentIntakeSession (durable delivery), Telegram webhook main menu.

## Stage 1: управление и ограниченная доставка
Risk: HIGH-LOCAL. stage_base_commit: `c3286aabe`.
DoD: своё активное состояние доступно из меню, отмена гасит pending delivery,
отменённая доставка не возобновляется; существующие race/recovery тесты зелёные.
Компактная доставка без повторного списка вводится в Stage 3 вместе с UI.
Work items: 1.1 RED tests и управление; 1.2 доставка/отмена, self-review.
Checks: PaymentIntake Telegram functional/unit, PHP lint и focused PHPStan.
Reviewer focus: company/actor isolation, stale callbacks, concurrent cancel/send,
generation/request-token fencing, отсутствие повторных LLM calls.

## Stage 2: пакетные уточнения
Risk: HIGH-LOCAL. stage_base_commit: `5467b7b49`.
DoD: date/project/CFO/direction/status ответы на сводку применяются атомарно
ко всем неисключённым строкам; общий reply идемпотентен; явно заданная дата
не заменяется датой сообщения; unresolved financial questions сохраняются.
Work items: 2.1 RED tests batch Cash/reply; 2.2 Telegram common-field parser
и routing; 2.3 явные общие параметры исходного списка и безопасность.
Checks: Cash/Telegram PaymentIntake tests, focused lint/PHPStan.
Reviewer focus: no guessed money/year/accounting policy, validation all rows,
receipt replay, permissions, preserve unrelated questions/manual selections.

## Stage 3: проверка и завершение списка
Risk: MEDIUM. stage_base_commit: записать после Stage 2.
DoD: компактная сводка с общими вопросами и доступом к деталям, короткие
кнопки, итоги по валюте/направлению, явное подтверждение сохранения.
Work items: 3.1 RED presentation tests → UI; 3.2 docs, full gates and reviews.
Checks: module tests, then once `make site-stan`, `make site-cs-check`,
`make site-cs-strict-types`, `make site-test-unit`, `make site-test`.
Reviewer focus: mobile readability, pending/empty/failed/closed states,
no mutation on view, bounded callbacks, correctness of summaries.

В конце каждого Stage: полный diff review, report, commit/push Draft PR;
HIGH-LOCAL diff >500 lines — отдельное внешнее ревью. На handoff — fresh
internal review и external read-only Codex fallback (Claude CLI отсутствует).
