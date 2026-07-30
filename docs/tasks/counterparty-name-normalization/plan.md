# Нормализация названий и поиск в справочнике контрагентов — план (Phase 0)

Источник задачи: бриф Владельца в чате от 30.07.2026 (полный текст ТЗ по
нормализации `Counterparty`, этапы 1–3 + вынесенные за скоуп пункты).

Task id: `counterparty-name-normalization`
Base commit: `89d0e5365e5cc5e8b5acb762afcccae45bd2c0ce` (master)
Модуль: `App\Company`, затрагиваются `Cash`, `Finance`, `DataFixtures`, тесты.

---

## 0. Замеры PROD (read-only, 30.07.2026, разрешение Владельца в чате)

`sudo -u codex-prod sudo /usr/local/bin/codex-psql-ro`, четыре `SELECT`, ничего
не менялось.

| Метрика | Значение |
|---|---|
| Всего строк в `counterparty` | **317** |
| Компаний | 7 |
| Максимум на одну компанию | **128** |
| Архивных | 2 |
| Дубли по `(company_id, inn)` | **0** |
| Групп-дублей по нормализованному названию | **2** (4 строки) |
| Без ИНН | 22 |
| ИНН: 10 знаков / 12 знаков / мусор длиной 1 | 162 / 132 / **1** |

Найденные группы-дубли целиком:

1. `ООО "ПО ОБОРОНХИМ"` × 2 — настоящий дубль.
2. `ООО "БАЛТИЙСКИЙ ЛИЗИНГ"` vs `АО "БАЛТИЙСКИЙ ЛИЗИНГ"` — **ложный дубль**:
   это два разных юридических лица с разными ИНН. Их склеила именно
   нормализация, вырезав ОПФ.

### Что эти цифры делают с ТЗ

Проблема из ТЗ §1 в проде существует, но не в том масштабе, который оправдывает
всю программу:

- **Производительности проблемы нет.** 128 строк на компанию — `LIKE '%…%'`
  укладывается в микросекунды, плоский `<select>` весит килобайты. Обоснование
  «нет индекса → seq scan» верно формально и нерелевантно фактически.
- **Критерий приёмки «`EXPLAIN` подтверждает использование
  `idx_counterparty_name_search_trgm`» невыполним** на 317 строках: планировщик
  выберет seq scan, и это правильное решение планировщика. Либо критерий
  снимается, либо проверяется только на локально засеянном объёме.
- **Backfill Messenger-батчем, `CONCURRENTLY`, батчи по 500 и maintenance-пауза
  не нужны** — 317 строк обрабатываются одним проходом за миллисекунды.
- **Ценность остаётся ровно в двух вещах:** (а) поиск находит оба порядка ОПФ и
  терпит опечатки; (б) импорт перестаёт плодить дубли (факт №6). Обе — про
  корректность, не про скорость.
- **Открытый риск, который замер выявил, а ТЗ не предусмотрело:** `core` без ОПФ
  склеивает разные юрлица (`ООО` и `АО` «Балтийский лизинг»). Поэтому
  `nameCore` **нельзя** использовать как ключ дедупликации сам по себе:
  отчёт по дублям и матчинг импорта обязаны учитывать `legalFormHint` (и ИНН,
  где он есть), иначе задача не уберёт дубли, а создаст ложные слияния.
  Это уточняет work item 2.2 и 4.3.

### Объём: принят Lite (решение Владельца, 30.07.2026)

Из ТЗ **убрано** как не оправданное замером:

| Убрано | Почему |
|---|---|
| Колонка `name_search` | Байт-в-байт равна `name_core` (ТЗ §3.2 п.8). Поиск и trgm работают по `name_core`; расхождение полей вводим тогда же, когда появятся алиасы |
| GIN-индекс `gin_trgm_ops` | На 317 строках планировщик его не возьмёт; добавляется одной строкой миграции, когда объём вырастет |
| Критерий «`EXPLAIN` подтверждает GIN-индекс» | Невыполним по построению |
| Замер p95 и синтетический сев 10k/50k | Мерить нечего: 128 строк на компанию |
| Батчи по 500, `clear()`, Messenger-батч, `CONCURRENTLY`, maintenance-пауза | 317 строк обрабатываются одним проходом |

