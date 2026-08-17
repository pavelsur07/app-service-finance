# План: фиксированные ширины и залипающая первая колонка в `/finance/report/preview`

Задача: привести таблицу отчёта P&L Preview к раскладке отчёта ДДС — первая колонка с
категориями закреплена слева, колонки периодов фиксированной ширины и прокручиваются
горизонтально. Только легаси-интерфейс.

## Что уже есть (факты из кода)

- `templates/finance/report/preview.html.twig` (317 строк) — единственный шаблон отчёта.
  `PlReportPreviewController::preview` рендерит только его (три `render()`, все на этот файл).
- Шаблон наследует `base.html.twig` → `_layout/legacy.html.twig`. **Легаси-интерфейс — это он и
  есть**: у app-режима экрана P&L нет вообще (`templates/app/` содержит только `_shell` и `home`),
  поэтому требование «менять только в легаси» выполняется самой правкой этого файла.
- Эталон — `templates/report/cashflow.html.twig:6-39,161-166`: `table-layout: fixed`, `<colgroup>`,
  CSS-переменные ширин, `position: sticky; left: 0` на `th/td:first-child`, `box-shadow` вместо
  границы, инлайновый `min-width: calc(...)` по числу периодов. Обёртка `.table-responsive` даёт
  горизонтальный скролл. Селекторы вида `.table.cf-table` — чтобы правила не перебивались `.table`
  из `legacy_app`, который грузится до `{% block stylesheets %}`.
- В preview обёртка `.card > .table-responsive > table.table.table-vcenter` уже есть
  (`preview.html.twig:152-154`) — контейнер прокрутки менять не нужно.
- Две раскладки в одном шаблоне: `layout=periods` (колонки периодов) и `layout=projects`
  (колонки проектов + «Итого»). Плюс опциональные мета-колонки «Код» и «Тип» (`showMetaColumns`).
- Ячейка названия сейчас: инлайновый `padding-left` по уровню + `white-space: nowrap` и
  `<span class="pl-toggle" style="...">`. Инлайн-JS в конце шаблона строит дерево по
  `data-row-id`/`data-level`, ищет `.pl-toggle`, пишет `innerHTML` и `style.display`.
- Ярлыки периодов (`PlReportGridBuilder:85-120`): день `31.12.2026`, месяц `2026-05`,
  неделя `Неделя 22 (25.05.2026 — 31.05.2026)` — неделя в 2 раза длиннее остальных.
- Разметку preview больше никто не использует: `.pl-row`/`.pl-toggle` не встречаются ни в одном
  другом шаблоне, CSS или JS; из тестов маршрут трогает только
  `tests/Functional/Finance/PlReportPreviewControllerTest.php`.
- В `assets/styles/vf-custom-classes.css:27-30` лежит мёртвое семейство `.vf-pnl-*` (нигде не
  подключено, на токенах `--vf-*`). Не использовать: легаси-таблица живёт на Tabler-токенах.

## Решения по ширинам

Ширины фиксированные (`table-layout: fixed` + `<colgroup>`), значения — из длины реального
содержимого, а не «на глаз».

| Колонка | Ширина | Обоснование |
|---|---|---|
| Строка (категория) | 280px | как в ДДС; влезает отступ 3 уровней (60px) + название |
| Код (опц.) | 110px | короткие коды в `<code>` |
| Тип (опц.) | 120px | |
| Период, `grouping=month` | 120px | `2026-05` |
| Период, `grouping=day` | 110px | `31.12.2026` |
| Период, `grouping=week` | 210px | `Неделя 22 (25.05.2026 — 31.05.2026)` не влезает в 120px |
| Проект (`layout=projects`) | 160px | имена проектов + пометка «(Shared)» |
| Итого (`layout=projects`) | 140px | |

Ширина периода — одна CSS-переменная, значение выбирается в Twig по `grouping`. Всё, что не влезло,
обрезается `text-truncate` и раскрывается в `title` (как в ДДС).

Числовые ячейки получают `font-variant-numeric: tabular-nums` — разряды выстраиваются по вертикали.
Это единственное добавление сверх копии ДДС-рецепта.

## Stage 1: фиксированные ширины и залипающая первая колонка

Risk: 🟢 LOW (шаблон и CSS легаси-интерфейса; исполняемая бизнес-логика не меняется)
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: зафиксировать перед реализацией (текущий `master`)

### Definition of Done
- Первая колонка не уезжает при горизонтальной прокрутке в обеих раскладках, при `show_meta`
  включённом и выключенном.
- Ширины колонок не «пляшут» от содержимого: `table-layout: fixed` + `colgroup` действуют
  (проверяется тем, что таблица шире контейнера и скроллится, а не сжимает колонки).
