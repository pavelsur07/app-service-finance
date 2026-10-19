# Stage 1 — серверный поиск и фильтр по тегу

- `stage_base_commit`: `ae67bde9c`
- Риск: MEDIUM. Модуль Marketplace, без миграций, без изменения API.

## 1. Какую задачу решаем

Страница `/marketplace/listings` не умеет искать на сервере: поиск в браузере
видит только 25 строк текущей страницы. На проде у ИП Лазарева из 108
листингов «Навол…» поиск находит 6. Фильтра по тегу нет вовсе.

Этап даёт серверу параметры `q` и `tag` и общее описание фильтра, которое
этап 2 переиспользует для назначения тега по фильтру.

## 2. Что будет результатом

- `App\Marketplace\Application\DTO\ListingsFilter` — фильтр реестра:
  `mapped`, `marketplace`, `search`, `tagId`, `withoutTags`;
  `fromQuery()` (страница, невалидное игнорируется), `fromPayload()`
  (API, невалидное → `InvalidListingTagPayloadException` с кодом
  `filter_invalid`), `toQueryParams()`, `isEmpty()`.
- `MarketplaceListingsQuery`:
  - `paginate(string $companyId, ListingsFilter $filter, int $page, int $perPage)`;
  - новый `matchingIds(string $companyId, ListingsFilter $filter, int $limit): list<string>`;
  - общий `baseQueryBuilder()`; поиск `LOWER(...) LIKE` по `name`,
    `supplierSku`, `marketplaceSku` с экранированием `%`, `_`, `\`;
  - тег: `IN` по id из `listingIdsByTags()`, «без тегов» — `NOT IN` по
    `taggedListingIds()`.
- `ListingTagAssignmentRepository::taggedListingIds(string $companyId)`.
- `MarketplaceListingsController` строит фильтр через `fromQuery()`, отдаёт
  в шаблон `listings_filter`, `filter_params`, `nb_results`; пагинация
  несёт `q` и `tag` (минимальная правка шаблона: `params: filter_params`).
  Форма поиска и UI — этап 3.
- Builder `MarketplaceListingBuilder`: `withName()`, `withSupplierSku()`.

### Шаги

| # | Work item | Файлы |
|---|---|---|
| 1.1 | `ListingsFilter` + unit-тест | `src/Marketplace/Application/DTO/ListingsFilter.php`, `tests/Unit/Marketplace/Application/DTO/ListingsFilterTest.php` |
| 1.2 | Query + `taggedListingIds()` + builder + integration-тест | `MarketplaceListingsQuery.php`, `ListingTagAssignmentRepository.php`, `MarketplaceListingBuilder.php`, `tests/Integration/Marketplace/Infrastructure/Query/MarketplaceListingsQueryTest.php` |
| 1.3 | Регрессионный functional-тест на `master` → красный | `tests/Functional/Marketplace/Controller/MarketplaceListingsSearchTest.php` |
| 1.4 | Контроллер + `params` пагинации → тест зелёный | `MarketplaceListingsController.php`, `index.html.twig` (только `params`) |
| 1.5 | Набор тестов модуля по листингам, PHPStan и CS по изменённым файлам, внутреннее ревью, итог, коммит | — |

Сортировка в тестах детерминирована через `UPDATE created_at` после
`flush()`: колонка хранит секунды, листинги одного теста создаются в одну
секунду.

## 3. Что считаем выполненной работой

- Все Work items 1.1–1.5 сделаны, тесты этапа зелёные.
- Регрессионный тест был красным до правки контроллера и зелёный после.
- PHPStan по изменённым файлам без новых ошибок, CS чисто.
- Внутреннее ревью: нет открытых BLOCKER / IMPORTANT.
- Итог дописан в этот файл, `checkpoint.md` обновлён, локальный коммит
  (без push — push и PR на handoff).

## 4. Чек-лист приёмки этапа

- [x] `?q=НАВОЛ` находит «Наволочки» (кириллица без учёта регистра).
- [x] Поиск по `supplier_sku` и `marketplace_sku`.
- [x] `q=50%` ищет буквальный `%`, `q=_` — буквальный `_`.
- [x] Листинг другой компании с тем же названием не попадает ни в список, ни в `matchingIds`.
- [x] `?tag=<id>` — только листинги с тегом; тег другой компании → пусто.
- [x] `?tag=none` — только листинги без тегов.
- [x] `q` + `marketplace` + `filter` + `tag` комбинируются (AND).
- [x] `matchingIds` уважает `limit` и сортировку списка.
- [x] Невалидные `marketplace` / `tag` на странице игнорируются, `q` длиннее 100 обрезается.
- [x] `fromPayload` отдаёт `filter_invalid` на невалидные значения.
- [x] Ссылки пагинации содержат `q` и `tag`.
- [x] Регрессия: совпадение со 2-й страницы видно на 1-й при `?q=` (красный на `ae67bde9c`, зелёный в ветке).
- [x] Карточки «Все / Привязанные / Без привязки» не изменились.
- [x] PHPStan / CS по изменённым файлам чисто.

## 5. Итог

Сделано 19.10.2026, все пункты чек-листа закрыты.

- Регрессия `MarketplaceListingsSearchTest` на `ae67bde9c` (worktree, тесты
  ветки): 3 из 3 красные — 25 строк вместо 1; ссылка
  `?filter=all&page=2` без `q`; `tag=none` вернул 2 строки вместо 1. В ветке —
  3/3 зелёные.
- Тесты этапа: `ListingsFilterTest` 14, `MarketplaceListingsQueryTest` 9,
  `MarketplaceListingsSearchTest` 3 — OK.
- Набор модуля `tests/{Unit,Integration,Functional}/Marketplace`: 1743 теста OK.
- PHPStan (lowmem-конфиг, изменённые файлы): No errors. CS по каждому
  файлу с `.php-cs-fixer.php`, без кеша: чисто.

Отклонения от плана этапа:

- `fromQuery()` принимает массив (`$request->query->all()`), а не
  `InputBag`: generic-тип `InputBag` не сходился в PHPStan, а `q[]=` из
  адресной строки с `InputBag::get()` дал бы 400. Массивы игнорируются (тест).
- Отдельная переменная `nb_results` не нужна — шаблон берёт `pager.nbResults`.
- Добавлена вторая сортировка `l.id DESC`: `created_at` хранит секунды, у
  листингов одной загрузки он совпадает, и страницы могли перемешиваться.
  Это делает «показали» и `matchingIds` одинаковыми по порядку.

Внутреннее ревью диффа от `ae67bde9c`: BLOCKER 0, IMPORTANT 0.
Проверено: все запросы с `companyId` (список, `matchingIds`, теги, «без
тегов»); тег чужой компании → пусто (тест); `LOWER(NULL)` у листинга без
названия не ломает `OR`; N+1 нет (теги страницы — прежние два запроса,
фильтр по тегу — один запрос id). MINOR → этап 3: select маркетплейсов в
шапке пока строит ссылки без `q`/`tag` (форма — этап 3).

