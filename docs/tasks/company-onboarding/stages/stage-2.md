# Stage 2. Finance: дерево ОПиУ при создании компании

Риск: MEDIUM (новый Action и подписчик; импорт и шаблон существующие, формулы и
коды статей не меняются). `stage_base_commit`: `08f8338fe`.

## 1. Какую задачу решаем

Новая компания должна сразу получать базовое дерево статей ОПиУ
(`config/finance/default_pl_tree.json`, 70 узлов). Сейчас его применяют только вручную:
экран импорта «Базовый шаблон ОПиУ» или `app:finance:pl-tree:apply-default`.

Правило (подтверждённое решение «только где данных этого вида нет совсем»): шаблон
применяется, только если у компании нет ни одной статьи ОПиУ. Дерево, которое
пользователь уже настроил, не дополняется. Ручная команда `pl-tree:apply-default`
сохраняет свою семантику «дополнить недостающим по коду» и не меняется — у неё
другой сценарий (донастройка существующего дерева), поэтому план «перевести команду
на новый Action» отменён.

## 2. Что будет результатом

- `Finance\Application\Action\EnsureDefaultPLTreeAction(string $companyId): int` — число
  созданных статей (0, если у компании уже есть статьи); импорт через
  `ImportPLCategoryTreeAction` в режиме `onlyCreate`.
- `Finance\EventListener\ApplyDefaultPLTreeOnCompanyCreated` на `CompanyCreatedEvent`.
- `PLCategoryRepository::existsByCompanyId(string $companyId): bool`.
- Ошибка шаблона или импорта не глотается: создание компании откатывается целиком
  (вложенная транзакция импорта — savepoint внешней).
- Тесты: интеграционный на Action, функциональный `/company/new` проверяет дерево.
- `ARCHITECTURE.md`: раздел «Finance: перенос дерева категорий ОПиУ», `Changelog`.

## 3. Что считаем выполненной работой

После любого пути создания компании у неё 70 статей ОПиУ шаблона с корнями шаблона;
повторный вызов Action и вызов для компании со своими статьями ничего не создают;
тесты Finance и Company зелёные.

## 4. Чек-лист приёмки

- [x] Новая компания → 70 статей, корни совпадают с шаблоном, коды — с шаблоном.
- [x] Повторный вызов → 0, число статей не меняется.
- [x] Компания с одной своей статьёй → 0, своя статья не тронута.
- [x] Несуществующая компания → `DomainException`.
- [ ] Сбой импорта внутри события → компании нет в БД (сквозной тест — Stage 4).
- [x] `existsByCompanyId` с `Assert::uuid`, scoped по компании.
- [x] Импорт в компанию без статей — без запроса на каждый узел.
- [x] `tests/*/Finance`, `tests/*/Company` зелёные; PHPStan и CS по изменённым файлам чистые.
- [x] `ARCHITECTURE.md` обновлён.

## Итог

Выполнено 2026-10-20.

- `EnsureDefaultPLTreeAction` + `ApplyDefaultPLTreeOnCompanyCreated`;
  `PLCategoryRepository::existsByCompanyId()`.
- N+1: `ImportPLCategoryTreeAction::resolveMatches` для компании без статей не вызывает
  `findExisting` на каждый узел (70 SELECT → 1). Семантика та же: несохранённые узлы в БД
  не видны, поиск и так дал бы null. Unit-тест импорта получил хелпер мока
  (`existsByCompanyId → true`), сценарии не менялись.
- Отклонение от общего плана: `pl-tree:apply-default` не переведена на новый Action —
  у неё другая семантика (дополнить настроенное дерево), она не меняется.
- Тесты: новый `EnsureDefaultPLTreeActionTest` (шаблон один раз и корни шаблона;
  компания со своей статьёй не тронута; неизвестная компания); `CompanyCreateFlowTest`
  проверяет 70 статей после `/company/new`.
- Проверки: `phpunit tests/{Functional,Unit,Integration}/Company tests/{Unit,Functional}/Admin
  tests/{Integration,Unit,Functional}/Finance` → OK (1140 tests, 5705 assertions); PHPStan по
  7 файлам — No errors; php-cs-fixer — 0 of 7.
- Ревью: BLOCKER/IMPORTANT нет. Пункт об откате при сбое закрывает сквозной тест Stage 4.
