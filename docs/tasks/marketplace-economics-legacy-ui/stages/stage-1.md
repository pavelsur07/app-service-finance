### Stage 1: оболочка и управление — DONE
**Risk:** MEDIUM
**Stage base commit:** `5aff7dbfd0f11f4c0d2181e04eda7743920018f9`
**Work items:** 1.1, 1.2

- Три шаблона наследуют base.html.twig; заголовки vf-page-head, вкладки и пагинация — существующие легаси-партиалы, контролы/уведомления — Tabler.
- Все прежние URL, query, поля, обозначения и SVG-геометрия сохранены. Backend и общие компоненты не менялись.
- Файлы: три шаблона, три функциональных теста, документы задачи.
- DoD: проверки оболочки, активных вкладок, выбранных значений и правил query написаны до реализации; 3 ожидаемых падения на исходном коде.
- Baseline: 6 tests / 34 assertions после подготовки отсутствовавшей тестовой БД; первый запуск 5 errors из-за отсутствующей app_test.
- Targeted PHPUnit после реализации: 8 tests / 62 assertions, passed.
- twigcs: no violations; lint:twig: 3 valid files.
- Focused CS: исправлены два PHP_URL_QUERY на \\PHP_URL_QUERY; кеш fixer недоступен — последующие проверки с --using-cache=no. Ошибочный вызов нескольких путей без --config исправлен.
- Internal review: 1 iteration, BLOCKER 0 / IMPORTANT 0; безопасные MINOR форматирования исправлены. Передача параметров сравнена с исходным diff.
- External review: на MEDIUM Stage не требуется; запланировано на handoff.
- Браузерная проверка визуала выполняется в Stage 2 вместе с локальным поведением таблиц.
- Далее: Stage 2 автоматически.
