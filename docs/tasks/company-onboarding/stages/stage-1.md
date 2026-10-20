# Stage 1. Cash: «Основной счет» и касса «Основная»

Риск: MEDIUM (новый Action и подписчик на существующем событии; правка Company без
смены контракта). `stage_base_commit`: `7939a22f6`.

## 1. Какую задачу решаем

Новая компания получает два денежных счёта: «Основной счет» (BANK, RUB) и кассу
«Основная» (CASH, RUB). Сейчас создаётся только первый, и создаёт его модуль Company,
импортируя сущность Cash (`CompanyOwnerMembershipCreator`) — это нарушение границы модулей.
Деньги — зона Cash: создание переносится туда, по образцу системных статей ДДС
(`EnsureCashflowSystemCategoriesOnCompanyCreated`).

Решение Phase 0 — без миграции и системного кода. Правило идемпотентности: счёт по
умолчанию создаётся, только если у компании нет ни одного счёта этого типа (активного
или архивного). Уникальный индекс `uniq_company_name (company_id, name)`: если имя уже
занято счётом другого типа — пропуск с warning, создание компании не падает.
`isDefault` остаётся `false`, как сейчас: поведение «счёта по умолчанию» этап не меняет.

## 2. Что будет результатом

- `Cash\Application\EnsureDefaultMoneyAccountsAction(string $companyId): int` — число
  созданных счетов.
- `Cash\EventListener\EnsureDefaultMoneyAccountsOnCompanyCreated` на `CompanyCreatedEvent`.
- `MoneyAccountRepository::existsByCompanyIdAndType()`, `existsByCompanyIdAndName()`.
- `CompanyOwnerMembershipCreator` не импортирует `App\Cash\*`; ID компании, участника,
  проекта — UUID v7 (`CompanyController::new` тоже).
- Тесты: интеграционный на Action, обновлённые unit-тест создателя и функциональный тест
  создания компании.
- `ARCHITECTURE.md`: раздел «Company → Cash», строка `Changelog`.

## 3. Что считаем выполненной работой

Все три пути создания компании (регистрация, админка, `/company/new`) дают ровно два
денежных счёта нужных типа, имени и валюты; повторный вызов Action ничего не создаёт;
Company больше не знает о сущностях Cash; тесты модулей Company и Cash зелёные.

## 4. Чек-лист приёмки

- [x] Новая компания → «Основной счет» BANK RUB и «Основная» CASH RUB, оба активны.
- [x] Повторный вызов Action → 0 созданных, число счетов не меняется.
- [x] Компания с переименованным BANK-счётом → BANK не создаётся.
- [x] Компания без BANK, но имя «Основной счет» занято счётом другого типа → пропуск, warning, без исключения.
- [x] Несуществующая компания → `DomainException`.
- [x] Запросы Repository принимают `string $companyId`.
- [x] `grep "App\\\\Cash" src/Company/Application/Service/CompanyOwnerMembershipCreator.php` пуст.
- [x] Новые ID — `Uuid::uuid7()`.
- [x] `tests/Functional/Company`, `tests/Unit/Company`, `tests/Integration/Cash` зелёные.
- [x] PHPStan и CS по изменённым файлам чистые.
- [x] `ARCHITECTURE.md` обновлён.

## Итог

Выполнено 2026-10-20.

- `EnsureDefaultMoneyAccountsAction` + `EnsureDefaultMoneyAccountsOnCompanyCreated`;
  `MoneyAccountRepository::existsByCompanyIdAndType()` / `existsByCompanyIdAndName()`.
- `CompanyOwnerMembershipCreator` без `App\Cash\*`, ID — UUID v7; `CompanyController::new` — v7.
- Тесты: новый `tests/Integration/Cash/Application/EnsureDefaultMoneyAccountsActionTest`
  (4 сценария: создание и идемпотентность, переименованный архивный счёт, занятое имя,
  неизвестная компания); `CompanyCreateFlowTest` проверяет два счёта после `/company/new`;
  счётчики persist в `CompanyOwnerMembershipCreatorTest`, `CreateCompanyOwnerAccountActionTest`,
  `Admin\CreateAccountActionTest` — 6→5 / 7→6.
- Проверки: `phpunit tests/{Functional,Unit,Integration}/Company tests/{Unit,Integration,Functional}/Cash
  tests/Unit/Admin` → OK (1374 tests, 4424 assertions); PHPStan по 8 изменённым файлам —
  No errors; php-cs-fixer (`.php-cs-fixer.php`, без кеша) — 0 of 8.
- Ревью диффа: BLOCKER/IMPORTANT нет. Других путей создания компании без
  `CompanyCreatedEvent` в `src/` нет.