**Оставлено:** расширение `pg_trgm` (нужна сама функция `similarity()` — для
поиска с опечаткой и для отчёта по дублям), btree `(company_id, name_core)`
(штатный индекс под основной путь запроса, стоит одну строку), колонка `kpp`,
вся нормализация, backfill с `--dry-run` и идемпотентностью, отчёт по дублям,
матчинг импорта, `NOT NULL` в contract-фазе.

UI-автокомплит вынесен в отдельную задачу
`docs/tasks/counterparty-picker-widget/TASK.md` (§7).

---

## 1. Что проверено в коде до планирования

Факты из репозитория, которые меняют или уточняют ТЗ. Читать до Stage 1.

| # | Факт | Где | Следствие для плана |
|---|---|---|---|
| 1 | Форма привязана к Entity напрямую: `data_class: Counterparty`, поля `name`/`inn` пишутся сеттерами | `src/Company/Form/CounterpartyType.php:39`, `Controller/CounterpartyController.php:50,84` | Удаление `setName()`/`setInn()` (ТЗ §5.3) ломает форму. Нужна форма без `data_class` + `CreateCounterpartyAction` / `UpdateCounterpartyAction`. Это самая недооценённая часть Stage 1 |
| 2 | `new Counterparty(...)` в 15 местах, из них 4 в проде-коде: контроллер, 2 сервиса импорта, фикстуры | `grep` по `src`, `tests` | Все переводятся на `CounterpartyName`; сервисы импорта обязаны получить `CounterpartyNameNormalizer` через DI |
| 3 | `CounterpartyFacade` описан в `ARCHITECTURE.md:533`, но файла нет; `CounterpartyRepository` импортируется напрямую из `Cash`, `Finance`, `Deals` (7 файлов) | `src/Company/Facade/` содержит только `CompanyFacade`, `FinancialResponsibilityCenterFacade` | Ответ на вопрос ТЗ §9.5: фасада нет, прямые импорты есть. Создавать фасад в этой задаче не нужно — endpoint поиска живёт в самом `Company`. Чинить 7 импортов — отдельная задача. В этой задаче правим только запись в `ARCHITECTURE.md` (документация разошлась с кодом) |
| 4 | `pg_trgm` не включён; `CREATE EXTENSION IF NOT EXISTS pgcrypto` в миграциях уже применялся успешно | `migrations/Version20260413120000.php:52` | Прецедент есть, права у DB-пользователя, скорее всего, достаточные. Проверить на PROD до миграции: `SELECT * FROM pg_available_extensions WHERE name = 'pg_trgm'` |
| 5 | Кастомных DQL-функций в проекте нет (`doctrine.yaml` без `dql:`), но есть паттерн DBAL Query-классов | `config/packages/doctrine.yaml`, `PATTERNS.md §6`, `src/Cash/Infrastructure/Query/CounterpartyHistoryQuery.php` | Поиск делаем DBAL Query-классом со скалярными колонками. `doctrine.yaml` не трогаем, DQL-функцию `SIMILARITY` не регистрируем, Entity не гидрируем |
| 6 | `CashFileImportService::resolveCounterparty()` матчит контрагента по **точному** `name`; `ClientBank1CImportService` — по `inn` | `src/Cash/Service/Import/File/CashFileImportService.php:356`, `ClientBank1CImportService.php:606` | Точный матч по `name` — фабрика дублей, то есть корневая причина проблемы из ТЗ §1. Переключение на `nameCore` — одна строка, но это изменение поведения импорта → решение Владельца **D3** |
| 7 | Индексы `idx_counterparty_company` и `idx_counterparty_company_inn` уже есть | `src/Company/Entity/Counterparty.php:15-16` | Отдельный индекс под префиксный поиск по ИНН не нужен |
| 8 | Полный справочник грузится в `<select>` в 4 местах, в одном — вместе с архивными | `CashTransactionType.php:89` (без фильтра `isArchived`), `CashTransactionController.php:63`, `CashTransactionAutoRuleController.php:145,209` | Баг с архивными закрывается в work item 3.4; остальные экраны — в задаче `counterparty-picker-widget` |
| 9 | React-острова в проекте есть (`assets/react/_legacy/*` + entries в `vite.config.js`), TanStack Query **не установлен**; `CLAUDE.frontend.md:19` запрещает Stimulus для логики с запросами к API | `package.json`, `vite.config.js:23-41` | D2 закрыт: UI вынесен в задачу `counterparty-picker-widget`, подход — Symfony form widget для legacy-страниц (§7) |
| 10 | `CounterpartyType` (enum): `LEGAL_ENTITY`, `INDIVIDUAL_ENTREPRENEUR`, `SELF_EMPLOYED`, `NATURAL_PERSON` | `src/Company/Enum/CounterpartyType.php` | Ответ на вопрос ТЗ §9.3: enum уже несёт правовой статус. Подтверждает рекомендацию ТЗ: разобранная ОПФ — свободная строка-подсказка, не enum, бизнес-логику на ней не ветвим |
| 11 | Факт использования контрагента фиксируется в `cash_transaction`, `documents`, `document_operations`, `payment_plan`, `deals` (колонка `counterparty_id`) | `information_schema` локальной БД | Ответ на вопрос ТЗ §9.4: отдельное поле `last_used_at` не нужно, «Недавние» выводимы запросом из `Cash`. За скоуп этой задачи — фолбэк-сортировка по `name_core` |
| 12 | В локальной dev-БД 21 контрагент, 1 компания, дублей по `(company_id, inn)` нет | `psql` read-only | Для калибровки объёмов бесполезно. Нужны цифры PROD → **D1** |
| 13 | Тест `CounterpartyEntityTest:78` проверяет `setCompany()` | `tests/Unit/Company/CounterpartyEntityTest.php` | Удаление сеттера (ТЗ §5.3) требует переписать тест на отсутствие смены tenant |
| 14 | Прецедент нетранзакционной миграции для `CONCURRENTLY` есть | `migrations/Version20251102120000.php`, `Version20251112090000.php` (`isTransactional()`) | В Lite не нужен (317 строк). Ссылка на будущее, если таблица вырастет |

