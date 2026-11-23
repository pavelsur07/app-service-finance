### Stage 2: таблицы, отчёты, фильтры — DONE
Risk: MEDIUM. Stage base commit: `c0f68e7b9a98b7175ccff01a5f2014a66c37ef0b`. Work items: 2.1, 2.2.

- Таблицы в карточках Tabler с table-responsive; заголовки/действия и статусы — legacy DS.
- Сохранены vf_minor/vf_amount/vf_money, точность, знаки движений, красное расхождение с пояснением и уровни статей.
- Пресеты диапазонов заполняют from/to; сравнение подписано как две независимые даты без пресетов.
- Pager macro передаёт явные route/params; ID карточек и type/id истории имеют приоритет над query.
- DoD выполнен; новые проверки фильтров/пагинации сначала дали 2 failures (подписи сравнения, отсутствие legacy table).
- Balance functional: 34 tests / 452 assertions.
- Internal: 1 проход, BLOCKER/IMPORTANT 0/0; исправлены селекторы существующих тестов заголовка и область проверки таблиц статьи.
- Локальный CSS только внутри .vf-balance: сетка KPI и отступы уровней на токенах legacy. Новых entry/зависимостей нет.
- External: MEDIUM Stage не требует, на handoff обязателен.
- Один коммит после Stage 3 по approved plan; далее Stage 3 автоматически.
