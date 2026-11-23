# marketplace-economics-legacy-ui

Утверждённый план: возврат оформления «По SKU», «Динамика», «Качество данных» в легаси.
База: `5aff7dbfd0f11f4c0d2181e04eda7743920018f9`.
Границы: только три шаблона, локальные UI-ресурсы, Vite entry и тесты страниц.
Контроллеры, расчёты, запросы, API, БД, финансовые обозначения, общие layout и эталоны не меняются.

## Stage 1: оболочка и управление
Risk: MEDIUM
stage_base_commit: 5aff7dbfd0f11f4c0d2181e04eda7743920018f9
Definition of Done:
- [x] Тесты легаси-оболочки, вкладок, GET-фильтров и переходов написаны до реализации.
- [x] Три страницы наследуют base.html.twig, используют оформление заголовка ДДС, общие легаси-вкладки и пагинацию.
- [x] Поля, применение фильтров, передача query, ошибки и график сохраняют поведение.
Work items:
- 1.1: baseline и регрессионные функциональные тесты.
- 1.2: перевод оболочки и контролов, тесты, self-review.
Stage checks: targeted PHPUnit, focused PHP CS Fixer, twigcs и lint:twig.
Reviewer focus: GET-параметры, активные вкладки, суммы, отсутствие backend-изменений.

## Stage 2: таблицы и локальное поведение
Risk: MEDIUM
stage_base_commit: 0bfc44af1
Definition of Done:
- [x] Таблицы повторяют unit-extended; колонки, значения, сортировка и итоги сохранены.
- [x] Первая колонка, шапка и синхронная горизонтальная прокрутка работают на широком и узком экране.
- [x] Локальный CSS/JS подключён отдельным Vite entry только на этих страницах.
Work items:
- 2.1: тесты таблиц и неизменности поведения, локальный CSS/JS.
- 2.2: браузерная проверка и сопоставление с эталонами, исправления, self-review.
Stage checks: targeted PHPUnit, Twig, frontend lint/build/test:unit, браузерный smoke.
Reviewer focus: геометрия шапки, пересечение закреплений, длинные значения, доступность.

## Handoff и приёмка
- [x] Полные гейты: make site-stan, site-cs-check, site-cs-strict-types, site-test-unit, site-test; Twig; npm lint/build/test:unit.
- [x] Тесты сумм, итогов, alloc, группировки, XLSX, изоляции компаний, сортировки и пагинации.
- [x] Пустые состояния, ошибки, отсутствие расхождений, длинные значения.
- [x] Внутреннее ревью в свежей сессии.
- [x] Внешнее read-only ревью Codex CLI вместо Claude по прямому указанию владельца: новая ephemeral-сессия, REVIEW_GREEN.
- [x] Один PR #3012 в master; передача Ready после проверок. Merge/deploy только по отдельному разрешению.

## Baseline
- Первый запуск целевых PHPUnit: 6 тестов, 5 errors — отсутствовала локальная app_test; один redirect-тест прошёл. Подготовка тестовой БД продолжается.
- После создания app_test и применения существующих 318 миграций baseline зелёный: 6 tests, 34 assertions.
- Vite baseline: build passed (предупреждение об отсутствующем @symfony/ux-turbo уже было); legacy CSS 48.22 kB, vf_custom_classes 38.86 kB, unit_extended 2.01 kB CSS / 39.13 kB JS.