---

## 2. Отступления от ТЗ, которые предлагаю принять

Каждое — с причиной. Если Владелец не согласен хотя бы с одним, правим план до Stage 1.

**О1. `nameCore` в PHP объявляем `?string`, а не `string`.**
ТЗ §4 требует `string` при nullable-колонке. Так нельзя: Doctrine гидрирует
существующие строки с `NULL` в типизированное свойство `string` и падает
`TypeError` сразу после деплоя миграции — до того, как backfill доедет до строки.
Инвариант непустоты держит путь записи (VO + `applyName()`), а не тип свойства.
Сужение до `string` — в Stage 4 (contract), вместе с `SET NOT NULL`. Это и есть штатный
expand → migrate → contract.

**О2. Колонка и свойство называются `legal_form_hint` / `$legalFormHint`.**
ТЗ §4 в SQL пишет `legal_form`, а §3.3 требует называть поле `legalFormHint`,
потому что это артефакт разбора строки, а не правовой статус. Переименование
колонки позже — лишний цикл expand/contract, поэтому имя берём правильное сразу.

**О3. Поиск — DBAL Query-класс со скалярными колонками, а не метод репозитория,
возвращающий Entity.**
`CounterpartySearchQuery` (`src/Company/Infrastructure/Query/`) по `PATTERNS.md §6`:
один SQL, явное перечисление колонок, `company_id` в `WHERE`. Endpoint отдаёт
скаляры — гидрировать Entity незачем. Побочные выгоды: `doctrine.yaml` не
меняем, DQL-функцию `SIMILARITY` не регистрируем, `similarity(...) > 0.3`
пишем явным условием (не оператором `%`, как и требует ТЗ §7.1).

**О4. `archive()` / `restore()` — методы Entity, но новых Action под них не
создаём.** Контроллер архивации уже делает `flush()` сам (существующий паттерн
модуля). Два новых Action-класса ради одного boolean — оверинжиниринг; правка
остаётся двухстрочной. Формально это расхождение с правилом «flush только в
Action» — фиксирую как осознанный долг, не расширяю задачу переписыванием
контроллера.

**О5. Перф-критериев в приёмке нет** (Lite, §0): ни `EXPLAIN` с GIN-индексом,
ни p95, ни синтетического сева. Если объём вырастет на порядок — GIN-индекс и
замеры добавляются отдельной задачей на одну миграцию.

**О6. `name_search` не добавляем** (Lite, §0). `CounterpartyName` остаётся с
полем `search`, но в Entity и БД пишется только `name_core`: VO — это контракт
нормализации, и разделение `core`/`search` понадобится вместе с алиасами.
Возможный источник расхождения — только один, и он под тестом идемпотентности.

