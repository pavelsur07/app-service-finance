# ozon-realization-duplicate-repair — handoff

**Branch:** `docs/ozon-realization-duplicate-repair-plan` ·
**PR:** https://github.com/pavelsur07/app-service-finance/pull/2913 · **Base:** master.
**Implementation commits:** `61383f407`, `ec19cd076`, `b2cd4f228`.
**PR:** Ready; master base verified.
**CI:** проверки запускаются автоматически на последнем push; актуальный статус — в PR.
Локальные handoff gates пройдены.

## Result

Production read-only сверка подтвердила точную двойную копию реализации Ozon
за октябрь 2026: 14 258 raw / 28 516 normalized. Маппинги «Выручки с СПП»
корректны. Конкурентная запись воспроизведена на старом коде; наличие именно
исторической гонки на production не доказано журналом перекрывающихся вызовов.
Ожидаемое нетто Ozon 9 266 291,33 ₽ вместо 18 532 582,66 ₽;
с неизменным WB — 9 280 441,14 ₽. Production данные пока не изменены.

## Summary of stages / files

- Stage 1 — общая транзакция и period lock до определения режима обработки,
  row lock/refresh raw, защита финального и финансово заблокированного месяца.
  Основные файлы: `ProcessOzonRealizationAction`, `ReopenMonthStageAction`.
- Stage 2 — фиксированная одноразовая repair Command, IntegrityQuery/DTO,
  shared header parser; проверка канонического регистра через FinanceFacade.
  Адресный ремонт с expected hash восстанавливает исходную кратность raw,
  пересоздаёт финальный sales_returns и проверяет суммы/аналитики/ссылки до commit.
  Кэш инвалидируется после внешнего commit. Повтор проверяет уже исправленное состояние.
- Regression, concurrency и synthetic fixtures проверяют реальную блокировку
  независимых процессов, batch rollback, законные одинаковые строки, 501 rows,
  сохранность WB/by-day/costs, проектов/ЦФО, register/cache и отказы без изменений.
- `ARCHITECTURE.md` обновлён. Baseline сокращён на 7 шаблонов / 8 occurrences,
  новых suppressions нет. Полный порядок production — в `production-runbook.md`.

## Migrations / contracts

Миграций, новых зависимостей, публичного API, Messenger/cron/CI изменений нет.
Новый read-only контракт: `FinanceFacade::isPLRegisterConsistent(companyId, from, to): bool`.
Формулы и category mappings не меняются. Production-wrapper не изменён.

## Checks

- Baseline до изменений: ProcessOzonRealizationRegressionTest — 2 / 8 PASS.
- Старый код RED: точная двойная запись конкурентными процессами; частичный
  commit первого batch; обход закрытого периода/header; review regressions.
- Marketplace + Finance — 749 tests / 3614 assertions PASS.
- `make site-test-unit` — 4547 tests / 25384 assertions PASS до финальных review fixes;
  все дополнительные unit cases входят в окончательный полный прогон.
- Изолированный `make site-test` — 8521 / 47893 PASS; окончательный полный
  `php -d memory_limit=1024M bin/phpunit` после всех runtime fixes —
  **8532 tests / 47908 assertions PASS**, 7m36s, 782.5 MB.
- После последнего перемещения phpDoc теста: header unit 8 / 8 PASS,
  focused PHPStan 11 changed files — No errors.
- `make site-cs-check` и `make site-cs-strict-types` через локальный compose
  override — PASS, 0 changes / 3909 files; CLI command help PASS; diff check PASS.
- Полный `vendor/bin/phpstan analyse --memory-limit=2G` — **No errors**,
  3873 files, project scope/config/baseline без исключений.
- После последнего исправления fixture entrypoint: real-process regression
  **3 tests / 19 assertions PASS**, focused CS 0 changes / 1 file, diff check PASS.

### Verification environment / failed attempts

Использованы только synthetic fixtures и отдельная локальная test DB
`app_test_ozon_realization_repair` (307 migrations), task container
`vf-ozon-realization-isolated-tests`. Production credentials не использовались.
Локальный compose override/helper находятся в /tmp, конфиги приложения/CI не меняются.
Первый полный тестовый прогон в shared app_test завершился exit137 при конкуренции
за ресурсы; fail-fast выявил коллизию фиксированного user ID. Полный изолированный
прогон после исправления окружения прошёл, затем повторён после runtime fixes.
Первый CS нашёл alignment docblock FinanceFacade: исправлено проектным config.
PHPStan parallel workers не завершились; serial composer достиг timeout300s,
serial1G исчерпал память. Последний full run использует serial2G без timeout;
это те же project rules, scope и baseline. Serial2G выявил один запрещённый
`exit()` в тестовом fixture; заменён на run/shutdown/return с исключением при
ошибке команды. Повтор полного project analysis2G прошёл без ошибок, затем
затронутый process test и focused CS прошли. Прежние попытки не заявляются зелёными.

## Reviews

- Полный внутренний review Stage/task + свежая независимая финальная сессия.
  Найдено **BLOCKER 1 / IMPORTANT 3**, все исправлены с RED→GREEN.
  Исправлены loss uncategorized operations, false no-op register/links,
  cached finance lock. Итоговая свежая проверка: **B0 / I0 / M0 remaining**.
- External Claude read-only через штатный script, **1 round**: **B0 / I2 / M1**.
  Обе IMPORTANT (EM закрывался после business refusal; ISO header compatibility)
  воспроизведены RED и исправлены; 11 tests / 15 assertions PASS.
  MINOR dead guard удалён. Результат **fixed without re-run** по §7.2;
  literal REVIEW_GREEN не получен, оснований для обязательного повтора нет.
- External reviewer видел очищенный diff и контекст схемы/обезличенных замеров;
  не имел production/secrets/write access. Отклонённых findings нет.

Review metrics: internal BLOCKER1 / IMPORTANT3; external BLOCKER0 / IMPORTANT2,
1 round; remaining BLOCKER0 / IMPORTANT0.

## Risks, limitations, follow-ups

Production всё ещё содержит ошибочные суммы. Обычный recalc не является ремонтом
финального месяца; execute проходит только при точном 2x, неизменившемся hash,
отсутствии finance lock и сохранении всех проверяемых аналитик. При любом ином
состоянии — отказ/rollback и адресная диагностика, без обхода финансовых правил.
Не проведены production backup, установка временного wrapper, execute и acceptance:
для них необходимы отдельные named approvals AGENTS.md §3.3.
Backup и временное разрешение обеспечивает владелец/DevOps; агент не обходит
restricted wrappers. Полные команды/контрольные суммы — production-runbook.md.

## Owner decision

Ready: PR #2913 "fix(marketplace): предотвратить дубли реализации Ozon и восстановить октябрь" — merge into master with automatic production deploy?
Reply: "merge and deploy #2913"

Отдельно требуются named approvals: резервная копия target rows; установка и
последующее удаление узкого временного wrapper; один execute с хешем
`304dd45f03283e2973b9b78e50b40635`. Merge/deploy сам по себе их не разрешает.
