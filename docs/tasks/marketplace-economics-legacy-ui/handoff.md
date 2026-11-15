# marketplace-economics-legacy-ui — handoff (не завершён)

**Branch:** `fix/marketplace-economics-legacy-ui`
**PR:** https://github.com/pavelsur07/app-service-finance/pull/3012 (Draft, base master)

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
- Финальные целевые PHPUnit: 10 tests / 95 assertions; frontend tests: 152 passed.
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
- Внешнее: 0 завершённых раундов. Команда `site/bin/external-review.sh 5aff7dbfd0f11f4c0d2181e04eda7743920018f9 --effort medium` завершилась на preflight (exit 2): `ревьюер 'claude' не установлен`. Поиск исполняемого файла в PATH и стандартных локальных каталогах кандидата не нашёл; каталога конфигурации Claude нет. Не заявляется REVIEW_GREEN.

## Ограничения и продолжение
- Восстановить установленный и авторизованный Claude Code, выполнить обязательное read-only ревью штатным скриптом, исправить подтверждённые находки и завершить handoff.
- После зелёного внешнего ревью обновить PR, пометить Ready и один раз запросить merge + deploy. Пока PR остаётся Draft; merge/deploy не разрешены и не выполнялись.
- Сопутствующие проблемы общего sidebar и историческая запись layout в ARCHITECTURE.md перечислены в stage-2.md; оставлены без исправления согласно границам плана.
