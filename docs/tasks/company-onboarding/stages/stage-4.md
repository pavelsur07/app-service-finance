# Stage 4. Сквозная проверка и дозаполнение существующих компаний

Риск: HIGH-LOCAL (консольная команда, которая на проде пишет в данные; запуск
`--execute` — только по отдельному одобрению Владельца, AGENTS §3.3).
`stage_base_commit`: `97653bd3f`.

## 1. Какую задачу решаем

1. Убедиться, что все три пути создания компании дают полный набор онбординга и что
   сбой любого шага откатывает компанию.
2. Существующие компании созданы до онбординга: у них нет кассы, у части — дерева ОПиУ и
   учётных счетов Баланса. Подтверждённое решение — дозаполнить там, где данных этого
   вида нет совсем. ДДС не дозаполняется: системные статьи у существующих компаний уже
   есть (этап 7.4 плана Cash).

Архитектура дозаполнения: команда модуля Company не вызывает чужие модули, а диспатчит
событие `CompanyOnboardingBackfillEvent(companyId, execute)`; Cash, Finance и Balance
подписываются, как на `CompanyCreatedEvent`, и сообщают в событие, сколько создали бы
(dry-run) или создали. Так нет зависимости Company → Cash/Finance/Balance и цикла.

## 2. Что будет результатом

- `Company\Event\CompanyOnboardingBackfillEvent` — `companyId`, `execute`, `report(item, count)`,
  `gaps(): array<string, int>`.
- Ensure-Action'ы получают `bool $dryRun = false`: `EnsureDefaultMoneyAccountsAction`,
  `EnsureDefaultPLTreeAction`, `EnsureBalanceAccountsAction` (dry-run без записи и без
  блокировки).
- Подписчики: `Cash\EventListener\BackfillDefaultMoneyAccounts`,
  `Finance\EventListener\BackfillDefaultPLTree`, `Balance\EventListener\BackfillBalanceSetup`
  (нет структуры → `EnsureBalanceStructureAction` = структура + счета; есть → счета).
- `Company\Application\BackfillCompanyOnboardingAction` + команда
  `app:company:onboarding-backfill [--company=<uuid>] [--execute --expected-count=N] [-v]`:
  dry-run по всем компаниям (или одной) → итоги по пунктам и число компаний с пробелами;
  `--execute` требует `--expected-count`, равный числу компаний из dry-run; каждая компания —
  своя транзакция; останов на первой ошибке; лог старт/финиш.
- Сквозной тест `tests/Integration/Company/CompanyOnboardingTest`: регистрация и админка
  дают полный набор; повторный `CompanyCreatedEvent` не дублирует; сбой подписчика
  откатывает компанию. `/company/new` покрыт `CompanyCreateFlowTest`.
- Тест команды: dry-run ничего не пишет и считает пробелы; `--execute` без/с неверным
  `--expected-count` отклоняется; `--execute` дозаполняет; повтор — 0 кандидатов.
- `ARCHITECTURE.md`: раздел «Онбординг компании», `Changelog`. `src/Billing/README.md` §4.1 —
  пометка, что пробная подписка не реализована.

## 3. Что считаем выполненной работой

Онбординг проверен сквозным тестом по всем путям, включая откат; команда дозаполнения
готова и покрыта тестом, на проде не запускалась (запуск — отдельное одобрение);
документация описывает онбординг целиком; полный набор гейтов зелёный.

## 4. Чек-лист приёмки

- [ ] Регистрация и админка → 2 денежных счёта, проект и ЦФО «Общий», 7 статей ДДС, 70 статей ОПиУ, 33 статьи и 2 счёта Баланса.
- [ ] Повторный `CompanyCreatedEvent` → счётчики не меняются.
- [ ] Сбой подписчика (битый шаблон ОПиУ) → компании нет в БД.
- [ ] Dry-run команды ничего не пишет; итоги по пунктам верные.
- [ ] `--execute` без `--expected-count` или с неверным → отказ без записи.
- [ ] `--execute` дозаполняет только пробелы; свои счета, статьи ОПиУ, счета Баланса не тронуты.
- [ ] Повторный запуск → 0 компаний с пробелами.
- [ ] Company не импортирует Cash/Finance/Balance; `ModuleBoundaryRules` зелёный.
- [ ] Полные гейты: `make site-stan`, `site-cs-check`, `site-cs-strict-types`, `site-test-unit`, `site-test`.
- [ ] `ARCHITECTURE.md`, Billing README обновлены.

## Итог

_Заполняется по завершении этапа._
