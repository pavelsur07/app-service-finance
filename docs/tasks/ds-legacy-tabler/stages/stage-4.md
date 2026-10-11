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
