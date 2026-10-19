# listings-server-search-bulk-tag — handoff

**Branch:** `feat/listings-server-search-bulk-tag` · **PR:** см. описание PR · **CI:** см. PR

## Summary of stages
- Stage 1 — серверный поиск `?q=` (название, артикул продавца, SKU маркетплейса,
  без учёта регистра, `%`/`_` буквально) и фильтр `?tag=<uuid>|none`;
  `ListingsFilter` — общее описание фильтра для страницы и API. Регрессия
  «совпадение со 2-й страницы» красная на `master`, зелёная в ветке.
- Stage 2 — `POST /api/marketplace/listings/tags/assign` принимает `filter`
  вместо `listingIds`: листинги находит тот же запрос, что строит список;
  больше 5000 → 422 `filter_too_broad` без создания тега; в ответе `matched`.
- Stage 3 — интерфейс: GET-форма фильтров (маркетплейс, тег, поиск),
  «Найдено: N», «Сбросить», «Выбрать все N по текущему фильтру», подтверждение
  с числом и описанием выборки, итог «назначен A, у B уже был», ошибки текстом.
- Stage 4 — гейты, финальное внутреннее ревью, внешнее ревью Codex, правки.

## Files changed
- `site/src/Marketplace/Application/DTO/ListingsFilter.php` (новый),
  `ListingTagPayload.php`, `ListingTagAssignResult.php`,
  `Application/AssignListingTagAction.php`,
  `Exception/ListingFilterTooBroadException.php` (новый),
  `Infrastructure/Query/MarketplaceListingsQuery.php`,
  `Infrastructure/Query/ListingTagAssignmentRepository.php`,
  `Controller/MarketplaceListingsController.php`,
  `Controller/Api/ListingTagAssignController.php`.
- `site/templates/marketplace/listings/index.html.twig`.
- Тесты: `ListingsFilterTest`, `ListingTagPayloadTest`,
  `MarketplaceListingsQueryTest`, `AssignListingTagActionTest`,
  `MarketplaceListingsSearchTest` (новые); `ListingTagsApiTest`,
  `MarketplaceListingBuilder` (дополнены).
- `ARCHITECTURE.md` — раздел «Marketplace: теги листингов», Changelog 2.30.

## Migrations
- none

## Public API / contract changes
- `POST /api/marketplace/listings/tags/assign`: совместимое расширение.
  Новое тело `{filter: {filter, marketplace, q, tag}, tagId | name}`; оба
  `listingIds` и `filter` → 422 `listing_selection_required`; невалидный
  фильтр → 422 `filter_invalid`; больше 5000 → 422 `filter_too_broad`.
  В ответ добавлено `matched`. Старое тело и его коды ошибок не менялись.
- `GET /marketplace/listings`: новые параметры `q`, `tag`.

## Checks
- `make site-stan` — OK, No errors (baseline не менялся).
- `make site-cs-check` — 0 из 3167 файлов; прогон без кеша — OK.
- `make site-cs-strict-types` — OK.
- unit — 3933 теста OK; полный `phpunit` (своя БД `app_test_lst`) —
  6868 тестов, 38938 assertions, OK.
- После правок ревью: тесты страницы/API/DTO — OK, PHPStan и CS изменённых
  файлов — чисто, `lint:twig` — OK.
- `npm run build` — OK (ассеты не менялись); `npm run lint` встроенный в Twig
  JS не проверяет.
- Smoke на стенде (puppeteer) — 14 сценариев этапа 3 + сценарий находки
  Codex (назначение со 2-й страницы «Без тегов»); данные стенда удалены.

## Reviews
- internal: по этапам 3 итерации (BLOCKER 0, IMPORTANT 0); финальное в свежей
  сессии — BLOCKER 0, IMPORTANT 0, MINOR 5: исправлены 3 (обрезка поиска с
  хвостовым пробелом, итог без `sessionStorage`, «Выбрать все» сверх лимита),
  1 уже в рисках (число в подтверждении может устареть — итог показывает
  фактическое `matched`), 1 отклонён (`isEmpty()` не заменяет `has_filters`:
  тот намеренно не учитывает вкладку привязки).
- external: Codex, 1 раунд, IMPORTANT 1 — после назначения/снятия тега
  перезагрузка сохраняла `page=2`, выборка «Без тегов» сжималась →
  `OutOfRangeCurrentPage`. Исправлено (`1f479a339`, страница сбрасывается,
  фильтры сохраняются), подтверждено на стенде; fixed without re-run
  (§7.2: повтор только после BLOCKER).

## Risks, limitations, follow-ups
- Фильтр по тегу — список id в `IN`/`NOT IN` (таблица связей без ORM).
  На проде максимум 636 листингов у компании; при десятках тысяч —
  переносить в SQL (`EXISTS`). Записано в `ARCHITECTURE.md`.
- FOLLOW-UP: привязка листинга к товару на последней странице вкладки
  «Без привязки» перезагружает с тем же `page` и может упасть так же —
  поведение было до задачи, не трогалось.
- FOLLOW-UP: приведение `х`/`×`/`x` и `ё`/`е` в поиске (пункт 5 обсуждения).
- Число в диалоге подтверждения — на момент загрузки страницы; сервер
  назначает по текущим данным, итог показывает фактическое.

## Приёмка на проде (после деплоя)
Действия в интерфейсе — Владелец, сверка SQL только на чтение — агент.
1. ИП Лазарева, `/marketplace/listings`, поиск «навол» → «Найдено: 108».
2. «Выбрать все» → «Выбрать все 108» → тег `навол-скл-1` → подтверждение
   «…108 листингам?» → итог «назначен 108 листингам».
3. SQL: связей тега `навол-скл-1` = 108, все у компании `3f4d87cc…`, все с
   `name ILIKE '%навол%'`.
4. Фильтр «Тег: навол-скл-1» → «Найдено: 108».
5. GlitchTip: нет новых ошибок по `/marketplace/listings` и `tags/assign`.

## Owner decision
Ready: PR — merge into master with automatic production deploy?
Reply: "merge and deploy #<n>"
