# PaymentIntake — реализация и результаты проверок

Branch `docs/telegram-payment-intake-examples`; [Draft PR #2985](https://github.com/pavelsur07/app-service-finance/pull/2985), base `master`.
Task base `8a1f51cac4dacac4d49dd6b5cf72e0940d26a895`.
Версия последних ручных live benchmarks: `99cf27cde6b1d0fa7213b008d11506466d70a091`, prompt v5.
Расширение nightly/source прошло module checks; итоговые handoff gates и ревью выполняются.

## Результат

Свободный текст Telegram, сокращения и несколько платежей превращаются в
проверяемые черновики. Модель предлагает статьи ДДС, проекты и ЦФО из переданных
справочников; Symfony проверяет права и сохраняет деньги только после явного
подтверждения. Фото исключены v1. Неизвестное не становится финансовым фактом.

| Этап | Результат | Приёмка |
|---|---|---|
| 1 | Контракт, 41 обезличенный текст и partial annotations | [ТЗ](stages/stage-1-spec.md), [отчёт](stages/stage-1.md) |
| 2 | Python TaskSpec, Decimal/source-evidence/privacy checks | [ТЗ](stages/stage-2-spec.md), [отчёт](stages/stage-2.md) |
| 3 | Durable Cash drafts, ручные правки, atomic/idempotent Confirm и recovery | [ТЗ](stages/stage-3-spec.md), [отчёт](stages/stage-3.md) |
| 4 | Telegram actor/company/source binding, карточки, уточнения и delivery recovery | [ТЗ](stages/stage-4-spec.md), [отчёт](stages/stage-4.md) |
| 5 | Интеграция, output preparation, четыре живых прохода; полная pilot acceptance открыта | [ТЗ](stages/stage-5-spec.md), [отчёт](stages/stage-5.md) |

## Текущее поведение и файлы

- `services/ai-service/src/ai_service/tasks/parse_payment_message/`: immutable
  prompt v5, structured output, строгий verifier и безопасная preparation.
  Неподтверждённые дата/направление/paid → null/unknown и вопросы; доказанный
  справочный итог исключается с уведомлением. Ошибки денег/валют/evidence,
  чужие ID, forbidden pairs и независимые коллизии остаются отказом.
- `core/task.py`, `core/pipeline.py`: optional prepare_output только для этой
  задачи; затем повторные outputschema и полный verify. Остальные tasks defaultNone.
- `reference_local_date` вычисляется общей с verifier функцией во внутреннем
  model data block. Public PaymentInput не расширяется; дата по умолчанию
  платежу не присваивается. Prompt сохраняет независимость аналитических полей.
- `src/ai_service/evals/payment_intake*.py`, `evals/parse_payment_message/`:
  source и synthetic dataset, раздельные метрики, exact source occurrence
  alignment, wrong-intent guard, версия и hashes runtime. Model не видит gold.
- `site/src/Cash/`, `site/src/Telegram/`, `site/src/ServiceGateway/`:
  текущие финансовые/авторизационные проверки, durable draft/revision/attempt,
  stable IDs, explicit Confirm, targeted pull и ack после commit.
- `site/tests/Fixtures/Cash/payment-intake-prepared.json`: общая synthetic fixture
  Python → Cash; отдельный Telegram manual-paid/ack/Confirm test.

## Живая проверка

Владелец разрешил до четырёх проходов тем же ключом, с прежним ограниченным
root-SSH исключением и общим estimated300RUB. Выполнено4/4, новых вызовов не осталось
по количеству проходов, даже при неиспользованном call headroom.

| Проходы | Source | Synthetic | Результат |
|---|---|---|---|
| 1, v4 + preparer | 49/49 кандидатов, 47/47 сумм, 7/7 направлений | 9/13 exact rows | Обнаружены CFO/pair/date ошибки |
| 2–4, v5 + preparer + derived date | На каждом49/49,47/47,7/7 | На каждом13/13 exact rows | Нет misses по измеряемым метрикам |

На каждом из проходов2–4: category13/13, project12/12, CFO12/12, date13/13,
null abstention9/9, pair abstention1/1, required-question presence9/9.
В forbidden-pair примере project/CFO оцениваются как допустимые tuples без
навязывания приоритета; индивидуальные denominators его не включают.
212 новых calls; вся кампания 375 calls / 32.74599378 RUB usage estimate, не invoice.
Fresh before/after всех четырёх совпали; собственных eval containers0.
[Полный отчёт с hashes/latency/ограничениями](live-eval-four-passes-2026-11-14.md).

41 исходное сообщение и12 synthetic cases использованы при настройке.
Независимый holdout, реальная analytics/date accuracy, source precision,
качество вопросов, живой Telegram E2E и queue/SLA этим не доказаны.
Исторический полный v4 до preparer:46/49,44/47,6/7,3rejected — сохранён
в [истории](live-eval-result-2026-11-14.md), не подменяется новым результатом.

## Проверки и review

- Текущий Python source: `make ai-service-lint` — Ruff/format98, mypy57 PASS;
  `make ai-service-test` —406PASS/1skip; `make ai-service-evals` —9PASS/1skip.
  Skip: site не смонтирован для одного cross-service test / нет eval keys.
- Cash/Telegram:66tests/434assertionsPASS; focused PHPStan/canonicalCS/strict
  двух изменённых тестов PASS. PHP-файлы с того запуска не менялись.
  Повторный Confirm даёт одну операцию и не вызывает новый AI parse.
- Четыре timezone tests provenRED→GREEN; source/semantic scorer и critical
  preparation regressions ранее provenRED→GREEN. Полные Python gates
  выполнены после последнего изменения runtime, для неизменных повторов не дублировались.
- Исторический полный PHP suite8942tests/50254assertionsPASS; CI4160b4e08
  full PHP/Python/empty-DB PASS. Это не CI текущей головы. Local full PHPStan
  ранее ограничен OOM; baseline не расширялся. Полные handoff gates для
  финального выпуска ещё требуются, не повторяются на каждом work item.
- Focused internal review runtime99cf27cde6 и bounded four-pass controller:
  B0/I0/M0. Reviewer read-only, тяжёлые тесты не повторял.
  Итоговый review отчёта записывается в checkpoint/PR.
- Исторический external review:5rounds,B1/I15observations; подтверждённые
  исправлены, последний handoff результат fixed without re-run, не новый
  REVIEW_GREEN. На work items external не запускался; Stage5/handoff
  обязательное review не отменено.

## Выпуск и оставшаяся приёмка

`ai-task/v1` не меняется; отдельный `ai-parse-payment-message/v1`.
Две additive migrations: Version20261113120001 (Telegram actor/session) и
Version20261113171344 (Cash draft/receipt). Local up/down/up и schema checks
описаны в Stage reports; старые денежные записи не преобразуются.
Down удаляет новые metadata, поэтому production rollback — выключение flag
с сохранением таблиц, а не автоматическое удаление данных.

Production migrations, worker/флаг и deploy этой задачей не выполнялись.
`TELEGRAM_PAYMENT_INTAKE_ENABLED=0`. Нужны согласованный охват пилота,
worker нового транспорта и отдельный migration dispatch по
`docs/workflow/release.md`; [runbook](runbook.md).
Flag глобальный плюс существующие AI grants; intake company allowlist пока нет.
Для узкого пилота сначала требуется company gate, не изменение общих grants.
Живой Telegram E2E и независимая проверка реальных аналитик остаются открытыми.
Stage5 целиком не закрыт; PR остаётся Draft. Merge/deploy не запрашивается в этом
checkpoint. Все четыре разрешённых прохода закончены, пятый не запускается.
