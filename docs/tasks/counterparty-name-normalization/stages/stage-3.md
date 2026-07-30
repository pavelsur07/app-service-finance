## Stage 3: Поиск — Query и endpoint автокомплита — DONE

**Риск:** 🟡 MEDIUM
**Owner gate:** no (Release Gate 2 объявляется после Stage 3 в plan.md §13)
**Release candidate:** yes
**Independently deployable:** no (зависит от backfill из Stage 2)
**Следующее действие:** 🛑 STOP — Release Gate: решение Владельца по Draft PR и Stage 4

### Scope Stage
- Stage base commit: `89d0e5365e5cc5e8b5acb762afcccae45bd2c0ce`
- Work items completed: `3.1`, `3.2`, `3.3`

### Что сделано
- `CounterpartySearchQuery` (DBAL, скаляры, явные колонки):
  - только цифры → префиксный поиск по `inn`, точное совпадение ранжируется выше;
  - иначе запрос проходит **тот же** нормализатор, что и сохранённое название, и
    ищется по `name_core` (точное → префикс → `similarity`);
  - порог задан явным условием `similarity(...) > 0.3`, а не оператором `%`: тот
    зависит от сессионной `pg_trgm.similarity_threshold` и вёл бы себя по-разному
    в тестах и на проде;
  - `company_id` и `is_archived = false` в каждом запросе, жёсткий `LIMIT 20`;
  - запрос короче 2 символов не доходит до SQL.
- `GET /api/counterparties/search?q=` — companyId только из `ActiveCompanyService`,
  ответ `[{id, name, inn, kpp, type}]`, без пагинации (осознанное отступление от
  Pagerfanta-конвенции, ТЗ §7.2).
- `legalFormHint` в ответе endpoint'а отсутствует: по ТЗ §3.3 подсказку нельзя
  показывать пользователю как статус, а пикеру для строки результата нужны
  название и ИНН.
- Форма операции ДДС больше не предлагает архивных контрагентов
  (`findSelectableByCompany`). Уже выбранный архивный контрагент остаётся в списке —
  иначе правка старой операции молча теряла бы ссылку.

### Затронутые файлы
- `src/Company/Infrastructure/Query/CounterpartySearchQuery.php` — new
- `src/Company/Controller/Api/CounterpartySearchController.php` — new
- `src/Company/Repository/CounterpartyRepository.php` — modified (`findSelectableByCompany`)
- `src/Cash/Form/Transaction/CashTransactionType.php` — modified
- `src/Cash/Controller/Transaction/CashTransactionController.php` — modified
- `tests/Integration/Company/CounterpartySearchQueryTest.php` — new
- `tests/Integration/Company/CounterpartySelectableListTest.php` — new
- `tests/Functional/Company/Controller/Api/CounterpartySearchControllerTest.php` — new

### Что не делалось и почему
- Перф-критериев нет (Lite, plan.md §0): `EXPLAIN` с GIN-индексом невыполним на
  317 строках — планировщик выберет seq scan, и это правильно. GIN-индекс, `name_search`
  и замер p95 добавляются одной миграцией, если объём вырастет на порядок.
- UI-пикер вынесен в отдельную задачу `docs/tasks/counterparty-picker-widget/TASK.md`
  (решение Владельца, подход — Symfony form widget для legacy-страниц).

### Self-review
- [x] Scope compliance
- [x] IDOR — тест «контрагент другой компании не находится ни при каком запросе»
      (по названию, по ИНН, в верхнем регистре) + тест «companyId из query ignored»
- [x] Архивные не находятся — отдельный тест
- [x] Нет `SELECT *`, все колонки перечислены явно
- [x] Анонимный доступ к endpoint'у отклоняется — тест
- [x] Ранжирование проверено тестом (точное совпадение выше похожести)
- [x] CS-Fixer — чисто
- [x] Tests — `make site-test`: OK (2820 tests, 15655 assertions)
- [x] ARCHITECTURE.md обновлён (VO, нормализатор, Query, endpoint, отсутствие фасада)

### External Claude Code review
- Прогон 1 (`--max-turns 40`): `Error: Reached max turns (40)` — по `AGENTS.md`
  recoverable reviewer-configuration failure, не зелёный review и не owner gate.
