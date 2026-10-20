# Stage 3. Balance: базовые учётные счета

Риск: MEDIUM (формат шаблона и сид Баланса; суммы, проводки и книга не меняются).
`stage_base_commit`: `cd716bf66`.

## 1. Какую задачу решаем

Новая компания получает книгу и структуру статей Баланса, но ни одного учётного счёта
(`balance_accounts`): их пользователь создаёт вручную. Нужна базовая настройка — два
счёта на статьях денежных средств:

| Статья (code) | Счёт (code) | Название |
|---|---|---|
| `CASH_BANK` «Расчётные счета» | `ACC_MAIN_BANK` | Расчётный счёт (основной) |
| `CASH_REGISTER` «Касса» | `ACC_MAIN_REGISTER` | Касса (основная) |

Отрицательный остаток запрещён (`allow_negative = false`), как у счёта по умолчанию в
форме. Книга не инициализируется: дату начала и входящие остатки задаёт пользователь.

Правило (подтверждённое решение): счета создаются, только если у компании нет ни одного
учётного счёта. Статья отсутствует, архивна или не конечная (структура версии 1,
пользователь её менял) — этот счёт пропускается, один агрегированный warning.

## 2. Что будет результатом

- Шаблон `config/balance/default_structure.yaml` версии 3: у конечной статьи
  необязательный список `accounts: [{code, name, allow_negative?}]`.
- `BalanceStructureTemplateReader` (VERSION = 3) читает и проверяет `accounts`: только у
  `kind: article`, `code` по формату статей и уникален среди счетов файла, `name` 1–255,
  `allow_negative` — bool, по умолчанию false. DTO `BalanceStructureTemplateAccount`,
  поле `accounts` в `BalanceStructureTemplateNode`.
- `Balance\Application\EnsureBalanceAccountsAction(string $companyId): int` — число
  созданных счетов; запись как у `BalanceStructureService::saveAccount` (`balance_accounts`
  + `balance_account_states` с нулевым остатком + аудит `system_seed`), под блокировкой
  книги, в DBAL-транзакции.
- `SeedBalanceStructureOnCompanyCreated` вызывает его после сида структуры.
- Тесты: reader (валидный шаблон со счетами, ошибки `accounts`), интеграционный на
  Action, функциональный `/company/new` проверяет два счёта.
- `ARCHITECTURE.md`: раздел Balance (шаблон версии 3, счета), `Changelog`.

## 3. Что считаем выполненной работой

Новая компания после любого пути создания имеет два учётных счёта на нужных статьях с
нулевым состоянием и записью аудита; повторный вызов и компания со своим счётом ничего
не создают; статья без пары пропускается без исключения; тесты Balance и Company зелёные.

## 4. Чек-лист приёмки

- [x] Шаблон v3 читается, 33 статьи и 2 счёта; ошибки `accounts` отклоняются с причиной.
- [x] Новая компания → `ACC_MAIN_BANK` на `CASH_BANK`, `ACC_MAIN_REGISTER` на `CASH_REGISTER`, состояние 0, аудит `system_seed`.
- [x] Повторный вызов → 0.
- [x] Компания со своим счётом → 0, свой счёт не тронут.
- [x] Статья `CASH_REGISTER` архивна → создан только `ACC_MAIN_BANK`, без исключения.
- [x] Книга остаётся `initialized = false`.
- [x] Все запросы с `company_id`.
- [x] `tests/*/Balance`, `tests/*/Company` зелёные; PHPStan и CS по изменённым файлам чистые.
- [x] `ARCHITECTURE.md` обновлён.

## Итог

Выполнено 2026-10-20.

- Шаблон v3 (`accounts` у `CASH_BANK`, `CASH_REGISTER`); `BalanceStructureTemplateReader`
  VERSION 3 с проверкой `accounts`; DTO `BalanceStructureTemplateAccount`.
- `EnsureBalanceAccountsAction`; вызывается из `SeedBalanceStructureOnCompanyCreated` и —
  дополнение по итогам ревью — из ленивого `EnsureBalanceStructureAction`, когда структура
  создаётся сейчас (старые компании при первом открытии Баланса получают и счета).
- Тесты: reader — счета шаблона по умолчанию, чтение опций, 6 новых ошибочных случаев;
  `EnsureBalanceAccountsActionTest` — 4 сценария (создание и повтор, свой счёт, архивная
  статья, нет книги); `CompanyCreateFlowTest` (`/company/new`) и
  `CreateCompanyOwnerAccountActionBalanceSeedTest` (регистрация) проверяют два счёта;
  счётчики аудита `system_seed` уточнены до `object_type='book'`.
- Проверки: `phpunit tests/{Unit,Integration,Functional}/Balance tests/{Functional,Unit,Integration}/Company
  tests/{Unit,Functional}/Admin` → OK (936 tests, 2735 assertions) до правки ленивого пути,
  после — Balance OK (183 tests, 725 assertions); PHPStan по 11 файлам — No errors;
  php-cs-fixer — 0 of 11.
- Ревью: BLOCKER/IMPORTANT нет; IMPORTANT «ленивый сид без счетов» исправлен.