---

## 3. Решения Владельца

**D1 — ЗАКРЫТО.** Read-only PROD разрешён и снят: см. §0. Следствия: backfill
консольной командой (не Messenger), `CREATE INDEX` обычный (не `CONCURRENTLY`),
maintenance-пауза не нужна, порядок этапов в пользу merge менять не надо —
дублей по `(company_id, inn)` ноль.

**D2 — анализ текущего Twig (запрошен Владельцем), решение открыто.**

Факты по шаблонам:

- Форма транзакции — legacy-страница Tabler: `templates/transaction/_form.html.twig`
  использует `form-select`, `form-label`, `card-footer`, `btn btn-primary`;
  `templates/transaction/edit.html.twig` → `base.html.twig` →
  `_layout/legacy.html.twig` с комментарием «Compatibility entrypoint for
  existing Tabler pages» и Tabler JS с CDN.
- На legacy-базе **112 шаблонов**, на новом UI-Kit-лэйауте (`_layout/app.html.twig`) — **1**.
  То есть экран, который правим, ещё не переведён на UI Kit, а Tabler по
  решению Владельца из проекта выводится.
- React-острова в legacy-страницах есть (`ingestion/verification/coverage.html.twig`,
  `marketplace_ads/efficiency/index.html.twig` → `base.html.twig` + `vite_entry_script_tags`),
  но все они — **виджеты целой страницы** (`<div id="…-root">` вместо контента).
  Поля внутри Symfony-формы React-островом не делает ни один экран.
- Stimulus в проекте — 4 контроллера, **ни один не делает HTTP-запросов**
  (`fetch` в `assets/controllers/` не встречается). Прецедента нет ни у одного из
  вариантов.
- Пикер потребует: скрытое поле + синхронизация с Symfony-формой, сохранение
  выбора при ошибке валидации, CSRF, серверная проверка принадлежности id
  компании. Это дороже, чем страничный виджет, в обоих вариантах.

Итог (принято Владельцем): **UI-пикер — отдельная задача, Symfony-виджет для legacy.** 128 вариантов максимум (§0) —
нативный `<select>` с браузерным type-ahead закрывает задачу; вместо пикера в
Stage 3 хватит двух правок: отфильтровать архивных в
`CashTransactionType.php:89` и оставить сортировку по названию. Когда экран
поедет на UI Kit, пикер делается React-островом по правилам и один раз, а не
дважды. Если автокомплит нужен раньше — Stimulus, потому что React-остров на
Tabler-странице придётся переписать при миграции экрана.

**D3 — ЗАКРЫТО: да, в Stage 4 (work item 4.3).** С уточнением из §0: ключ
матчинга — `nameCore` **вместе с** `legalFormHint` (и ИНН, где он есть), иначе
`ООО` и `АО` с одинаковым названием склеятся в одного контрагента.

**D4 (не блокирует, дефолт = по ТЗ).** Согласны ли отступления О1–О6 (§2).
Молчание читаю как согласие с дефолтами.

---

## 4. Stage 1: модель названия — VO, нормализатор, контракт Entity, expand-миграция

Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `89d0e5365e5cc5e8b5acb762afcccae45bd2c0ce`

Definition of Done:
- `Counterparty` невозможно создать или переименовать, минуя `CounterpartyName`.
- `setName()`, `setInn()`, `setCompany()`, `setIsArchived()`, `setUpdatedAt()`
  отсутствуют в кодовой базе (grep пустой).
- Все точки записи (контроллер, 2 сервиса импорта, фикстуры, Builder, тесты)
  переведены на новый контракт.
- Expand-миграция накатывается и откатывается; `down()` проверен фактически.
- `bin/console doctrine:schema:validate` без расхождений.
- Unit-тесты нормализатора зелёные, включая блокирующую группу ложных
  срабатываний `ИП`.

Work items:
- 1.1 — `CounterpartyName` (VO, `src/Company/Domain/ValueObject/`) +
  `CounterpartyNameNormalizer` (`src/Company/Domain/Service/`), приватный
  конструктор VO, порядок операций по ТЗ §3.2 (снятие точек — строго после
  вырезания ОПФ), whitelist ОПФ с правилами позиции. Unit-тесты: канонические
  формы «Ромашка», группа ложных `ИП`, `УФК …`, идемпотентность, пустой `core`.