- Прогон 2 (`--max-turns 80`, narrowed prompt): 4 IMPORTANT, 5 MINOR, 3 FOLLOW-UP.
  `REVIEW_GREEN` не выдан.
- Первый коммит `50b3a33f` и Draft PR #2262 сделаны по прямому указанию Владельца
  до получения `REVIEW_GREEN`; findings исправлены следующим коммитом.

#### Подтверждённые findings и исправления

| # | Finding | Вердикт | Исправление |
|---|---|---|---|
| IMPORTANT 1 | `normalize()` бросал исключение на входе из одних кавычек/точек (`..`, `""`) → 500 на `/api/counterparties/search`, 500 на форме, аборт всего импорта на одной мусорной ячейке | подтверждён | Нормализатор сделан total для непустого ввода: последний фолбэк `core = upper(display)`. Unit-провайдер на `""`, `«»`, `..`, `. .`, `''` |
| IMPORTANT 2 | `CashFileImportService` искал контрагента по сырому `trim($name)`, а сохранял схлопнутое `display` → название с двойным пробелом создавало нового контрагента на каждом прогоне (моя регрессия) | подтверждён | Нормализация один раз, `display` используется и как ключ кэша, и как условие поиска, и как сохраняемое значение |
| IMPORTANT 3 | Backfill пересчитывал подсказку только из названия и возвращал `ИП`, сброшенный при сохранении по 10-значному ИНН → инвариант держался до следующего прогона, ключ Stage 4 (`nameCore`, `legalFormHint`) стал бы недостоверным | подтверждён | Правило «ИП + 10-значный ИНН = ошибка парсера» сведено в один приватный предикат Entity; `refreshNormalizedName()` и `hasInconsistentLegalFormHint()` используют его. Регрессионный integration-тест + проверка идемпотентности |
| IMPORTANT 4 | `SaveCounterpartyAction` без тестов при том, что это крупнейшее поведенческое изменение Stage 1 | подтверждён | `tests/Integration/Company/SaveCounterpartyActionTest.php` — 11 тестов: создание, обновление, дубль ИНН, `$exceptId` на edit, тот же ИНН в другой компании, сброс подсказки, схлопывание пробелов, пустые ИНН/КПП |
| MINOR | `LIKE` без экранирования `%`/`_` | подтверждён | `addcslashes($core, '%_\')`; backslash — escape-символ LIKE по умолчанию в PostgreSQL, отдельная `ESCAPE`-клауза не нужна |
| MINOR | `--company-id` скоупил только отчёт, но читался как скоуп пересчёта | подтверждён | Переименован в `--report-company-id` с явным описанием |
| MINOR | docblock обещал `similarity: float` / `rows: int`, драйвер отдаёт строки | подтверждён | Приведение типов в самом Query |
| MINOR | `searchByInn` возвращал сырые строки DBAL, `searchByName` — маппленные | подтверждён | Общий `toItems()` для обоих маршрутов |
| MINOR | архивные исключены из похожести названий, но не из групп по ИНН | принят как намеренное | Задокументировано в docblock: архивный дубль всё равно занимает ИНН |
| FOLLOW-UP | кросс-компанийные CLI-методы без `companyId` | принят | Явные предупреждения в docblock `findAllForBackfill()` и `CounterpartyDuplicateCandidatesQuery` |
| FOLLOW-UP | `LIMIT :limit` полагался на вывод типа PostgreSQL | принят | Биндинг `ParameterType::INTEGER` |
| FOLLOW-UP | `Cash` импортирует `Domain/Service` модуля `Company` | принят, без действий | Долг зафиксирован в `stage-1.md`, чинится вместе с `CounterpartyFacade` |

### Команды для проверки
- `make site-test`
- `make site-cs-check`
- `docker compose run --rm site-php-cli bin/console doctrine:schema:validate`

### Риски / на что обратить внимание ревьюеру
- Поиск по названию находит только строки, прошедшие backfill (`name_core IS NULL`
  не матчится ничем). Порядок деплоя обязателен: миграция → backfill → endpoint в UI.
- `LIMIT`/порог передаются параметрами DBAL; типизация проверена интеграционными тестами.

### Открытые вопросы
- Stage 4 (contract `NOT NULL` + матчинг импорта по `nameCore` + `legalFormHint`)
  выполняется только после подтверждённого нулевого остатка `name_core IS NULL` на PROD.
