# listings-server-search-bulk-tag — поиск по всем листингам и тег на всю выборку

> Статус: в работе с 19.10.2026, ветка `feat/listings-server-search-bulk-tag`.
> Класс Large (меняется публичный контракт `assign`). Этапы — раздел 7,
> ход работ — `checkpoint.md`. Модуль **Marketplace**, страница
> `/marketplace/listings`. Другие модули, миграции и `messenger.yaml` не
> трогаем. Объём: пункты 1–4 из обсуждения 19.10.2026. Приведение `х`/`×`/`x`
> и `ё`/`е` (пункт 5) — отдельная задача.

## 1. Какую задачу решаем

Поле «Поиск на странице…» фильтрует только 25 строк, загруженных в браузер:
JS прячет `<tr>` (`site/templates/marketplace/listings/index.html.twig:380`),
сервер про поиск не знает. «Выбрать все» и массовое назначение тега тоже
работают в пределах страницы, а API принимает только явный список
`listingIds` (не больше 500, `ListingTagPayload::MAX_LISTING_IDS`).

Из-за этого нельзя сделать основной сценарий: найти все товары с нужным
названием и поставить им тег за одно действие.

### Подтверждение на проде (19.10.2026, только чтение)

ИП Лазарева (`3f4d87cc-a967-43a7-9b5e-8a9113c0a910`):

| Показатель | Значение |
|---|---|
| Листингов всего | 636, 26 страниц по 25 |
| `name ILIKE '%навол%'` | **108**: WB 55, Ozon 53, все активные, не привязаны к товару |
| Из них на 1-й странице | 6 |
| Страниц с совпадениями | 22 из 26 |
| Совпадений в `supplier_sku` | 0 |
| Тег `навол-скл-1` | создан, назначений **0** |

Сейчас поиск находит 6 из 108. Чтобы разметить остальные, нужно пройти
22 страницы вручную.

Окружение прода, проверенное для решения:

- БД `en_US.utf8`: `lower()` и `ILIKE` работают с кириллицей
  (`lower('НАВОЛОЧКИ Ё') = 'наволочки ё'`).
- Больше всего листингов у одной компании — 636. Полный перебор
  листингов компании по индексу `idx_company_marketplace` дешёвый, триграммный
  индекс не нужен.

## 2. Что будет результатом

1. **Поиск на сервере.** `GET /marketplace/listings?q=навол` ищет по всем
   листингам компании без учёта регистра в `name`, `supplier_sku` и
   `marketplace_sku`. Работает вместе с `filter` (все / привязанные /
   без привязки), `marketplace` и `tag` и сохраняется при переходе по
   страницам. Над таблицей — «Найдено: N». Поиск на клиенте удалён.
2. **Выбор всех по запросу.** Если на странице отмечены все строки и
   результатов больше, чем на странице, появляется плашка «Выбраны 25 на
   странице. **Выбрать все N по текущему фильтру**». В этом режиме тег
   назначается по фильтру: браузер отправляет фильтр, сервер сам находит
   листинги. Перед отправкой — подтверждение «Присвоить тег „X“ N листингам?».
3. **Понятный итог.** После назначения: «Тег „X“: назначен N листингам,
   у M уже был» (сейчас страница просто перезагружается молча).
4. **Фильтр по тегу.** `?tag=<uuid>` — листинги с тегом, `?tag=none` —
   листинги без тегов. Выпадающий список тегов в шапке таблицы.

## 3. Что считаем выполненной работой

- Код, тесты и документация из раздела 4 в одной ветке, один PR.
- Гейты зелёные: `make site-stan` (без роста baseline), `make site-cs-check`
  (прогон без кеша, оба конфига), `make site-cs-strict-types`,
  `make site-test`.
- Регрессионный тест «совпадение со 2-й страницы находится» красный на
  `master` и зелёный в ветке.
- `ARCHITECTURE.md` обновлён (раздел тегов листингов + строка Changelog).
- Визуальная проверка на локальном стенде: скриншоты поиска, плашки
  «выбрать все», итога назначения и фильтра по тегу.
