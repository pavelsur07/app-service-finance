# Telegram payment intake — локальная реализация

**Branch:** `docs/telegram-payment-intake-examples` ·
**PR:** https://github.com/pavelsur07/app-service-finance/pull/2985 · **Draft**.
Task base: `8a1f51cac4dacac4d49dd6b5cf72e0940d26a895`.

## Результат по этапам

| Этап | Результат | Приёмка |
|---|---|---|
| 1 | Контракт v1, 41 обезличенный текст, partial gold и контексты компаний | [ТЗ](stages/stage-1-spec.md), [отчёт](stages/stage-1.md) |
| 2 | Задача Python внутри ai-service, структурированный вывод, Decimal/evidence checks | [ТЗ](stages/stage-2-spec.md), [отчёт](stages/stage-2.md) |
| 3 | Cash drafts, правки, atomic/idempotent Confirm, recovery и бюджеты | [ТЗ](stages/stage-3-spec.md), [отчёт](stages/stage-3.md) |
| 4 | Telegram actor/source binding, карточки, уточнения, durable polling/delivery | [ТЗ](stages/stage-4-spec.md), [отчёт](stages/stage-4.md) |
| 5 | Локальная общая проверка и подготовка живого eval | [ТЗ](stages/stage-5-spec.md), [отчёт](stages/stage-5.md); живой eval pending |

## Что изменено

- `services/_contracts/ai-parse-payment-message/v1/`,
  `services/ai-service/src/ai_service/tasks/parse_payment_message/`:
  извлечение кандидатов и проверка literal evidence без float. Модель предлагает
  аналитику из переданных справочников; не пишет деньги и не выдумывает ID.
- `site/src/Cash/{Application,Entity,Repository}/PaymentIntake/`, `CashFacade`:
  долговечный черновик, stable item/transaction IDs, revision/attempt,
  текущие права/аналитика/период, atomic Confirm, source/reply replay guards.
- `site/src/Telegram/`: actual app actor в привязке, выбор компании,
  ответы и corrections, source edits, очередь карточек, безопасное восстановление.
- `site/src/ServiceGateway/`: targeted company/task pull через существующие
  consumer/ai_usage transaction и ack после commit; dedicated intake transport.
- `ARCHITECTURE.md`, dev Compose/profile и config описывают текущие контракты.
  User deletion/access и synthetic fixtures согласованы с новыми metadata.

## Схема и контракты

`ai-task/v1` не меняется; новая самостоятельная `ai-parse-payment-message/v1`.
Новый внутренний GET `/internal/tasks/{taskId}/result?company_id=...` использует
существующий authenticated results_page, неизвестный/pending/чужой/acked → empty.
Новых публичных API или frontend нет.

- `Version20261113120001`: nullable actual actor в links/bindings, выбор компании,
  таблица Telegram session. Final up/down/up: PASS23 SQL, старая строка сохранена.
- `Version20261113171344`: Cash draft и reply receipt, global unique source/reply,
  company/draft indexes и receipt FK CASCADE. Старые денежные записи не меняются.
- Doctrine mapping/schema: только существующий fixture test_money_holder вне
  миграций; Cash/Telegram расхождений нет. Down удаляет новые черновики и metadata,
  поэтому для production rollback выключается flag, таблицы сохраняются.

## Проверки и review

- `make site-test`: **8942 tests / 50254 assertions PASS**, 11:32.694,
  до последних четырёх PHP regression tests handoff review.
- Full canonical CS/strict types: **0/4153**; оба новых entity builders и
  unit helper также проверены focused PHPStan.
- Python current: lint/format/mypy55 GREEN; **295 passed / 1 skipped**;
  offline eval9 passed / 1 skipped; targeted verifier/runner64 passed.
- Local full PHPStan: incomplete worker / host OOM (не green). CI0984d7b62
  full static и остальные PHP gates PASS; baseline не расширялся.
- Late fixes: Cash TRANSFER2 tests/5 assertions, Telegram2/16, focused Stan/CS
  PASS; Python sign/runner regression RED→GREEN. Итоговый CI текущей головы PR
  указывается в PR description и финальном сообщении после проверки.

Stage2 external1: B0/I6 (5 подтверждены; ошибочное missing-tracked-files
отклонено по git, valid unused-context subfinding исправлен). Stage3 external1:
B0/I3, все исправлены. Stage4 external1: B1/I4, все исправлены; обязательный
external2: B0/I1, queue docs/local worker исправлены, safe MINOR тоже.
Результаты без повторного маркера честно записаны fixed without re-run.
Последний fresh internal B0/I3, все три независимо проверены закрытыми;
open B0/I0/M0. Разные раунды могут описывать один дефект и не суммируются как
уникальные находки. Финальный external handoff: один раунд B0/I1/M3; подтверждённые исправления
проверены targeted tests и fresh targeted internal review, open B0/I0. Повтор не обязателен после IMPORTANT/MINOR,
результат **fixed without re-run**, не REVIEW_GREEN.

## Ограничения и следующий разрешённый шаг

Флаг `TELEGRAM_PAYMENT_INTAKE_ENABLED=0` по умолчанию. Фото/OCR исключены v1.
Short amounts/группировка/личные средства/неизвестный факт остаются вопросами;
точность модели, latency p95 и стоимость ещё не подтверждены живым eval.
Никаких live API или production изменений/processing не выполнялось.
Flag глобальный + существующие AI grants; отдельного intake allowlist нет.
Для более узкого независимого пилота сначала нужен company gate, а не молчаливое
изменение общих AI grants. Доля черновиков с provenance=user измерима, полная
история частоты событий редактирования не сохраняется; см. observability runbook.

Следующий шаг — отдельно разрешённый ограниченный live eval на обезличенных
41 сообщениях с выбранными provider/model/лимитами и локальным test token.
Stage5 нельзя полностью закрыть/объявить пилот готовым до этого измерения.
[Runbook](runbook.md) содержит recovery, очередь, условия включения и откат.

После успешного live eval для выпуска нужны отдельный production worker нового
транспорта, согласованная группа пилота, production migrations и deploy.
Миграционный запрос по docs/workflow/release.md: «run the migration and deploy
#2985». На текущем этапе merge/deploy не запрашивается; PR остаётся Draft.

Review metrics: independent internal B0/I12 по трём исходным сессиям (4+5+3),
runner self-review B0/I1; external B1/I15 по пяти раундам, open B0/I0.
Суммы — наблюдения review, возможны повторы, не число уникальных дефектов.
Task commits до final Stage5: dbfc6d5ff, 1b326aa9a, 0264aed23, 5fff92c58,
0984d7b62; final code commit доступен в голове той же ветки/PR.

Final runtime code: `7031b52992cbf7fadc6cbb27a516c987c5dcd73c`.
Automatic PR CI did not start for that push. Manual workflow43500484323 with
production_action=none skipped every path gate; its SUCCESS is explicitly not
accepted as a test/static result. A documentation checkpoint push retriggers
normal PR validation; actual current-head receipt belongs in PR/final response.
