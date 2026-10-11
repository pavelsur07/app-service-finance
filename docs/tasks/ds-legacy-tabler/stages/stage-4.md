### Stage 4: пагинация, Tabs, таблицы — DONE

**Risk:** MEDIUM · **Stage base commit:** `c1675aa6` · **Work items:** 4.1 пагинация, 4.2 Tabs, 4.3 таблицы

#### What was done
- **Пагинация — один partial** `partials/_pagination.html.twig`: `.pagination`
  Tabler, окно ±2, «Назад»/«Вперёд», подпись «Показано с X по Y из N» (числа
  через `vf_money(0)`), активная страница — Action blue. Принимает Pagerfanta
  (`pager`) или `page`/`total`/`perPage`. Удалён `_pagerfanta.html.twig`;
  20 подключений перенесены; самописные копии в `import_log/index`,
  `inventory/snapshots/index`, `marketplace_analytics/cost_mappings/index`,
  `cash/bank1c_import_preview` и свои подписи «Показано» в `inventory/stocks`,
  `marketplace/listings` заменены partial'ом. Контроллеру превью 1С добавлен
  параметр `perPage` в шаблон (одна строка). Вне partial `page-item`/`.pagination`
  в легаси не осталось (`admin/` вне рамок).
- **Tabs — один partial** `partials/_tabs.html.twig`: ссылочные вкладки и
  клиентские Bootstrap (`pane`), иконка, счётчик-пилюля `.cnt` (+ `cnt--warn`).
  Перенесены все 14 наборов; 4 копии вкладок «Операции ДДС» сведены в
  `transaction/_tabs.html.twig`, 2 копии ОПиУ — в `document/_tabs.html.twig`.
  Классы `.nav-tabs .nav-link.active` сохранены — на них завязаны тесты.
  Стиль DS: без «коробки», активная — подчёркивание 2px Action blue. Inline-SVG
  иконки заменены на эквивалентные `ti-*` из уже подключённого webfont.
  Исключение: вкладки в `marketplace/cost_pl_mapping/index` строит JS — на них
  действует CSS, partial неприменим.
- **Таблицы:** tabular-nums в `td/th.text-end`, hover, строка итогов `tfoot`.

#### Вопросы к DS (`TODO(ds)`)
- `marketplace/layout`: 10 вкладок при норме DS 2–6 — группировка раздела —
  решение продукта.
- Имя warn-модификатора счётчика; плотность и hover таблиц.

#### Files changed
- new: `partials/_tabs.html.twig`, `transaction/_tabs.html.twig`, `document/_tabs.html.twig`
- rewritten: `partials/_pagination.html.twig`; deleted: `partials/_pagerfanta.html.twig`
- modified: 29 шаблонов (подключения), `vf-custom-classes.css`,
  `Bank1CImportController.php` (+`perPage`), `StockReportControllerTest.php`
  (новая подпись)

#### Checks
- `lint:twig templates` ✅ 263 файла
- функциональные: Cash, Deals, Inventory, Ingestion, Marketplace,
  MarketplaceAds, MarketplaceAnalytics, Finance, Balance — 542 теста; 2 падения
  на старой подписи «Показано 1–2 из 2» → ожидания обновлены под DS, повтор ✅
- `npm run lint` ✅, `npm run build` ✅, `check:ui-kit` 9081 → **8836**
- PHPStan и cs-fixer на изменённом PHP ✅
- smoke: операции ДДС (вкладки, подпись пагинации), маркетплейсы (10 вкладок с
  иконками), документы ОПиУ.

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none. MINOR fixed: размер иконок вкладок.

#### External review
- required: no (MEDIUM)

#### Next
- Stage 5 (пилот) автоматически

#### Сверка с листами DS-Tabs / DS-Table / DS-Feedback (2026-10-11)
- Вкладки: 14/12/12px, gap 4, текст 500 Navy 600 → hover Navy 900 + линия
  Navy 300 → активная Action blue + 2px; фокус — outline внутри; недоступная
  .45. Счётчик h20 · радиус 10 · 12/600, Navy 100 / Blue 50; warn — `.cnt.warn`
  (имя с листа, закрыт `TODO(ds)`). Иконки у вкладок убраны («без иконок и
  эмодзи»). Больше 6 вкладок — пять видны, остальные в «Ещё»; если активная
  спрятана, переключатель показывает её название (маркетплейсы, 10 вкладок —
  закрыт `TODO(ds)`). «Авто-правило прим.» → «Авто-правило применено».
- Таблицы: шапка Navy 50 · 11/16 · 700 · +5%; строка 14px, `.table-sm` 8px;
  hover Navy 50, выбранная Blue 50; итоги крупнее и жирнее.
- Пагинация: кнопки 30px, граница Navy 300, текущая — Primary, «Назад/Вперёд» —
  стрелки с `aria-label`, подпись 13px.
- Проверки: `lint:twig` ✅ 263, функциональные 9 модулей ✅ 542, `npm run lint`/`build` ✅.
