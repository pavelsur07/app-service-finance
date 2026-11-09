### Stage 5: разбиение крупных шаблонов — PLANNED (старт только по отдельной команде Владельца, решение 08.11.2026)

**Work items:** 5.1 (`plan.md`, раздел Stage 5)
**Risk:** LOW — только структура шаблонов; разметка и поведение 1:1

#### Задача
Ни один шаблон страницы не длиннее ~300 строк: части — партиалы модуля (`_filters`, `_table`, `_row`, `_modals`, `_totals`)
с `{% include … only %}`; макросы — в `_macros.html.twig` модуля. Отрендеренный HTML на одних и тех же данных идентичен до и после.

#### Замер «до» (08.11.2026, ветка PR D Stage 4)
Страниц длиннее 300 строк — 15 (без писем и партиалов):

| Строк | Шаблон | Таблиц | Форм | Модалок (строк `class="modal` / `<dialog`) | Макросов |
|---|---|---|---|---|---|
| 810 | `marketplace/wb_finance_report` | 6 | 1 | 0 | 3 |
| 574 | `report/cashflow` | 3 | 1 | 0 | 4 |
| 544 | `marketplace/connections` | 0 | 8 | 33 | 0 |
| 537 | `finance/report/preview` | 1 | 1 | 0 | 1 |
| 465 | `admin/ingestion/external_categories/index` | 6 | 7 | 2 | 0 |
| 463 | `marketplace/pl_preview/index` | 7 | 1 | 0 | 1 |
| 456 | `marketplace/inventory/index` | 2 | 5 | 21 | 0 |
| 412 | `report/cashflow_ops_check` | 2 | 1 | 0 | 0 |
| 394 | `transaction/index` | 1 | 2 | 20 | 0 |
| 385 | `marketplace/pl_mappings` | 1 | 4 | 14 | 0 |
| 368 | `pl_category/import` | 3 | 6 | 0 | 0 |
| 348 | `marketplace/month_close/index` | 1 | 4 | 0 | 0 |
| 342 | `marketplace/index` | 2 | 3 | 13 | 0 |
| 332 | `budget/plan_fact` | 1 | 3 | 0 | 7 |
| 324 | `marketplace/listings/index` | 1 | 2 | 7 | 0 |

Функциональных тестов, бьющих по этим маршрутам, — 39 файлов (grep по URL); перед каждым PR проверить, что у каждой страницы PR есть
тест на 200, иначе добавить (чек-лист приёмки задачи).

#### Метод (одинаков для всех PR)
1. «До»: снять отрендеренный HTML страницы на стенде (скрипт скриншотов умеет логин в оба firewall; добавить режим `page.content()`),
   нормализовать (`_token`/`csrf`, `sf-toolbar`, хеши бандлов) → `scratchpad/html-5x/before/<page>.html`.
2. Резать по смыслу, не по строкам: фильтры → `_filters`, шапка+строки таблицы → `_table` (+ `_row`, если строка > ~40 строк или
   повторяется), модалки → `_modals`, итоги → `_totals`, макросы → `_macros` модуля (`{% import %}` в потребителях).
   Каждый include — `with {…} only`: партиал получает только то, что использует (список переменных — grep по партиалу).
3. «После»: тот же снимок → `diff` должен быть пустым. Пробелы/переводы строк между тегами допустимо нормализовать, но различие
   показывать в отчёте явно. Скриншоты — не нужны (HTML идентичен ⇒ пиксели идентичны).
4. Гейты: `make site-cs-twig`, тесты модуля, `make site-test`, внешнее ревью. В отчёт — таблица «шаблон: строк до → после, партиалы».

#### Ловушки, известные заранее
- `only` отрезает `app` и глобальные переменные Twig? Нет: `app`, globals и функции доступны всегда; отрезаются только переменные
  контекста страницы — поэтому `is defined` в партиале должен проверяться на том, что реально передано.
- Макросы внутри страницы, которые используют переменные контекста неявно, при переносе в `_macros` их теряют — передавать аргументами.
- `{% block %}` внутри `{% embed %}` (page header, `_empty`) в партиал не переносится — остаётся в странице.
- `data-*` для JS (Stage 2) часто ищется от корня страницы (`document.querySelector`) — перенос в партиал не ломает, но `only` без
  нужной переменной даст пустой атрибут; снимок HTML это поймает.

#### План PR (по 3–4 шаблона, от крупных к мелким)
- PR A: `wb_finance_report` (6 таблиц → `_table_<name>` ×6 + `_macros`), `report/cashflow` (`_filters`, `_table`, `_row`, `_macros`),
  `finance/report/preview` (`_filters` уже отдельно, резать `_table`/`_row`).
- PR B: модальные страницы — `connections` (`_modals`, `_connection_card`), `marketplace/inventory/index`, `transaction/index`,
  `pl_mappings` (у всех — `_modals` + `_table`).
- PR C: `admin/ingestion/external_categories/index` (6 таблиц → по вкладкам), `pl_preview/index`, `cashflow_ops_check`, `pl_category/import`.
- PR D: `month_close/index`, `marketplace/index`, `budget/plan_fact` (7 макросов → `_macros`), `listings/index`.
- Закрывающий PR: отчёт DONE, пересъёмка счётчиков снимка «до» из `plan.md` §0, чек-лист приёмки задачи.

#### Чек-лист этапа
- [ ] 5.1 — страниц > 300 строк: 15 → 0 (`for f in templates/**/*.html.twig (не _*) … wc -l`)
- [ ] у каждого разрезанного шаблона — пустой `diff` отрендеренного HTML «до/после» в отчёте PR
- [ ] каждый include партиала — с `only`
- [ ] Каждый PR: `make site-cs-twig`, `make site-test`, внешнее ревью; тест на 200 у каждой страницы PR
