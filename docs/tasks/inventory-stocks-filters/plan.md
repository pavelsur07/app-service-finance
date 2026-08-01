# Рефакторинг фильтров отчёта `/inventory/stocks`

**Задача владельца:** оставить в отчёте только два фильтра — «Источник» и «Дата, на которую показываются остатки».

## Что есть сейчас

- `site/src/Inventory/Controller/StockReportController.php` — 6 параметров:
  `source`, `snapshotSessionId`, `snapshotAt` (точное равенство timestamp),
  `search`, `mappingStatus`, `status`.
- `site/src/Inventory/Infrastructure/Query/InventoryStockReportQuery.php` —
  `findLatestSnapshotSessionId()` + `getPage()` с теми же 6 фильтрами.
- `site/templates/inventory/stocks/index.html.twig` — форма из 6 полей.
- По умолчанию отчёт показывает последнюю сессию снимка (`snapshotSessionId` подставляется автоматом).

Опорный факт схемы (`StockSnapshot`): уникальный ключ
`(company_id, snapshot_date, source, source_sku, fulfillment_type, location_id, status)`
и `upsertDaySnapshot()` — то есть **на один день по источнику существует ровно один срез остатков**.
Значит день полностью идентифицирует картину остатков, и `snapshot_session_id` в UI не нужен.

## Решения (подтверждены владельцем 2026-08-01)

1. **Семантика даты — «на дату», а не «за дату».**
   Показываем последний доступный `snapshot_date <= выбранная дата` для выбранного источника.
   Если 27-го синхронизации не было, а 26-го была — на 27-е показываются остатки от 26-го,
   с явной подписью «фактически на 26.07.2026».
   Это совпадает с уже существующей семантикой `StockQtyByListingOnDateQuery`
   (`snapshot_date <= :reportDate`, берётся ближайший предыдущий), которая используется в отчётах себестоимости.
   *Альтернатива:* строгое равенство `snapshot_date = :date` — пустая страница в дни без синхронизации.
2. **Дата по умолчанию — сегодня** (при пустом/невалидном параметре). Фактически это даёт
   «последний доступный снимок», то есть поведение текущей страницы по умолчанию не меняется.
3. **Невалидная дата не ломает страницу** — graceful fallback на сегодня, как сейчас с невалидным UUID сессии
   (тест на это уже есть, сохраняем поведение под новый параметр).
4. **Список источников сокращается до реально поддерживаемых** — `ozon`, `wildberries`.
   Нормализация (`NormalizeInventorySnapshotAction`) отвергает остальные, поэтому `yandex_market`
   и `sber_megamarket` в селекте — это гарантированно пустой отчёт.
5. **Удалённые параметры просто игнорируются** — без редиректов и без 400/422. Старые ссылки
   (`?mappingStatus=unmapped`) продолжают открываться, показывая полный срез.

## Что НЕ входит в scope

- Колонки таблицы, вёрстка страницы, перевод экрана с Tabler-разметки на UI Kit — не трогаем.
- Схема БД и миграции — не нужны, новых полей нет.
  *Обновлено по итогам внешнего ревью:* добавлена одна миграция — индекс
  `(company_id, source, snapshot_date)` под новый паттерн запроса, см. Stage Report.
- `StockQtyByListingOnDateQuery`, вкладка «Себестоимость», страница `/inventory/snapshots` — не трогаем.

---

## Stage 1: фильтрация отчёта сведена к «Источник + Дата»

```yaml
Risk: 🟡 MEDIUM
owner_gate: yes            # владелец явно попросил план и критерии до выполнения
release_candidate: yes
independently_deployable: yes
stage_base_commit: <фиксируется перед реализацией>
```

### Work items

**1.1 — Query: заменить набор фильтров на (source, date)**
`site/src/Inventory/Infrastructure/Query/InventoryStockReportQuery.php`
- `findLatestSnapshotSessionId()` → `findEffectiveSnapshotDate(string $companyId, MarketplaceType $source, \DateTimeImmutable $date): ?\DateTimeImmutable`
  — `SELECT MAX(snapshot_date) WHERE company_id = :companyId AND source = :source AND snapshot_date <= :date`.
- `getPage(companyId, page, perPage, source, snapshotDate)` — сигнатура без
  `snapshotSessionId`, `snapshotAt`, `search`, `mappingStatus`, `status`.
- `WHERE company_id = :companyId AND source = :source AND snapshot_date = :snapshotDate`
  (IDOR-фильтр остаётся первым, `Assert::uuid($companyId)` сохраняется).
- Нет данных ни на одну дату ≤ выбранной → пустой Pagerfanta (страница рендерится, не падает).
- Список колонок SELECT и `available_for_sale` не меняются.

**1.2 — Controller: два параметра запроса**
`site/src/Inventory/Controller/StockReportController.php`
- Читаем только `source`, `date`, `page`.
- `date`: `\DateTimeImmutable::createFromFormat('Y-m-d', ...)` + проверка на реальную дату;
  пусто/мусор → сегодня.
