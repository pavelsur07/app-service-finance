# balance-legacy-ui: возврат Баланса к Tabler

Утверждённое задание: [TASK.md](TASK.md). Класс Large; один итоговый коммит и PR.
База задачи и этапов: `c0f68e7b9a98b7175ccff01a5f2014a66c37ef0b`.
Этапы сохраняются без промежуточных коммитов по явному требованию утверждённого плана.
Baseline: `docker exec -e XDEBUG_MODE=off vf-balance-php php bin/phpunit tests/Functional/Balance` — OK: 31 tests, 248 assertions (до любых правок).

## Stage 1: общая легаси-оболочка
Risk: MEDIUM. stage_base_commit: `c0f68e7b9a98b7175ccff01a5f2014a66c37ef0b`.
Definition of Done: основные, вложенные и ошибочные страницы используют Tabler; вкладки и меню учитывают права и текущий маршрут.
Work items: 1.1 — функциональные тесты оболочки, доказать red; 1.2 — layout, вкладки, sidebar и ошибки, доказать green.
Checks: функциональные тесты Balance, lint Twig затронутых файлов.
Reviewer focus: наследование, active состояния сторно/истории/статей, права; бизнес-логика без изменений.

## Stage 2: отчёты, таблицы и фильтры
Risk: MEDIUM. stage_base_commit: та же база задачи (один итоговый коммит).
Definition of Done: карточки и таблицы Tabler с прокруткой; суммы/иерархия сохранены; сравнение двух дат отделено от диапазона; пагинация сохраняет параметры маршрута и запроса.
Work items: 2.1 — тесты фильтров и пагинации до шаблонов; 2.2 — конвертация отчётов, таблиц, KPI и пресетов.
Checks: Balance functional; Twig lint.
Reviewer focus: currency digits, знаки, сравнение дат, ID карточки/истории, горизонтальная прокрутка.

## Stage 3: формы и приёмка
Risk: MEDIUM. stage_base_commit: та же база задачи (один итоговый коммит).
Definition of Done: Bootstrap-тема, ошибки/подсказки/checkbox; строки Stimulus сохранены; подтверждения через легаси-диалог; создание/проведение/сторно/остаток/права проверены.
Work items: 3.1 — тесты действий/форм; 3.2 — формы и подтверждения; 3.3 — браузер 1440/375 и итоговые гейты.
Checks: Balance; на handoff один раз make site-cs-twig, site-stan, site-cs-check, site-cs-strict-types, site-test-unit, site-test; frontend lint/build/test:unit.
Reviewer focus: POST/CSRF/version/confirm_zero, ошибки и a11y, prototype/index/итоги, responsive.

## Чек-лист приёмки
- [x] Все экраны и вложенные формы — legacy независимо от выбора UI.
- [x] Статьи остаются в справочниках; вкладки/Ещё/права корректны.
- [x] Фильтры и pagination сохраняют from/to, query и route params.
- [x] Денежные значения, знаки, валютная точность и дерево сохранены.
- [x] Формы, строки, ошибки, подтверждения и пользовательские сценарии проверены.
- [x] Browser smoke 1440/375 и полный набор гейтов зафиксированы (границы полного повторного прогона — handoff).
- [ ] Свежая внутренняя и внешняя read-only проверки; нет открытых BLOCKER/IMPORTANT.
- [ ] Один коммит, push, один Draft PR → Ready → запрос merge + deploy.

Аналоги: Cash transactions; legacy partials tabs/pagination/period_presets/confirm_modal; Bootstrap-темы существующих легаси-форм. Миграций, зависимостей, API/финансовых изменений нет.