- 1.2 — expand-миграция: `pg_trgm`, `legal_form_hint`, `name_core`, `kpp`,
  btree `(company_id, name_core)`, рабочий `down()`. Без `name_search` и без
  GIN-индекса (Lite, §0).
- 1.3 — `Counterparty`: новые поля (`?string` по О1), `applyName()`, `rename()`,
  `assignTaxIds()`, `belongsToCompany()`, `hasTaxId()`,
  `hasInconsistentLegalFormHint()`, `archive()`/`restore()`, приватный `touch()`,
  `getId(): string`, `updatedAt` → `datetime_immutable`; удаление сеттеров по
  ТЗ §5.3. Unit-тесты Entity.
- 1.4 — форма и Actions: `CounterpartyType` без `data_class`,
  `CreateCounterpartyAction` + `UpdateCounterpartyAction` (нормализация, сброс
  несогласованной подсказки с `warning`, проверка дубля по ИНН),
  контроллер — только HTTP in/out.
- 1.5 — остальные точки записи: `ClientBank1CImportService`,
  `CashFileImportService`, `AppFixtures`, `CounterpartyBuilder`, все тесты со
  `new Counterparty(...)`.

Stage checks:
- `make site-test-unit`, затем `make site-test`
- `make site-cs-check` (PHPStan в проекте нет — `make stan` не существует)
- миграция up/down на локальной тестовой БД, `doctrine:schema:validate`
- grep на удалённые сеттеры

Reviewer focus:
- порядок шагов нормализации (точки после ОПФ), word-boundary матчинг `ИП`
- ни одной новой записи `name` мимо нормализатора
- `?string` вместо `string` (О1) — сознательно, не забытая правка
- сохранена ли изоляция по компании во всех переписанных путях записи

---

## 5. Stage 2: backfill производных полей + отчёт по кандидатам-дублям

Risk: HIGH-LOCAL (локально) / 🔴 прогон на PROD — Production Gate
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: фиксируется перед реализацией

Definition of Done:
- Команда `app:counterparty:backfill-names` идемпотентна, с `--dry-run` и
  итоговым счётчиком; `--dry-run` не пишет в БД. Один проход, без батчей
  (Lite, §0).
- Backfill использует тот же `CounterpartyNameNormalizer` (одно определение
  нормализации на проект, без SQL-копии правил).
- `updatedAt` не меняется ни у одной строки.
- Пустой `core` логируется `warning` (не `error`) и попадает в отчёт.
- Отдельный вывод: кандидаты на дубли (одинаковый `inn` либо
  `similarity(name_core, …) > 0.6` в рамках компании) — только отчёт, без правок
  данных. В строке отчёта обязателен `legalFormHint` и ИНН каждого кандидата:
  замер §0 показал, что без ОПФ `ООО` и `АО` «Балтийский лизинг» выглядят одним
  контрагентом, хотя это разные юрлица.
- Integration-тест: два прогона подряд не меняют данные (diff пустой).
- Тест на ложный дубль: `ООО "X"` и `АО "X"` не попадают в одну группу отчёта.

Work items:
- 2.1 — консольная команда + Action пересчёта, `--dry-run`, идемпотентность,
  логирование по уровням из `CLAUDE.md`. Один `flush()` в конце, ни батчей, ни
  Messenger (D1: 317 строк).
- 2.2 — отчёт по кандидатам-дублям в stdout/файл, с ОПФ и ИНН в строке.
- 2.3 — integration-тесты: идемпотентность, неизменность `updatedAt`, нулевой
  остаток `name_core IS NULL`, ложный дубль `ООО`/`АО`.
- 2.4 — мусорные ИНН: замер §0 нашёл строку с ИНН длиной 1 знак. Backfill
  `inn` не трогает; в отчёте такие строки выводятся отдельным списком, чтобы
  Владелец увидел их до того, как `assignTaxIds()` начнёт отклонять правку такой
  записи в форме.

Stage checks: `make site-test`, `make site-cs-check`, ручной прогон команды на
локальной БД с фикстурами (dry-run → run → повторный run).

Reviewer focus: идемпотентность, отсутствие мутации `updatedAt`, отсутствие
дублирования правил нормализации в SQL, уровни логов.

Release Gate после Stage 2: миграция + backfill деплоятся отдельно и раньше
поиска. Порядок на PROD: миграция → backfill → проверка нулевого остатка.

---

