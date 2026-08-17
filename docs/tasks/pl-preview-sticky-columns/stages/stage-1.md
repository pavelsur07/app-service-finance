## Stage 1: фиксированные ширины и залипающая первая колонка в `/finance/report/preview` — DONE

**Риск:** 🟢 LOW (шаблон и CSS легаси-интерфейса, исполняемая логика не менялась)
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP, ждать решения Владельца по Draft PR

### Scope Stage
- Stage base commit: `14659c0f`
- Work items completed: `1.1` (CSS-блок), `1.2` (colgroup/min-width для periods), `1.3` (то же для
  projects + залипание «Итого»), `1.4` (ячейка названия и заголовки), `1.5` (тесты), `1.6` (smoke)

### Что сделано
- В `preview.html.twig` добавлен `{% block stylesheets %}` с рецептом отчёта ДДС:
  `table-layout: fixed`, переменные ширин, `position: sticky; left: 0` на первой ячейке,
  `box-shadow` вместо границы, обрезка по `text-overflow`.
- `<colgroup>` для обеих раскладок; ширина таблицы задаётся инлайном
  `min-width: calc(var(--pl-col-name) [+ мета] + N * var(--pl-col-period|project) [+ итого])`.
- Ширина колонки периода зависит от группировки: неделя 210px (ярлык
  «Неделя 22 (25.05.2026 — 31.05.2026)» в 120px не помещается), день 110px, месяц 120px.
- Колонка «Итого» в раскладке «по проектам» закреплена справа (`.pl-cell-total`).
- Ячейка названия переведена на flex + `min-width: 0` + `title`; инлайновые стили тоглов убраны в CSS.
  Контракт инлайн-JS (`.pl-toggle`, `data-row-id`, `data-level`) не менялся.
- Числовые ячейки получили `font-variant-numeric: tabular-nums`.

### Затронутые файлы
- `site/templates/finance/report/preview.html.twig` — modified
- `site/tests/Functional/Finance/PlReportPreviewControllerTest.php` — modified
- `docs/tasks/pl-preview-sticky-columns/plan.md` — new

PHP-кода, миграций и изменений публичных контрактов нет, `ARCHITECTURE.md` обновлять нечем.

### Self-review
- [x] Scope compliance — только легаси-страница отчёта и её тест
- [x] Patterns — рецепт скопирован с работающего отчёта ДДС, новых абстракций не заведено
- [x] Forbidden actions — none
- [x] Security — разметка, данные и доступы не трогались
- [x] Тесты зелёные; twigcs на файле — только предсуществующее нарушение
- [x] ARCHITECTURE.md — N/A

### Проверка на реальных данных
Локальный стек, фикстуры, залогиненный `owner@app.ru`, 4 варианта:

| Запрос | min-width | colgroup |
|---|---|---|
| `grouping=month` | `calc(var(--pl-col-name) + 6 * var(--pl-col-period))` | 1 + 6 |
| `grouping=week` | `--pl-col-period: 210px`, 27 периодов | 1 + 27 |
| `month&show_meta=1` | обе мета-колонки в формуле | 1 + 2 + 6 |
| `layout=projects(&show_meta=1)` | 12 проектов + `var(--pl-col-total)` | 1 [+2] + 12 + 1 |

CSS-блок в отрендеренном HTML идёт после `legacy_app.css`.

### Тесты доказаны мутацией
Тест `testColgroupMatchesHeaderColumns` — не «написан», а проверен: при удалении мета-колонок из
`colgroup` он падает `Failed asserting that 10 is identical to 12` с указанием URL. Остальные два
теста на старом шаблоне падали бы тривиально — там нет ни `table.pl-table`, ни `colgroup`.

### External review
- Reviewer: Codex CLI 0.147.0 (`codex exec -s read-only --ephemeral`, дифф через stdin)
- Iterations: 2
- Result: REVIEW_GREEN
- Confirmed findings fixed: MINOR — правило `td:first-child` залипляло единственную ячейку пустого
  состояния с `colspan`; селектор ограничен `thead th:first-child` и `tbody tr.pl-row > td:first-child`.
- Rejected findings with reason: нет
- Ограничения ревьюера: без шелла и браузера; факты о прогонах, рендере на фикстурах, ярлыках
  периодов и отсутствии app-режима переданы в промпте.

### Команды для проверки
- `docker compose run --rm site-php-cli php bin/phpunit --testsuite functional --filter PlReportPreviewControllerTest` — `OK (3 tests, 19 assertions)`
- `docker compose run --rm site-php-cli php bin/console lint:twig templates/finance/report/preview.html.twig` — OK
- `docker compose run --rm site-php-cli vendor/bin/twigcs templates/finance/report/preview.html.twig` — 1 предсуществующее нарушение

### Риски / на что обратить внимание ревьюеру
- **Пиксельная проверка не делалась**: браузерное расширение в сессии не подключено, а traefik в этом
  чекауте не стартует (несовместимость версии Docker API), поэтому скриншотов нет. Проверено только
  то, что отдаёт сервер. Глазами стоит посмотреть: залипание при прокрутке вправо, тёмную тему
  Tabler, обрезку длинных названий и колонку «Итого» в раскладке по проектам.
- Ширины подобраны под текущие форматы ярлыков; если формат периода поменяется, переменную ширины
  надо пересматривать.

### Открытые вопросы
- Залипающая шапка таблицы по вертикали не делалась — отдельная задача, если понадобится.
