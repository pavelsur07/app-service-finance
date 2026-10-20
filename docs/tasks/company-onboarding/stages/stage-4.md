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

- [x] Регистрация и админка → 2 денежных счёта, проект и ЦФО «Общий», 7 статей ДДС, 70 статей ОПиУ, 33 статьи и 2 счёта Баланса.
- [x] Повторный `CompanyCreatedEvent` → счётчики не меняются.
- [x] Сбой подписчика (битый шаблон ОПиУ) → компании нет в БД.
- [x] Dry-run команды ничего не пишет; итоги по пунктам верные.
- [x] `--execute` без `--expected-count` или с неверным → отказ без записи.
- [x] `--execute` дозаполняет только пробелы; свои счета, статьи ОПиУ, счета Баланса не тронуты.
- [x] Повторный запуск → 0 компаний с пробелами.
- [x] Company не импортирует Cash/Finance/Balance; `ModuleBoundaryRules` зелёный.
- [x] Полные гейты: `make site-stan`, `site-cs-check`, `site-cs-strict-types`, `site-test-unit`, `site-test`.
- [x] `ARCHITECTURE.md`, Billing README обновлены.

## Итог

Выполнено 2026-10-20.

- `CompanyOnboardingBackfillEvent`; dry-run в `EnsureDefaultMoneyAccountsAction`,
  `EnsureDefaultPLTreeAction`, `EnsureBalanceAccountsAction`; подписчики
  `BackfillDefaultMoneyAccounts`, `BackfillDefaultPLTree`, `BackfillBalanceSetup`;
  `BackfillCompanyOnboardingAction` + `app:company:onboarding-backfill`.
- Сквозной `CompanyOnboardingTest`: регистрация и админка → полный набор; повторное событие
  → без дублей; битый шаблон ОПиУ → компании нет в БД.
- `BackfillCompanyOnboardingCommandTest` (6): dry-run без записи с отчётом по пунктам;
  `--expected-count` обязателен, неверный и нечисловой — отказ; execute дозаполняет только
  пробелы и не трогает своё; повтор → 0; сбой подписчика → компания откатывается, следующие не
  трогаются; неизвестная компания.
- Финальное ревью в отдельной сессии: BLOCKER нет; IMPORTANT (нет теста останова, dry-run
  занижал счета Баланса) и MINOR (ленивый сид в одной транзакции, сообщение о
  `--expected-count`, unit-тест N+1) исправлены в `cf50dd48e`. Не принято: родовые исключения
  для неизвестной компании — так же у соседних `EnsureCashflowSystemCategoriesAction` и
  `SeedMissingBalanceStructuresAction`.
- Полные гейты (до правок ревью): PHPStan (весь проект, baseline) — No errors; php-cs-fixer и
  strict_types без кеша — 0 of 3202; `phpunit` полный — OK (6941 tests, 39307 assertions).
  После правок: модули Balance, Company, Finance, Cash — OK (1374 tests, 4704 assertions),
  PHPStan и CS по изменённым файлам чистые.