- Handoff с планом приёмки на проде (раздел 6).

## 4. Детальный план работ

### 4.1. Фильтр реестра — один DTO на страницу и API

Новый `final readonly class ListingsFilter` в `src/Marketplace/Application/DTO/`.
Это единственное описание фильтра. Страница и назначение по фильтру строят
его одинаково, поэтому «показали 108» и «назначили 108» считаются одним
и тем же запросом.

```php
final readonly class ListingsFilter
{
    public const SEARCH_MAX_LENGTH = 100;
    public const TAG_NONE = 'none';

    public function __construct(
        public ?bool $mapped = null,                 // null — все
        public ?MarketplaceType $marketplace = null,
        public ?string $search = null,               // trim, '' → null
        public ?string $tagId = null,                // uuid
        public bool $withoutTags = false,            // ?tag=none
    ) {}

    /** HTML-страница: невалидные значения игнорируются, как сейчас marketplace. */
    public static function fromQuery(InputBag $query): self;

    /** API: невалидные значения → InvalidListingTagPayloadException (422). */
    public static function fromPayload(mixed $data): self;

    /** Параметры для ссылок пагинации и формы: filter, marketplace, q, tag. */
    public function toQueryParams(): array;

    public function isEmpty(): bool;
}
```

Правила разбора:

| Параметр | Страница (`fromQuery`) | API (`fromPayload`) |
|---|---|---|
| `filter` | `mapped` / `unmapped` / иное → все | то же, иное → 422 `filter_invalid` |
| `marketplace` | `MarketplaceType::tryFrom`, иное → null | иное → 422 `filter_invalid` |
| `q` | `trim`, обрезка до 100 символов, `''` → null | длиннее 100 → 422 `filter_invalid` |
| `tag` | uuid или `none`, иное → игнор | иное → 422 `filter_invalid` |

Тег из `tag` принадлежность компании проверяет не DTO, а запрос в
`ListingTagAssignmentRepository`: там всегда есть `company_id`, поэтому
тег чужой компании просто ничего не находит.

### 4.2. Query: поиск и тег в `MarketplaceListingsQuery`

`site/src/Marketplace/Infrastructure/Query/MarketplaceListingsQuery.php`:

- `paginate(string $companyId, ListingsFilter $filter, int $page, int $perPage)` —
  сигнатура меняется, вызов один (контроллер).
- Новый `matchingIds(string $companyId, ListingsFilter $filter, int $limit): list<string>` —
  те же условия, `SELECT l.id`, сортировка как у списка, `setMaxResults($limit)`.
- Общий приватный `baseQueryBuilder(string $companyId, ListingsFilter $filter)`:
  список и `matchingIds` строятся из него, условия не расходятся.

Поиск (образец — `CounterpartyRepository`, `LOWER(...) LIKE` +
`mb_strtolower`):

```php
$term = '%'.addcslashes(mb_strtolower($filter->search), '%_\\').'%';
$qb->andWhere('LOWER(l.name) LIKE :q OR LOWER(l.supplierSku) LIKE :q OR LOWER(l.marketplaceSku) LIKE :q')
   ->setParameter('q', $term);
```