## 6. Stage 3: поиск — Query, endpoint автокомплита

Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no

Definition of Done:
- `CounterpartySearchQuery` (DBAL, явные колонки, `company_id` в `WHERE`,
  `is_archived = false`), роутинг «только цифры → префикс по `inn`, иначе →
  `name_core` префикс + `similarity(name_core, :q) > 0.3` явным условием (не
  оператором `%`)», ранжирование по ТЗ §7.1, `LIMIT 20`.
- `GET /api/counterparties/search?q=` (`src/Company/Controller/Api/`), companyId
  только из `ActiveCompanyService`, формат ошибок `{error: {code, message}}`,
  `q` короче 2 символов → пустой массив без SQL.
- Integration-тесты на реальной БД: «ромашка» находит оба варианта написания,
  «рамашка» находит через similarity, ИНН из 10 цифр находит по ИНН,
  контрагент другой компании не находится ни при каком запросе, архивные
  не попадают.
- Перф-критериев нет (О5, §0): `EXPLAIN`, p95 и синтетический сев исключены
  вместе с GIN-индексом.

Work items:
- 3.1 — `CounterpartySearchQuery` + integration-тесты (включая IDOR).
- 3.2 — контроллер endpoint'а + functional-тесты (короткий `q`, лимит, формат
  ошибки).
- 3.3 — отфильтровать архивных в `CashTransactionType.php:89` (баг, найден при
  разведке; закрывается двумя строками, пикер для этого не нужен).

Stage checks: `make site-test`, `make site-cs-check`.

Reviewer focus: `company_id` в каждом запросе, отсутствие `SELECT *`,
отсутствие Pagerfanta осознанно (ТЗ §7.2 — согласованное отступление), пороги
similarity не через сессионную настройку.

---

## 7. UI-пикер — вынесен в отдельную задачу (решение Владельца, 30.07.2026)

D2 закрыт: автокомплита в этой задаче нет. Отдельная задача —
`docs/tasks/counterparty-picker-widget/TASK.md`, подход: **Symfony
form widget для legacy-страниц** (собственный FormType + form theme), не
React-остров и не одноразовый Stimulus в шаблоне.

Что остаётся в этой задаче: work item 3.4 — отфильтровать архивных в
`CashTransactionType.php:89`. Плоский `<select>` со 128 вариантами остаётся до
той задачи как есть.

Что уезжает: пикер, debounce, «Создать контрагента» из пустого результата,
перевод остальных экранов из факта №8, `entity-picker`-разметка UI Kit.

---

## 8. Stage 4: contract-фаза

Risk: HIGH-LOCAL
owner_gate: yes
release_candidate: yes
independently_deployable: yes

Выполняется только после подтверждённого нулевого остатка `name_core IS NULL`
на PROD (то есть после Production Gate Stage 2).

Definition of Done:
- Миграция `SET NOT NULL` на `name_core`; свойство сужено до `string`
  (закрытие О1).
- Матчинг импорта файлов переключён с точного `name` на пару
  (`nameCore`, `legalFormHint`) — D3 закрыт «да», уточнение из §0 обязательно.
  Регрессионные тесты: `ООО "Ромашка"` и `"Ромашка" ООО` в одном файле дают
  одного контрагента; `ООО "Ромашка"` и `АО "Ромашка"` — двух.
- `ARCHITECTURE.md` синхронизирован (см. §10).

Work items:
- 4.1 — contract-миграция + сужение типов.
- 4.2 — обновление `ARCHITECTURE.md`, устранение расхождения по
  `CounterpartyFacade`.
- 4.3 — матчинг импорта по (`nameCore`, `legalFormHint`) + регрессионные тесты
  на настоящий и на ложный дубль.

Stage checks: `make site-test`, `make site-cs-check`, миграция up/down,
`doctrine:schema:validate`.

Reviewer focus: миграция безопасна при непустой таблице, нет пути записи,
способного вернуть `NULL`.

---

## 9. Карта изменений

