# marketplace-economics-legacy-ui — handoff

**Branch:** `fix/marketplace-economics-legacy-ui`
**PR:** https://github.com/pavelsur07/app-service-finance/pull/3012 (base master; статус Ready и текущий CI — в PR)

## Результат
- Stage 1: три страницы переведены на base.html.twig и легаси-элементы по образцу ДДС.
- Stage 2: таблицы по образцу unit-extended; закрепление первой колонки/шапки, синхронная горизонтальная прокрутка, локальный Vite entry.
- Сохранены расчёты, обозначения, колонки, фильтры, правила query, сортировка, пагинация и экспорт. Миграций и изменений публичных контрактов нет.

## Файлы
- `site/templates/marketplace_economics/{sku,dynamics}.html.twig`, `site/templates/marketplace_quality/index.html.twig`.
- `site/assets/pages/marketplace_economics.js`, `site/assets/styles/pages/marketplace-economics.css`, одна регистрация entry в `site/vite.config.js`.
- Три функциональных теста страниц и документы этой задачи.

## Проверки
- Baseline: 6 tests / 34 assertions passed после подготовки отсутствовавшей локальной app_test.
- Регрессии оболочки proven red: 3 failures; таблиц: 4 failures.
- Финальные целевые PHPUnit: 10 tests / 92 assertions в обоих режимах (с Vite build и без него); frontend tests: 152 passed.
- Frontend lint/build: passed. Предупреждение сборки об отсутствующем @symfony/ux-turbo было и до изменений.
- `make site-cs-check`, `make site-cs-strict-types`: passed (4274 файла); итоговые правки тестов также прошли focused CS.
- `make site-test-unit`: passed, 4807 tests / 26743 assertions.
- `make site-stan`: первый запуск нашёл nullable-тип фикстуры Company; добавлена assertInstanceOf, повторный гейт: no errors, 4238 файлов.
- `make site-cs-twig`: twigcs no violations; lint:twig 403 valid files. Первоначальный lint был заблокирован правами записи dev.log, повтор с локальным override пользователя PHP-контейнера прошёл.
- `make site-test`: первый запуск заблокирован правами записи Composer vendor; повтор с локальным override пользователя контейнера прошёл: 9155 tests / 51409 assertions, 07:02, exit 0.
- Override /tmp/economics-ui-root.yml применён только к локальному CLI, в Git не входит; Docker/CI/production-конфигурация проекта не менялась.
- Browser smoke: три страницы, Chromium 1440×900 и 390×900, длинные значения, скролл и геометрия. Независимая проверка resize: отклонение колонок 0 px. См. stage-2.md.
- Локальные логи и screenshots в site/var и /tmp; в Git не включены.

## Ревью
- Внутреннее: self-review и независимое ревью в свежей сессии; BLOCKER 0 / IMPORTANT 0. Дополнение двух тестов выбранным кабинетом и уточнение типа фикстуры также проверены независимо, новых находок нет.
- Внешнее: по прямому указанию владельца Claude заменён на Codex CLI. Использован штатный скрипт с `--reviewer codex --effort medium --context /tmp/economics-ui-codex-review-context.md`.
- Два запуска в двух новых `codex exec -s read-only --ephemeral` сессиях, без resume/fork и истории реализации. Первый не завершён из-за `bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted`; исправленный повтор получил весь diff, утверждённый план, правила, чек-листы и окружающие исходники через stdin, без инструментов чтения.
- Повтор: exit 0, точный маркер `REVIEW_GREEN`; 1 завершённый раунд, BLOCKER 0 / IMPORTANT 0. Сессия `01a1de6f-1357-7e23-acd5-3b1a49e3bddf`; артефакты локально: `site/var/external-review/5aff7dbf-codex-retry/`. Vite config, не входящий в стандартный набор путей скрипта, передан полностью в context.
- Метрики BLOCKER/IMPORTANT: internal 0/0; external 0/0.

## Исправление CI после Stage 2
- CI SHA 9b2be9a24: Functional 2/3 и 3/3 падали на трёх проверках `script[src*=marketplace_economics]`, зависящих от Vite build. PHP-шарды запускаются без frontend-сборки.
- Воспроизведено локально без build: 10 tests, 3 failures. Удалены только эти три проверки имени JS-файла; UI и проверки таблиц/данных не менялись. Подключение assets проверяют Vite build и browser smoke.
- После исправления: 10 tests / 92 assertions без build и 10/92 с build; focused CS passed. Коммит `d1754a155`.
- Полный локальный PHPUnit 9155/51409 выше относится к версии до удаления этих трёх assertions; повтор полного прогона не требовался, финальные функциональные шарды запускает CI. Актуальное заключение CI отражается в PR.

## Ограничения и продолжение
- Внешнее ревью завершено по разрешённой владельцем замене на Codex CLI. После проверки финального CI — Ready и единственный запрос merge + deploy. Merge/deploy не разрешены и не выполнялись.
- Сопутствующие проблемы общего sidebar и историческая запись layout в ARCHITECTURE.md перечислены в stage-2.md; оставлены без исправления согласно границам плана.