- Длинные названия категорий, проектов и ярлыков недель обрезаны и имеют `title`.
- Разворачивание/сворачивание строк работает как раньше (инлайн-JS не тронут по контракту).
- Тёмная тема Tabler: фон залипшей ячейки совпадает с фоном таблицы (`--tblr-bg-surface`).
- Отчёт ДДС и любые другие страницы не затронуты.

### Work items

**1.1 — CSS-блок и переменные ширин**
Добавить в `preview.html.twig` `{% block stylesheets %}` со стилями по образцу `cf-table`:
`.table.pl-table { --pl-col-name / --pl-col-meta-code / --pl-col-meta-type / --pl-col-period /
--pl-col-project / --pl-col-total; table-layout: fixed; }`, `white-space: nowrap` на `th/td`,
ширины `col.pl-col-*`, залипание `th:first-child, td:first-child` (`position: sticky; left: 0;
z-index: 2; background: var(--tblr-bg-surface); box-shadow: inset -1px 0 0 var(--tblr-border-color)`),
`tabular-nums` для `.text-end`, стили `.pl-toggle`. Класс `pl-table` — на `<table>`.

**1.2 — colgroup и min-width для `layout=periods`**
`<colgroup>`: `pl-col-name`, опционально `pl-col-meta-code` + `pl-col-meta-type`, затем по одному
`pl-col-period` на период. На `<table>` инлайном: `--pl-col-period` по `grouping`
(`week` → 210px, `day` → 110px, иначе 120px) и
`min-width: calc(var(--pl-col-name) + <meta> + {{ periods|length }} * var(--pl-col-period))`.

**1.3 — colgroup и min-width для `layout=projects`**
То же самое с `pl-col-project` по числу проектов и `pl-col-total` для «Итого».
*Точка решения владельца:* закреплять ли «Итого» справа (`position: sticky; right: 0`).
Рекомендация — да, это самая читаемая колонка раскладки; +4 строки CSS. По умолчанию в плане
считаю включённым; скажи «без залипания Итого» — уберу.

**1.4 — ячейка названия и заголовки**
Ячейку названия перевести на `d-flex` + `.pl-toggle`/`.pl-toggle-spacer` + `<span class="text-truncate"
title="…">`, инлайновые стили тоглов убрать в CSS (`padding-left` по уровню остаётся инлайновым —
он зависит от данных). Заголовкам периодов и проектов добавить `title` и обрезку. Контракт для
инлайн-JS не меняется: `.pl-toggle` остаётся на каждой строке, `data-row-id`/`data-level` — тоже.

**1.5 — регрессионные тесты**
Расширить `PlReportPreviewControllerTest`: для `layout=periods` и `layout=projects` проверить, что
`<colgroup>` содержит ровно `1 + (show_meta ? 2 : 0) + N` элементов `<col>`, что на таблице есть
класс `pl-table` и инлайновый `min-width`, и что при `grouping=week` подставлена широкая
переменная. Это ловит рассинхрон colgroup с числом колонок — единственную настоящую регрессию,
которую тут можно поймать автоматически.

**1.6 — ручной smoke**
1440 / 1280 / 768 px, `grouping` day/week/month, `layout` periods/projects, `show_meta` on/off,
светлая и тёмная тема Tabler, сворачивание/разворачивание строк, компания без данных (пустая строка
с `colspan`).

### Stage checks
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite functional --filter PlReportPreviewControllerTest`
- `make site-test` (полный прогон; для БД поднять `site-postgres` и `site-redis`)
- `docker compose run --rm site-php-cli composer cs:twig` — линт Twig; php-cs-fixer здесь не при чём,
  PHP-файлы не меняются
- внутренний review диффа → внешний read-only review Codex до `REVIEW_GREEN` → Stage Report →
  commit/push → Draft PR

### Reviewer focus
- colgroup строго соответствует числу `<th>`/`<td>` в обеих раскладках и при `show_meta`
- `min-width` считается по той же формуле, что и число колонок (иначе таблица сожмётся)
- инлайн-JS тоглов не сломан структурой ячейки названия
- фон залипшей ячейки перекрывает уезжающие ячейки в обеих темах
- никаких изменений вне `preview.html.twig` и его теста

## Вне scope

- Отчёт ДДС и его CSS не трогаем.
- App/React-интерфейс — экрана P&L там нет.
- Залипающая шапка таблицы (`thead` по вертикали) — отдельная задача, если понадобится.
- Вынос общего рецепта залипающей таблицы в `vf-custom-classes.css` — по правилу трёх: сейчас таких
  таблиц две, дублирование дешевле преждевременной абстракции. Follow-up, когда появится третья.
- Мёртвое семейство `.vf-pnl-*` в `vf-custom-classes.css` — не чистим в этой задаче.

## Файлы

- `site/templates/finance/report/preview.html.twig` — modified (единственный файл с изменением UI)
- `site/tests/Functional/Finance/PlReportPreviewControllerTest.php` — modified
- Миграций нет, PHP-код не меняется, `ARCHITECTURE.md` обновлять нечем.
