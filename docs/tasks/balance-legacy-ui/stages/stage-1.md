### Stage 1: оболочка — DONE
Risk: MEDIUM. Stage base commit: `c0f68e7b9a98b7175ccff01a5f2014a66c37ef0b`. Work items: 1.1, 1.2.

- Тест оболочки покрывает 22 основных/вложенных экрана, выбор app, страницу ошибки 422, active вкладки/меню.
- Layout и ошибки переведены на legacy; вкладки используют готовый partial с «Ещё».
- История сопоставлена типу объекта; сторно — документам; карточки статей — справочникам.
- DoD выполнен. Исходный baseline: 31 tests / 248 assertions.
- Red: новый shell-тест — 1 failure, отсутствует html[data-ui-mode=legacy].
- Green: Balance functional — 32 tests / 390 assertions; lint:twig — 3/3.
- Internal review: 1 проход, BLOCKER/IMPORTANT 0/0. Тест h1 уточнён корневым контейнером (legacy header содержит собственный h1).
- External: не требуется на MEDIUM Stage, запланирован на handoff.
- Коммит отложен до конца задачи по утверждённому плану (один коммит).
- Далее: Stage 2 автоматически.
