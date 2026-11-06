### Stage 2: страница — DONE

**Risk:** LOW (новый контроллер и Twig-шаблон, вкладка)
**Stage base commit:** `e0961ef72`
**Work items:** 2.1 контроллер, парсер фильтра, шаблон, вкладка

#### What was done
- `StockTurnoverFilterParser::parse(Request, companyId)` — невалидные параметры → умолчания (период 30, все источники/схемы/корзины,
  дата сегодня, будущее → сегодня, теги только валидные UUID).
- `StockTurnoverIndexController` (`GET /marketplace-analytics/stock-turnover`, `ROLE_COMPANY_USER`): `getActiveCompany()` → парсер →
  Query → Pagerfanta `ArrayAdapter` по 50 строк.
- Шаблон: карточка фильтров (btn-group период и схема, селекты источник/корзина/теги), сводка «В товаре / Заморожено / По корзинам /
  без себестоимости», подвал «Остатки: … на дату» и предупреждение об устаревшем источнике, `vf-table` с бейджами корзин, пустое состояние, пагинация.
- Вкладка «Оборачиваемость» в `_tabs.html.twig`.

#### Files changed
- `site/src/MarketplaceAnalytics/Application/StockTurnoverFilterParser.php`, `Controller/StockTurnoverIndexController.php` — new
- `site/templates/marketplace_analytics/stock_turnover/index.html.twig` — new; `_tabs.html.twig` — modified
- `ARCHITECTURE.md` — маршрут и парсер
- tests: `StockTurnoverControllerTest` (new, 11 с провайдером)

#### Definition of Done
- [x] строки по убыванию денег, сводка и баннер даты, корзины словами, «—» без цены
- [x] фильтр корзины сужает строки, сводка не меняется
- [x] невалидные параметры → умолчания, 200
- [x] 51 строка → 50 на странице, ссылки пагинации сохраняют фильтры
- [x] устаревший источник → предупреждение и пустое состояние

#### Checks
- `phpunit StockTurnoverControllerTest` — OK (11 tests, 43 assertions)
- `make site-stan` — OK; `make site-cs-check` — OK
- DS (чек-лист `stage-report-frontend.md`, легаси-слой): деньги `vf_money`, числа `vf_number`, бейджи Tabler `bg-*-lt`,
  без хардкода цветов и inline-стилей; скриншоты на стенде — `screenshots/index.png`, `bucket-frozen.png`, `scheme-fbs.png`
  (сид SQL снесён, роль владельца возвращена к `ROLE_ADMIN`).

#### Internal review
- iterations: 1. Rulings: «Заморожено» без `text-danger` (DS-Money: красный только для ошибок); `page` вне диапазона → 422 по конвенции ExceptionListener.

#### External review
- required: no (handoff — см. stage-3)

#### Risks / reviewer focus
- Список тегов — `select multiple` без JS-виджета (как в легаси-слое); при десятках тегов неудобно, но DS-компонента нет.
- Мобильная вёрстка: «В день» и «Себестоимость» скрыты до `md`, таблица в `table-responsive`.

#### Next
- continue to Stage 3 automatically
