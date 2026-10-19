# Stage 2 — API: назначение тега по фильтру

- `stage_base_commit`: `e4f46ddae`
- Риск: HIGH-LOCAL — меняется публичный контракт
  `POST /api/marketplace/listings/tags/assign`. Изменение совместимое:
  старое тело `{listingIds, tagId|name}` работает как раньше.

## 1. Какую задачу решаем

API назначения тега принимает только явный список `listingIds` (до 500).
Браузер знает id только строк текущей страницы, поэтому «все 108 по
запросу» назначить нельзя. Нужен второй способ выбора: по фильтру, который
сервер сам превращает в листинги тем же запросом, что строит список
(`MarketplaceListingsQuery::matchingIds`, этап 1).

## 2. Что будет результатом

Контракт после этапа:

```text
POST /api/marketplace/listings/tags/assign            MARKETPLACE_WRITE
{ "listingIds": [uuid…],              "tagId": uuid | "name": str }   — как раньше
{ "filter": {filter, marketplace, q, tag}, "tagId": uuid | "name": str } — новое
→ 200 { tagId, tagName, matched, assigned }
```

| Ситуация | Ответ |
|---|---|
| нет ни `listingIds`, ни `filter` | 422 `listing_ids_required` (как раньше) |
| есть и `listingIds`, и `filter` | 422 `listing_selection_required` |
| невалидный `filter` | 422 `filter_invalid` |
| под фильтр попало больше 5000 | 422 `filter_too_broad`, ничего не назначено |
| фильтр ничего не нашёл | 200, `matched = 0`, `assigned = 0` |

`matched` — сколько листингов выбрано (по `listingIds` — сколько из них
принадлежит компании; по фильтру — сколько нашлось). `assigned` — сколько
связей реально создано. `matched − assigned` — «у скольких тег уже был».

### Шаги

| # | Work item | Файлы |
|---|---|---|
| 2.1 | `ListingTagPayload`: поле `?ListingsFilter $filter`, разбор `filter` через `ListingsFilter::fromPayload()`, взаимоисключение с `listingIds`; unit-тест | `ListingTagPayload.php`, `tests/Unit/Marketplace/Application/DTO/ListingTagPayloadTest.php` |
| 2.2 | `ListingFilterTooBroadException` (`final`, `\RuntimeException`) | `src/Marketplace/Exception/` |
| 2.3 | `AssignListingTagAction`: режим фильтра через `matchingIds(limit + 1)`, лимит — аргумент конструктора `int $maxFilterMatch = 5000`; назначение порциями по 500 в той же транзакции; `matched` в результате; `INFO`-лог в режиме фильтра | `AssignListingTagAction.php`, `ListingTagAssignResult.php` |
| 2.4 | `matched` для режима `listingIds`: новый `ListingTagAssignmentRepository::countCompanyListings(companyId, ids)` | `ListingTagAssignmentRepository.php` |
| 2.5 | Контроллер: `matched` в ответе, `filter_too_broad` → 422 | `ListingTagAssignController.php` |
| 2.6 | Тесты: functional по API, integration на лимит | `ListingTagsApiTest.php`, `tests/Integration/Marketplace/Application/AssignListingTagActionTest.php` |
| 2.7 | Тесты модуля, PHPStan/CS по изменённым, внутреннее ревью, итог, коммит | — |

Внешнее ревью этапа не нужно: дифф этапа заведомо меньше 500 строк;
внешнее ревью задачи — на handoff (§7.2).

## 3. Что считаем выполненной работой

- 2.1–2.7 сделаны, тесты этапа и модуля зелёные.
- Старые тесты `ListingTagsApiTest` не менялись по смыслу и зелёные —
  совместимость старого контракта.
- PHPStan по изменённым файлам без ошибок, CS чисто.
- Внутреннее ревью: нет открытых BLOCKER / IMPORTANT, особо — IDOR.
- Итог в этом файле, `checkpoint.md` обновлён, локальный коммит.

## 4. Чек-лист приёмки этапа

- [x] Назначение по фильтру `{q: "навол"}` ставит тег всем совпавшим листингам компании, в том числе вне первых 25.
- [x] Ответ содержит `matched` и `assigned`; повтор → `assigned = 0`, `matched` тот же.
- [x] Листинги другой компании под тем же фильтром тег не получают.
- [x] Тег другой компании по `tagId` → 404 `tag_not_found` (как раньше), в режиме фильтра тоже.
- [x] `filter` + `listingIds` → 422 `listing_selection_required`.
- [x] Невалидный `filter` → 422 `filter_invalid`.
- [x] Больше лимита → 422 `filter_too_broad`, связей не создано (тест с лимитом 2).
- [x] Пустой результат фильтра → 200, `matched = 0`.
- [x] Старый режим `listingIds`: прежние тесты зелёные, `matched` = число листингов компании из списка.
- [x] Без права `MARKETPLACE_WRITE` → 403 (существующая проверка; покрыть тестом, если не покрыта).
- [x] INFO-лог режима фильтра без текста поиска, с `companyId`, `tagId`, `matched`, `assigned`.
- [x] PHPStan / CS по изменённым файлам чисто.

## 5. Итог

Сделано 19.10.2026, все пункты чек-листа закрыты.

- Тесты этапа: `ListingTagPayloadTest` 6, `AssignListingTagActionTest` 2,
  `ListingTagsApiTest` +5 сценариев и +3 кейса невалидного тела; старые
  сценарии API не менялись и зелёные (совместимость `listingIds`).
- Набор модуля `tests/{Unit,Integration,Functional}/Marketplace`: 1761 тест OK.
- PHPStan по изменённым файлам: No errors. CS: одно выравнивание phpdoc в
  `ListingTagAssignResult` исправлено, повтор чистый.
- `ARCHITECTURE.md`: раздел «Marketplace: теги листингов» — фильтр реестра,
  ограничение `IN`, контракт `assign`; Changelog 2.30. Перенесено из этапа 4
  сюда: контракт поменялся в этом этапе.

Отклонения от плана этапа:

- Без `listingIds` и без `filter` — прежний код `listing_ids_required`
  (а не `listing_selection_required`), чтобы не ломать существующий
  контракт; `listing_selection_required` — только когда переданы оба.
- Выборка (`matchingIds` / подсчёт `matched`) вынесена **до** транзакции:
  `filter_too_broad` не открывает транзакцию и не закрывает EntityManager
  (в ORM 3 исключение внутри `wrapInTransaction` его закрывает).

Внутреннее ревью диффа от `e4f46ddae`: BLOCKER 0, IMPORTANT 0.
Проверено: IDOR — выборка `matchingIds` с `companyId` и повторная проверка
`l.company_id` в `assign()`; тег — `findById($companyId, …)`; тест на тег
чужой компании в режиме фильтра (404, связей 0) и на листинги чужой
компании под тем же фильтром (не получают тег). Конкурентность: листинг,
удалённый между выборкой и вставкой, отсекается `WHERE` в `assign()`;
повтор гасится `ON CONFLICT`. Пустой объект `filter: {}` = все листинги
компании — осознанно (фильтр «все»), подтверждение с числом — этап 3.
Лог: INFO без текста поиска.

