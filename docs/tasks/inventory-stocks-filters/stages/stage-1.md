## Stage 1: фильтрация отчёта `/inventory/stocks` сведена к «Источник + Дата» — DONE

**Риск:** 🟠 HIGH-LOCAL (добавлена миграция — индекс; изначально планировалась 🟡 MEDIUM без миграций)
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP, ждать решения Владельца по Release Gate

### Scope Stage
- Stage base commit: `59cf6bfc4f1cdb4d21ce5d404c51997d14717d29`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`, `1.5`

### Что сделано
- `InventoryStockReportQuery`: вместо шести фильтров — `source` + `snapshotDate`.
  Новый `findEffectiveSnapshotDate()` резолвит семантику «остатки **на** дату»
  (`snapshot_date <= :date`, `ORDER BY snapshot_date DESC LIMIT 1`), `getPage()` выбирает один день.
  Удалены `findLatestSnapshotSessionId()` и ветки `snapshotSessionId` / `snapshotAt` / `search` /
  `mappingStatus` / `status`.
- `StockReportController`: читает только `source`, `date`, `page`. Невалидный ввод (мусор, несуществующий
  день, null-байт, год 0000, массив в параметре) деградирует в дефолт, а не в 400/500. Список источников —
  только `ozon` и `wildberries` (остальные не поддерживаются нормализацией).
- Twig: форма из двух полей, нативный `<input type="date">`, подпись о фактической дате снимка,
  когда точного снимка на выбранный день нет. Пустое состояние — «Нет остатков на выбранную дату».
- Миграция `Version20260801120000`: индекс `(company_id, source, snapshot_date)`,
  `CREATE INDEX CONCURRENTLY` + `isTransactional(): false` по образцу `Version20251102120000`.
- Тесты: 10 функциональных сценариев отчёта (было 3), включая cross-company IDOR-проверку;
  интеграционный тест нормализации переведён на новую сигнатуру.

### Затронутые файлы
- `site/src/Inventory/Controller/StockReportController.php` — modified
- `site/src/Inventory/Infrastructure/Query/InventoryStockReportQuery.php` — modified
- `site/src/Inventory/Entity/StockSnapshot.php` — modified (новый `#[ORM\Index]`)
- `site/migrations/Version20260801120000.php` — new
- `site/templates/inventory/stocks/index.html.twig` — modified
- `site/tests/Functional/Inventory/Controller/StockReportControllerTest.php` — modified
- `site/tests/Integration/Inventory/Application/NormalizeInventorySnapshotActionTest.php` — modified
- `ARCHITECTURE.md` — modified

### Отклонение от плана
План фиксировал «миграций нет». Внешнее ревью показало, что новый паттерн запроса
(`company_id + source + snapshot_date <= :date`) не покрыт существующими индексами: для источника
без синхронизации поиск эффективной даты способен пройти по всей истории компании. Индекс добавлен
в scope Stage. Эффект не измерен на реальных объёмах — доступа к PROD в рамках задачи не запрашивалось.

### Self-review
- [x] Scope compliance — только фильтрация отчёта + вынужденный индекс (отклонение описано выше)
- [x] Patterns / naming — `final class` Controller/Query, `__invoke`, паттерн graceful-парсинга
      query-параметров взят у `MarketplaceSalesController`
- [x] Forbidden actions — нет `dump()/dd()`, нет бизнес-логики в контроллере, нет `SELECT *`
- [x] Security — `company_id` в обоих методах Query + `Assert::uuid()`, `getActiveCompany()` в контроллере,
      добавлен функциональный тест: снимок чужой компании не виден
- [x] Пагинация сохранена (Pagerfanta, `PER_PAGE = 30`, потолок 100)
- [x] Тесты — зелёные (см. ниже)
- [x] CS-Fixer по изменённым файлам — чисто
- [x] ARCHITECTURE.md обновлён (`InventoryStockReportQuery`)
- [ ] PHPStan — в проекте не установлен, `make stan` отсутствует

### External review
- Reviewer: Codex CLI 0.146.0 (`codex exec -s read-only --ephemeral`, дифф передан через stdin)
- Iterations: 5
- Result: **REVIEW_GREEN**
- Confirmed findings fixed:
  1. IMPORTANT — `date=0000-01-01` роняло страницу в 500 (`SQLSTATE[22008]`, доказано красным тестом).
  2. IMPORTANT — `date=%00` бросал `ValueError` в `createFromFormat()` → 500. Формат теперь проверяется
     регуляркой до парсера.
  3. IMPORTANT — нижняя граница «1970» молча подменяла корректную старую дату на сегодня;
     теперь `1969-12-31` даёт пустой отчёт.
  4. IMPORTANT — отсутствие индекса под новый паттерн запроса → миграция.
  5. IMPORTANT — обычный `CREATE INDEX` заблокировал бы записи → `CONCURRENTLY` + нетранзакционная миграция.
  6. MINOR — не было теста на точное совпадение даты (замена `<=` на `=` осталась бы незамеченной).
  7. MINOR — `daysAgo()` пересчитывался несколько раз → нестабильность при прогоне через полночь.
  8. MINOR — не было cross-company IDOR-теста.
  9. MINOR — источники в тесте лежали в одну дату, потеря условия `s.source` не ловилась.
  10. MINOR — комментарий ссылался на неверное имя индекса.
- Rejected findings with reason: нет
- Ограничения ревьюера: без доступа к шеллу, БД и PROD; схема, индексы и семантика `upsertDaySnapshot()`
  переданы в промпте. Проверить план запроса и реальные объёмы таблицы ревьюер не мог — оценка индекса
  аналитическая, не измеренная.

### Команды для проверки
- `docker compose run --rm site-php-cli php bin/phpunit tests/Functional/Inventory tests/Integration/Inventory` → **OK (86 tests, 384 assertions)**
- `docker compose run --rm site-php-cli php bin/phpunit` (полный прогон) → **2902 теста, 1 падение**
- `make site-cs-check` — точечно по изменённым файлам: **0 нарушений**

### Baseline
- `StockReportControllerTest` до задачи: OK (3 теста, 13 assertions).
- Полный прогон падает на `Functional\Analytics\DashboardSnapshotGoldenTest` —
  **проверено на базовом коммите через `git stash`: падает и без изменений задачи**, к Stage не относится.
- `make site-cs-check` по репозиторию красный до задачи: только в `src/Inventory` 11 файлов из 46
  требуют правок, включая `Entity/StockSnapshot.php` (нарушение существует и в базовой версии файла).

### Риски / на что обратить внимание ревьюеру
- Миграция выполняется вне транзакции. При падении `CREATE INDEX CONCURRENTLY` Postgres оставляет
  индекс в состоянии `INVALID` — его нужно удалить вручную перед повтором.
- Семантика отчёта изменилась: раньше по умолчанию показывалась последняя **сессия**, теперь —
  последний **день**. Для дня с двумя синхронизациями это одно и то же (уникальный ключ на день),
  но записи, исчезнувшие из более поздней сессии того же дня, теперь остаются видимыми.
- Дата по умолчанию считается по таймзоне PHP (`new \DateTimeImmutable('today')`), а не по таймзоне
  пользователя.

### Открытые вопросы
- нет