В Postgres по умолчанию `\` — escape-символ для `LIKE`, поэтому `%`, `_`
и `\` в запросе пользователя ищутся буквально. Это проверяет тест.

Тег. Таблица `marketplace_listing_tag_assignments` в ORM не замаплена, DQL
её не видит. Поэтому сначала id, потом `IN`:

- `tagId` → `ListingTagAssignmentRepository::listingIdsByTags($companyId, [$tagId], false)`
  → `l.id IN (:tagListingIds)`; пустой список → результат пустой
  (`1 = 0`), без запроса с пустым `IN`.
- `withoutTags` → новый `ListingTagAssignmentRepository::taggedListingIds(string $companyId): list<string>`
  → `l.id NOT IN (:tagged)`, если список не пуст.

На проде у компании максимум 636 листингов, список id на сотни элементов
в `IN` приемлем. Если вырастет до десятков тысяч — переносить на DBAL или
маппить таблицу связей (в задачу не входит, записать в `ARCHITECTURE.md`
как известное ограничение).

`countAll` / `countMapped` / `countUnmapped` не меняются: карточки сверху —
сводка по компании, не по поиску.

### 4.3. Контроллер страницы

`site/src/Marketplace/Controller/MarketplaceListingsController.php` (не
легаси-зона, `src/Marketplace/Controller`):

- `$filter = ListingsFilter::fromQuery($request->query)`, дальше
  `paginate($companyId, $filter, $page, self::PER_PAGE)`.
- В шаблон: `listings_filter` (сам DTO), `filter_params`
  (`toQueryParams()`), `nb_results` (`$pager->getNbResults()`).
- Шаблонные переменные `filter` и `marketplace` оставить — на них
  завязаны карточки сверху.

### 4.4. Шаблон и JS

`site/templates/marketplace/listings/index.html.twig`:

- Шапку фильтров превратить в `<form method="get">`: скрытый `filter`,
  `select name="marketplace"`, новый `select name="tag"` (все теги /
  «Без тегов» / теги компании из `all_tags`), `input name="q"` с
  `placeholder="Поиск по названию, артикулу, SKU"`. Отправка по Enter и по
  `change` у select'ов; `page` при этом сбрасывается. `onchange`-навигацию
  по URL у select'а маркетплейсов убрать.
- Удалить клиентский поиск (`search-input`, обработчик на `:380`).
- Под шапкой: «Найдено: N». При активном поиске/теге — ссылка
  «Сбросить», ведёт на `?filter=<текущий>`.
- Пагинация: `params: filter_params` вместо `{filter, marketplace}`.
- Пустой результат при активном поиске: «По запросу «…» ничего не найдено»
  вместо «Загрузите данные из маркетплейса».
- Панель массовых действий получает `data-applied-filter` — JSON
  **применённого сервером** фильтра (`toQueryParams()`) и
  `data-nb-results`. Фильтр берётся отсюда, а не из поля ввода: если
  пользователь поменял текст в поле и не нажал Enter, назначение не должно
  уйти по другому фильтру.

JS массовых тегов:

- Режим выбора: `page` (отмеченные чекбоксы) или `filter`.
- Отмечены все строки страницы и `nb_results > строк на странице` → плашка
  «Выбраны K на странице. Выбрать все N по текущему фильтру». Клик →
  режим `filter`, текст «Выбраны все N. Снять выбор». Снятие любой
  галочки → обратно в `page`.
- Назначение в режиме `filter`: `confirm('Присвоить тег «X» N листингам?')`,
  затем `POST /api/marketplace/listings/tags/assign`
  `{filter: <applied>, tagId | name}`.
- Итог: ответ `{tagName, matched, assigned}` → сообщение «Тег «X»: назначен
  `assigned` листингам, у `matched − assigned` уже был». Показать после
  перезагрузки: записать в `sessionStorage` (чтение/запись в `try/catch`,
  без хранилища — `alert` до перезагрузки), вывести как `alert-success`
  над таблицей.
- Режим `page` и снятие тега (`detach`) работают как сейчас; в ответ
  `page`-назначения тоже добавить `matched`, сообщение — то же.

Визуал — существующие классы DS (Tabler-слой легаси). Ошибки — видимым
текстом в панели, не только в `alert`/тултипе.

### 4.5. API назначения по фильтру

`ListingTagPayload::forAssign()`:

- Ровно одно из `listingIds` / `filter`. Оба или ни одного → 422
  `listing_selection_required` («Укажите listingIds или filter»).
- `filter` → `ListingsFilter::fromPayload()`; новое поле
  `public ?ListingsFilter $filter`; `listingIds` в этом случае `[]`.
- `forDetach()` не меняется — снятие по фильтру не в задаче.

`AssignListingTagAction`:

- Новая зависимость `MarketplaceListingsQuery` (тот же модуль).
- Лимит `int $maxFilterMatch = 5000` — аргумент конструктора (для теста). В режиме фильтра:
  `$ids = $query->matchingIds($companyId, $filter, $this->maxFilterMatch + 1)`;
  больше лимита → `ListingFilterTooBroadException` → 422 `filter_too_broad`
  («Под фильтр попадает больше 5000 листингов, сузьте выборку»).
  Пустой результат → `assigned = 0, matched = 0`, тег всё равно
  создаётся/находится (как сейчас при пустом пересечении).
- Назначение — существующий `assign()` порциями по 500 внутри той же
  `wrapInTransaction`. Компания проверяется дважды: в `matchingIds` и в
  `WHERE l.company_id` внутри `assign()`.
- `ListingTagAssignResult` + поле `matched` (сколько листингов попало в
  выборку). Контроллер отдаёт `{tagId, tagName, matched, assigned}`.

`ListingTagAssignController`: ловит `ListingFilterTooBroadException` → 422.
Новый класс исключения — `src/Marketplace/Exception/`, `final`,
extends `\RuntimeException`.

Логирование: `INFO` «listing tags assigned by filter» с `companyId`,
`tagId`, `matched`, `assigned` (без текста поиска — он может содержать
что угодно, но это не ПДн; оставить `searchLength`).

### 4.6. Тесты

| Тест | Тип | Что проверяет |
|---|---|---|
| `ListingsFilterTest` | Unit | разбор `fromQuery` (невалидное игнорируется, обрезка `q`, `tag=none`) и `fromPayload` (422-коды), `toQueryParams` |
| `ListingTagPayloadTest` (дополнить или создать) | Unit | `listingIds` xor `filter`, `listing_selection_required`, `filter_invalid` |
| `MarketplaceListingsQueryTest` | Integration | кириллица без учёта регистра (`НАВОЛ` находит «Наволочки»); поиск по `supplier_sku` и `marketplace_sku`; `%` и `_` ищутся буквально; тег / `none`; тег чужой компании → пусто; листинг чужой компании с тем же названием не попадает; `matchingIds` уважает `limit` |
| `MarketplaceListingsSearchTest` | Functional | **регрессия**: 30 листингов, искомый — 28-й по `createdAt` → `?q=` показывает его на 1-й странице (красный на `master`); «Найдено: N»; ссылки пагинации несут `q` и `tag`; `?tag=` и `?tag=none` |
| `ListingTagsApiTest` (дополнить) | Functional | happy path по фильтру (`matched`, `assigned`, связи в БД); повтор → `assigned = 0`, `matched` тот же; листинги второй компании под тем же фильтром не получают тег; оба поля → 422; `filter` с невалидным `marketplace` → 422; без `MARKETPLACE_WRITE` → 403 |
| `AssignListingTagActionTest` | Integration | `filter_too_broad` при превышении лимита. Лимит — аргумент конструктора `int $maxFilterMatch = 5000` (автовайринг берёт дефолт), тест создаёт Action с лимитом 2 и тремя листингами — без вставки 5000 строк |

Builder'ы — существующие `tests/Builders/Marketplace/`. В functional —
`clear()` EntityManager после фикстур перед первым запросом.

### 4.7. Документация

- `ARCHITECTURE.md`, раздел тегов листингов (≈ строка 629): параметры
  страницы `q`/`tag`, `ListingsFilter`, контракт `assign` с `filter`,
  `matched`, коды `listing_selection_required` / `filter_invalid` /
  `filter_too_broad`, лимит 5000, ограничение `IN` по id.
- Строка в `Changelog` `ARCHITECTURE.md`.
- `docs/tasks/listings-server-search-bulk-tag/handoff.md` — итог и замеры.

### 4.8. Порядок работ (локальные коммиты, push + PR одним шагом на handoff)

1. `ListingsFilter` + unit-тесты.
2. `MarketplaceListingsQuery` + `taggedListingIds()` + integration-тесты.
3. Контроллер и шаблон: форма, «Найдено», пагинация, удаление клиентского
   поиска + functional регрессия (сначала прогнать на `master` — красный).
4. Payload, Action, исключение, контроллер API + тесты.
5. JS: плашка «выбрать все N», подтверждение, итог.
6. `ARCHITECTURE.md`, гейты по одному (OOM на dev-машине), стенд и
   скриншоты, handoff.

## 5. Риски и решения

| Риск | Решение |
|---|---|
| Пользователь изменил текст поиска и не нажал Enter, тег уходит по другому фильтру | JS берёт фильтр из `data-applied-filter`, а не из поля |
| Между показом «108» и назначением добавились листинги | сервер назначает по актуальному состоянию; `matched` в ответе показывает фактическое число |
| Тег на всю компанию случайно (пустой фильтр) | разрешено (фильтр «все» — законный случай), но подтверждение называет число |
| Рост компании до десятков тысяч листингов | лимит 5000 → 422; `IN` по id — известное ограничение в `ARCHITECTURE.md` |
| IDOR через `tag` или `filter` | все запросы с `companyId`; тесты на чужую компанию в Query и API |
| Размеры записаны `х`/`×`/`x` | вне задачи (пункт 5); поиск по «навол» не затронут |

## 6. Чек-лист приёмки

Локально / CI:

- [ ] `?q=навол` (регистр любой) находит листинги со всех страниц, «Найдено: N» верно.
- [ ] Поиск по артикулу продавца и по SKU маркетплейса работает.
- [ ] `q`, `marketplace`, `tag`, `filter` сохраняются при переходе по страницам и комбинируются.
- [ ] `?tag=<id>` и `?tag=none` фильтруют правильно; тег чужой компании → пусто.
- [ ] `%` и `_` в запросе ищутся буквально.
- [ ] Клиентского поиска «на странице» больше нет.
- [ ] Плашка «Выбрать все N» появляется только когда N больше строк на странице.
- [ ] Назначение по фильтру просит подтверждения с числом и после него показывает «назначен X, у Y уже был».
- [ ] Листинги второй компании не получают тег (тест).
- [ ] Оба поля / ни одного / невалидный фильтр → 422 с кодом; без прав → 403; больше 5000 → 422 `filter_too_broad`.
- [ ] Регрессионный тест красный на `master`, зелёный в ветке.
- [ ] Гейты зелёные, baseline не вырос.
- [ ] `ARCHITECTURE.md` и Changelog обновлены.
- [ ] Скриншоты стенда приложены к handoff.

На проде после деплоя (действия в интерфейсе делает Владелец, агент сверяет
SQL только на чтение):

- [ ] ИП Лазарева, `/marketplace/listings?q=навол` → «Найдено: 108».
- [ ] «Выбрать все 108» → тег `навол-скл-1` → «назначен 108, у 0 уже был».
- [ ] SQL: `count(*)` связей тега `навол-скл-1` = 108, все листинги компании
      `3f4d87cc…`, все с `name ILIKE '%навол%'`.
- [ ] `?tag=<id навол-скл-1>` показывает 108.
- [ ] Ошибок по `/marketplace/listings` и `/api/marketplace/listings/tags/assign` в GlitchTip нет.

## 7. Этапы

Бэкенд и фронтенд — разными этапами. План каждого этапа (задача, результат,
готовность, чек-лист приёмки) пишется в `stages/stage-N.md` до начала работ,
итог дописывается туда же.

| Этап | Что | Риск | Пункты плана |
|---|---|---|---|
| 1 | Серверный поиск и фильтр по тегу: `ListingsFilter`, Query, контроллер страницы | MEDIUM | 4.1, 4.2, 4.3 |
| 2 | API: назначение тега по фильтру | HIGH-LOCAL (публичный endpoint) | 4.5 |
| 3 | Интерфейс: форма фильтров, «Найдено», «Выбрать все N», итог назначения | MEDIUM | 4.4 |
| 4 | Документация, полные гейты, стенд, внешнее ревью, handoff | LOW | 4.7, 6 |