| Слой | Файл | Действие |
|---|---|---|
| VO | `src/Company/Domain/ValueObject/CounterpartyName.php` | new |
| Domain Service | `src/Company/Domain/Service/CounterpartyNameNormalizer.php` | new |
| Entity | `src/Company/Entity/Counterparty.php` | modified (поля, VO-контракт, удаление сеттеров) |
| Action | `src/Company/Application/CreateCounterpartyAction.php`, `UpdateCounterpartyAction.php` | new |
| Action | `src/Company/Application/BackfillCounterpartyNamesAction.php` | new |
| Command | `src/Company/Application/Command/CounterpartyBackfillNamesCommand.php` | new |
| Query | `src/Company/Infrastructure/Query/CounterpartySearchQuery.php` | new |
| Controller | `src/Company/Controller/Api/CounterpartySearchController.php` | new |
| Controller | `src/Company/Controller/CounterpartyController.php` | modified |
| Form | `src/Company/Form/CounterpartyType.php` | modified (без `data_class`) |
| Миграции | expand (Stage 1) + contract `NOT NULL` (Stage 4) | new ×2 |
| Точки записи | `src/Cash/Service/Import/ClientBank1CImportService.php`, `src/Cash/Service/Import/File/CashFileImportService.php`, `src/DataFixtures/AppFixtures.php` | modified |
| Формы/экраны | `src/Cash/Form/Transaction/CashTransactionType.php`, `templates/transaction/_form.html.twig`, + экраны из факта №8 | modified |
| Frontend | — | вынесено в задачу `counterparty-picker-widget` |
| Тесты | `tests/Builders/Company/CounterpartyBuilder.php`, `tests/Unit/Company/CounterpartyEntityTest.php`, + 10 файлов со `new Counterparty(...)` | modified |

## 10. Записи в `ARCHITECTURE.md`

- `Counterparty`: новые поля `legalFormHint`, `nameCore`, `kpp`;
  контракт записи только через `CounterpartyName`; `setCompany()` удалён.
- Новые `CounterpartyName`, `CounterpartyNameNormalizer`, `CounterpartySearchQuery`.
- Новый публичный endpoint `GET /api/counterparties/search`.
- Устранить расхождение: описанный `CounterpartyFacade` отсутствует в коде
  (либо удалить блок, либо пометить как план — по факту Stage 4).

## 11. Тесты — минимум

| Что добавлено | Тесты |
|---|---|
| `CounterpartyNameNormalizer` | unit на все ветки: канонические формы, блокирующая группа ложных `ИП`, `УФК …`, идемпотентность, пустой `core` |
| `CounterpartyName` | unit: создать мимо нормализатора невозможно |
| Entity | unit: `assignTaxIds(null, kpp)` бросает, `belongsToCompany()` ±, `hasInconsistentLegalFormHint()` для 10/12 цифр, `rename()` синхронизирует `name`, `legalFormHint`, `nameCore` |
| Create/Update Action | happy-path + негативный (дубль ИНН, сброс несогласованной подсказки, `name` не изменён) |
| Backfill | integration: идемпотентность, `updatedAt` не тронут, нулевой остаток |
| `CounterpartySearchQuery` | integration на реальной БД: оба порядка ОПФ, опечатка, ИНН, **IDOR другой компании**, архивные |
| Endpoint | functional: `q` из 1 символа без SQL, лимит 20, формат ошибки |
| Импорт (D3) | регрессионный: два написания в одном файле → один контрагент; `ООО "X"` + `АО "X"` → два контрагента |
| Отчёт по дублям | `ООО "X"` и `АО "X"` не попадают в одну группу (ложный дубль из §0) |

## 12. Границы задачи

За скоупом (ТЗ §2, подтверждено): `merged_into_id` и слияние дублей;
`UNIQUE (company_id, inn, kpp)`; вынос скоринговых полей; ЕГРЮЛ/ФНС/DaData;
`counterparty_alias`. Дополнительно за скоупом по факту разведки: создание
`CounterpartyFacade` и чистка 7 прямых импортов `CounterpartyRepository`;
секция «Недавние» в пикере; переписывание archive/unarchive в Actions (О4).

## 13. Гейты

- Release Gate 1 — после Stage 2 (expand + backfill деплоятся первыми).
- Release Gate 2 — после Stage 3 (поиск + endpoint).
- Release Gate 3 — после Stage 4 (contract).
- Production Gate — отдельно и явно на каждое: миграция на PROD, прогон
  backfill. Зелёный Release Gate ничего из этого не разрешает.
  `CONCURRENTLY`, maintenance-пауза и перф-замеры по итогам §0 не нужны.
- Read-only PROD-замеры §0 выполнены по разрешению Владельца от 30.07.2026;
  новое разрешение нужно на каждый следующий доступ к PROD.
