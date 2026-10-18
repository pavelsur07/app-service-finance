### Stage 3: страница «Покрытие Сервис GO» — DONE

**Risk:** MEDIUM · **Base:** этап 2 (`7a9490a93` + итерации ревью) · **Branch:** `feat/ozon-fetcher-coverage-admin`
**Внешнее ревью:** нет (на handoff задачи)

## 1. Какую задачу решаем

Админ должен за один взгляд видеть, что `ozon-fetcher` загрузил за месяц: по
каждому отчёту и дню — сколько кабинетов из всех загружено, что в работе, что
упало. Данные уже лежат в витрине (этап 2); нет экрана. Страница читает только
витрину (`service-gateway.md` §2.6) и открывается, даже когда сервис лежит.

## 2. Что будет результатом работы

- **Контроллер** `App\ServiceGateway\Controller\Admin\OzonFetcherCoverageController`:
  `__invoke`, `#[IsGranted('ROLE_ADMIN')]`, маршрут
  `admin_service_gateway_ozon_fetcher_coverage` →
  `GET /admin/services/ozon-fetcher/coverage?month=YYYY-MM`.
  - `month` пуст — текущий месяц (МСК); вне M, M-1, M-2 или кривой — redirect на
    текущий с flash.
- **Модель представления** `Application/View/CoverageHeatmapBuilder` (чистый класс,
  без БД) строит сетку из `ServiceCoverageMonth`:
  - строки — отчёты сервиса в фиксированном порядке с русскими названиями:
    начисления по дням (дни месяца), реализация и взаиморасчёты (одна ячейка
    на месяц), справочник типов начислений (одна ячейка `ref`). Незнакомый
    `resource_type` — отдельной строкой с техническим именем, не теряется;
  - ячейка:
    - `ok` — `done = cabinets_expected`;
    - `partial` — загружено не всё, нет `failed_permanent`;
    - `err` — есть `failed_permanent`;
    - `empty` — задач нет;
    - `future` — день ещё не наступил.
  - подпись ячейки `3/4`, подсказка: отчёт, период, разбивка по статусам,
    последняя успешная загрузка;
  - шапка: «данные на HH:MM» (`refreshed_at`), «устарело», если снимку больше
    30 минут, процент покрытия месяца (`done` / ожидаемое по прошедшим дням),
    кабинетов в снимке.
- **Шаблон** `templates/admin/service_gateway/ozon_fetcher_coverage.html.twig`
  на `admin/base.html.twig`, классы кита: сетка — `kit/components/coverage.css`
  (`.coverage-grid`, `.cov-cell--ok|partial|err|empty|future`, `.cov-legend`),
  шапка — `.wz-head`, `.wz-kpi-row`, статус «устарело» — `status status--warning`.
  Свой CSS — только раскладка (ширина колонки месяца), без цветов.
- **Переключатель месяцев** — только M, M-1, M-2.
- **Пустая витрина** — понятное пустое состояние («данных ещё нет: обновление
  раз в 10 минут»), не 500.
- **Подключение:**
  - блок `servicegateway_controllers` в `config/routes.yaml`;
  - префикс `App\ServiceGateway\Controller\Admin\` в `EXEMPT_PREFIXES`
    `ModuleAccessMap`, как у `Marketplace\Controller\Admin`;
  - пункт «Покрытие Сервис GO» в группе Ingestion сайдбара; подсветка группы
    дополняется этим маршрутом;
  - шаблоны админки — обычным путём `admin/…`, `twig.yaml` не меняется.
- **Документы:** раздел ServiceGateway в `ARCHITECTURE.md` (страница), маршрут.

## 3. Что считаем выполненной работой

Админ открывает страницу и видит тепловую карту текущего месяца по витрине:
четыре строки отчётов, дни, цвета по правилам, подсказки, «данные на» и
«устарело»; может переключиться на M-1 и M-2. Пользователь без `ROLE_ADMIN`
получает 403. Пустая витрина и месяц вне окна обрабатываются без ошибок.
Вёрстка сверена скриншотом на локальном стенде с данными из этапа 2.

## 4. Чек-лист приёмки

- [x] `CoverageHeatmapBuilder`: unit-тесты на все цвета (`ok`, `partial`, `err`, `empty`, `future`), подпись, подсказку, процент, «устарело» на границе 30 минут, незнакомый ресурс
- [x] строки: by-day — дни месяца; реализация, взаиморасчёты, справочник — одна ячейка
- [x] будущие дни — `future`, `—`; сегодняшний день отмечен
- [x] месяц: пусто → текущий; M-1, M-2 — открываются; M-3, будущий, кривой → redirect + flash
- [x] `ModuleAccessMap` exempt + `ControllerAccessCoverageTest` зелёный
- [x] functional-тесты: 200 админу с данными витрины в HTML; 403 пользователю; пустая витрина; месяц вне окна; «устарело»
- [x] пункт меню, подсветка группы Ingestion
- [x] дизайн-система: только классы кита и semantic-токены, без хардкода цветов, `vf_number` для чисел
- [x] визуальная проверка: скриншот на локальном стенде (светлая тема), ширина 1280 и 390
- [x] `ARCHITECTURE.md`
- [x] PHPStan / CS / strict_types по изменённым файлам; baseline не растёт

## Итог

- Контроллер `OzonFetcherCoverageController`, `CoverageHeatmapBuilder` + DTO `CoverageHeatmap*`, шаблон
  `templates/admin/service_gateway/ozon_fetcher_coverage.html.twig` (кит: `coverage.css`, `page-tabs` через
  `app/_ui/_tabs.html.twig`, `kpi`, `empty`, `status`), маршрут в `routes.yaml`, exempt в `ModuleAccessMap`,
  пункт меню в группе Ingestion.
- Тесты: unit `CoverageHeatmapBuilderTest` (6: все состояния, подписи, подсказки, процент, граница «устарело»,
  незнакомый ресурс, пустая витрина), functional `ServiceGatewayOzonFetcherCoveragePageTest` (5: данные витрины
  в HTML, «устарело», пустая витрина, M-1/M-2 и redirect для прочих, 403 без `ROLE_ADMIN`),
  `ControllerAccessCoverageTest` зелёный.
- Найдено тестом и исправлено: справочник без данных в текущем месяце показывался «будущим» — шаг строки
  (день / месяц / справочник) теперь задан явно.
- Визуальная проверка (стенд: витрина из локального ozon-fetcher с моком, 3 кабинета; 1280 и 390):
  по скриншотам исправлены подчёркнутые ссылки месяцев (переход на `page-tabs`) и невидимая ячейка месячного
  отчёта текущего месяца (подпись «ещё нет»).
- Вне задачи, замечено: хлебные крошки админки везде «Панель управления» (TODO в `admin/partials/_header.html.twig`);
  на 390 px сайдбар админки не сворачивается — общее для всех страниц админки.
