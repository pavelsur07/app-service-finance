## Stage 1: Модель названия — VO, нормализатор, контракт Entity, expand-миграция — DONE

**Риск:** 🟠 HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Следующее действие:** continue autonomously (Stage 2)

### Scope Stage
- Stage base commit: `89d0e5365e5cc5e8b5acb762afcccae45bd2c0ce`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`, `1.5`

### Что сделано
- `CounterpartyName` — immutable VO (`display` / `legalFormHint` / `core`) с приватным
  конструктором: создать значение можно только нормализатором.
- `CounterpartyNameNormalizer` — чистый детерминированный сервис. Порядок операций по
  ТЗ §3.2: точки снимаются **после** разбора ОПФ, иначе «ИВАНОВ И.П.» превращается в
  «ИВАНОВ ИП» и суффиксное правило начинает видеть ОПФ в инициалах. Whitelist ОПФ с
  правилами позиции: `ООО`/`АО`/`ЗАО`/`ОАО`/`ПАО` — префикс и суффикс, `ИП` — только
  префикс, `ГБУ`/`ФГБУ`/`МУП`/`ГУП`/`УФК`/`Казначейство` — не вырезаются вообще.
  Длинные формы проверяются раньше коротких, иначе «ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО»
  распознаётся как «АКЦИОНЕРНОЕ ОБЩЕСТВО» и оставляет «ПУБЛИЧНОЕ» в `core`.
- Expand-миграция `Version20260730150000`: `pg_trgm`, `legal_form_hint`, `name_core`,
  `kpp`, btree `(company_id, name_core)`, комментарий `DC2Type:datetime_immutable`.
  `down()` рабочий и проверен фактически (прогон down → up на тестовой БД).
- `Counterparty`: `rename()`, `refreshNormalizedName()`, `assignTaxIds()`,
  `belongsToCompany()`, `hasTaxId()`, `hasInconsistentLegalFormHint()`,
  `clearLegalFormHint()`, `archive()`/`restore()`, приватный `touch()`,
  `getId(): string`, `updatedAt` → `datetime_immutable`.
  Удалены `setName()`, `setInn()`, `setCompany()`, `setIsArchived()`, `setUpdatedAt()`.
- Форма справочника переведена с `data_class: Counterparty` на `CounterpartyFormData`;
  запись идёт через `SaveCounterpartyAction` (нормализация, проверка уникальности ИНН,
  сброс несогласованной подсказки ОПФ с логом `warning`).
- Переведены все точки записи: контроллер справочника, `ClientBank1CImportService`,
  `CashFileImportService`, `AppFixtures`, `CounterpartyBuilder` и 8 файлов тестов.

### Затронутые файлы
- `src/Company/Domain/ValueObject/CounterpartyName.php` — new
- `src/Company/Domain/Service/CounterpartyNameNormalizer.php` — new
- `src/Company/Application/SaveCounterpartyAction.php` — new
- `src/Company/Application/DTO/CounterpartyFormData.php` — new
- `src/Company/Exception/CounterpartyInnAlreadyExistsException.php` — new
- `migrations/Version20260730150000.php` — new
- `src/Company/Entity/Counterparty.php` — modified
- `src/Company/Form/CounterpartyType.php` — modified
- `src/Company/Controller/CounterpartyController.php` — modified
- `src/Company/Repository/CounterpartyRepository.php` — modified (`findOneByInn`)
- `src/Cash/Service/Import/ClientBank1CImportService.php` — modified
- `src/Cash/Service/Import/File/CashFileImportService.php` — modified
- `src/DataFixtures/AppFixtures.php` — modified
- `tests/Builders/Company/CounterpartyBuilder.php` — modified
- `tests/Unit/Company/CounterpartyNameNormalizerTest.php` — new (41 теста)
- `tests/Unit/Company/CounterpartyEntityTest.php` — rewritten

### Отступления от плана
- **Один `SaveCounterpartyAction` вместо `Create*` + `Update*`.** Разница между
  создданием и изменением — только исключение самой записи из проверки уникальности
  ИНН; два почти идентичных класса не оправданы.
- **`legalFormHint` в VO вместо `legalForm`,** и колонка `legal_form_hint` — по ТЗ §3.3
  это артефакт разбора, не правовой статус (плана §2, О2).
- **Поле `search` в VO не заведено** (Lite, О6): сегодня оно байт-в-байт равно `core`,
  разделение появится вместе с алиасами.
- **Гарантия «нельзя записать имя мимо нормализатора» — приватный конструктор + фабрика
  `fromNormalizedParts()` с `@internal` + тест на приватность конструктора.** В PHP нет
  friend-классов, поэтому полностью типовой запрет невозможен; VO без нормализованных
  частей не собрать, а прямой вызов фабрики виден в review и grep.
- **`archive()`/`restore()` без отдельных Action** (плана §2, О4): контроллер архивации
  уже делает `flush()` сам, два класса ради boolean — оверинжиниринг.
- **`Cash` импортирует `CounterpartyNameNormalizer` и `CounterpartyName` напрямую.**
  Формально это импорт `Domain/Service` чужого модуля. Причина: `Cash` уже импортирует
  саму `Counterparty` и создаёт её (`ClientBank1CImportService`, `CashFileImportService`),
  а конструктор теперь требует VO. Правильное решение — `CounterpartyFacade` с методом
  создания; он вынесен за скоуп (plan.md §12, отдельная задача вместе с чисткой семи
  прямых импортов `CounterpartyRepository`). Новых прямых импортов Repository не добавлено.

### Self-review
- [x] Scope compliance — только справочник контрагентов и его точки записи
- [x] Patterns / naming — VO и Domain Service по `PATTERNS.md` §4, Action по §3
- [x] Forbidden actions — none (нет `dump()`, `new Service()`, `flush()` в Repository)
- [x] Security — `belongsToCompany()` вместо сравнения объектов, `setCompany()` удалён,
      `findOneByInn(string $companyId, ...)` принимает companyId первым параметром
- [x] CS-Fixer — чисто по всем изменённым файлам (PHPStan в проекте нет)
- [x] Tests — `make site-test`: OK (2820 tests, 15655 assertions)
- [x] `doctrine:schema:validate` — по `counterparty` расхождений нет
      (в проекте есть pre-existing drift по другим таблицам, он не менялся)
- [x] ARCHITECTURE.md обновлён

### External Claude Code review
- См. `stage-3.md`: внешний review запускается на полный diff Stage 1–3 (стадии
  закрывались одной серией без промежуточных коммитов). На момент коммита
  `REVIEW_GREEN` не получен, разбор findings — отдельными коммитами.

### Команды для проверки
- `make site-test`
- `make site-cs-check`
- `docker compose run --rm site-php-cli bin/console doctrine:migrations:migrate -n`

### Риски / на что обратить внимание ревьюеру
- `CREATE EXTENSION IF NOT EXISTS pg_trgm` требует прав на PROD; прецедент —
  `pgcrypto` в `Version20260413120000`. Проверить до деплоя.
- `name_core` nullable намеренно: `string` в PHP уронил бы гидрацию строк, не прошедших
  backfill. Сужение — в Stage 4.
- Импорт по-прежнему матчит по точному `name` (переключение — Stage 4, D3).

### Открытые вопросы
- нет