- `source`: `MarketplaceType::tryFrom()` из белого списка (ozon, wildberries), иначе `OZON`.
- Резолвим эффективную дату через `findEffectiveSnapshotDate()` и передаём в `getPage()`.
- В шаблон: `source`, `sources`, `filters: {date}`, `effectiveDate`, `pager`.
- Неиспользуемые импорты (`Uuid`, `StockSnapshotMappingStatus`, `StockStatus`) удаляются.

**1.3 — Twig: форма из двух полей**
`site/templates/inventory/stocks/index.html.twig`
- Селект «Источник» + `<input type="date" name="date">` (нативный, без JS-пикера) + «Применить»/«Сбросить».
- Удаляются поля `snapshotSessionId`, `snapshotAt`, `search`, `mappingStatus`, `status`.
- Подпись под формой, когда `effectiveDate != date`:
  «Ближайший снимок на 26.07.2026» — иначе пользователь не поймёт, почему дата в таблице другая.
- Пустое состояние: «Нет остатков на выбранную дату».
- Пагинация продолжает наследовать активные фильтры через `app.request.query.all|merge`.

**1.4 — Тесты**
`site/tests/Functional/Inventory/Controller/StockReportControllerTest.php` — переписать под новый контракт:
- по умолчанию (без параметров) — последний доступный срез, старый не показывается;
- `?date=<день без синхронизации>` — показывается ближайший предыдущий срез + подпись о фактической дате;
- `?date=<день раньше всех снимков>` — 200 и пустое состояние;
- `?date=` мусор (`?date=not-a-date`) — 200, fallback на сегодня;
- `?source=wildberries` — данные другого источника не смешиваются;
- удалённые параметры (`?mappingStatus=unmapped&search=xxx`) больше не фильтруют — в выдаче обе записи.

**1.5 — ARCHITECTURE.md**
Обновить раздел `## Query — Inventory` → `InventoryStockReportQuery`: новый список фильтров
(`source`, `snapshotDate`), метод `findEffectiveSnapshotDate()`, семантика «на дату».

### Stage checks

```
make site-test-unit
docker compose exec -T php-fpm vendor/bin/phpunit tests/Functional/Inventory   # таргетированно
make site-test
make site-cs-check                      # baseline красный по репозиторию — сверяем только изменённые файлы
```
(`make stan` в проекте нет — статический анализ не запускается.)

### Reviewer focus

- IDOR: `company_id` в обоих запросах, `Assert::uuid()`.
- Пагинация не потерялась, `PER_PAGE` ≤ 200.
- Нет второго запроса в цикле (эффективная дата резолвится один раз).
- Обработка невалидной даты не даёт 500.

---

## Критерии готовности (Definition of Done)

**Наблюдаемое поведение**
1. На `/inventory/stocks` ровно два фильтра: «Источник» (ozon / wildberries) и «Дата» (нативный date-input) + «Применить» и «Сбросить».
2. Без параметров открывается последний доступный срез остатков по Ozon — как сейчас.
3. `?date=YYYY-MM-DD` показывает остатки на эту дату; если снимка в этот день нет — ближайший предыдущий, с видимой подписью о фактической дате снимка.
4. Дата раньше первого снимка → HTTP 200 и пустое состояние, не ошибка.
5. Невалидная дата (`?date=31.02.2026`, `?date=abc`, `?date[]=1`) → HTTP 200, fallback на сегодня.
6. Смена источника не смешивает данные Ozon и Wildberries.
7. Пагинация сохраняет выбранные источник и дату при переходе по страницам.
8. Старые ссылки с удалёнными параметрами открываются без ошибок; параметры игнорируются.

**Код**
9. `InventoryStockReportQuery::getPage()` принимает `companyId, page, perPage, source, snapshotDate` — и ничего больше; мёртвые ветки фильтров удалены, а не закомментированы.
10. Каждый метод Query принимает `string $companyId` и фильтрует по нему; `find($id)` без companyId отсутствует.
11. Нет неиспользуемых импортов и переменных в контроллере и шаблоне; нет `dump()`/`dd()`.
12. Явное перечисление колонок в SELECT сохранено; `SELECT *` не появилось.
13. Бизнес-логики в контроллере нет — только разбор HTTP-параметров и рендер.

**Тесты**
14. Все сценарии из Work item 1.4 покрыты функциональными тестами и зелёные.
15. `make site-test` зелёный либо расхождение с baseline документировано как pre-existing.
16. `make site-cs-check` по изменённым файлам чист (baseline репозитория красный — фиксируем цифрой).

**Документация и сдача**
17. `ARCHITECTURE.md` описывает новый контракт Query.
18. Stage Report в `docs/tasks/inventory-stocks-filters/stages/stage-1.md`.
19. Внешнее ревью diff'а завершено `REVIEW_GREEN`.
20. Коммит с `refactor(inventory):`, push отдельной ветки, Draft PR обновлён; merge/deploy — только по отдельной команде владельца.

## Открытые вопросы

Нет — оба вопроса закрыты владельцем 2026-08-01:
семантика даты «на дату» (ближайший предыдущий снимок), источники в селекте — только Ozon и Wildberries.
