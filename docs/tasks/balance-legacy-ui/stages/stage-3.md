### Stage 3: формы и приёмка — DONE (внешний handoff review блокирован средой)
Risk: MEDIUM. Stage base commit: `c0f68e7b9a98b7175ccff01a5f2014a66c37ef0b`. Work items: 3.1, 3.2, 3.3.

- Все формы используют существующую bootstrap_5_layout; локальная дублирующая тема удалена.
- Ошибки CSRF/валидации, подсказки, checkbox и кнопки переведены на Bootstrap.
- Stimulus-контроллер и финансовые расчёты не менялись; сохранены row/prototype/index, side/currency и события.
- Удаление/архивация используют существующий единственный confirm_modal: адрес POST, CSRF, version/archive передаются явно; JSON экранирован. Подтверждение нулевого открытия осталось обязательным в исходной форме.
- Функционально проверены создание/изменение счета, статья, черновик, проведение, сторно, расчет остатка, выдача прав, reader/foreign company, filters и pagination (включая все фильтры журнала).
- Тесты темы/сторно сначала red (2 failures), затем Balance 37 tests / 500 assertions; journal pagination отдельно 1 / 8.
- Browser: реальные HTML-рендеры PHPUnit с изолированными данными + production legacy bundle/CDN, Chromium 1440/375. Начальные 40 экранных проверок прошли, но дополнительная проверка mobile-menu доказала существующую коллизию d-flex/collapse. Исправлено только для balance_*; subtitle разделён d-block. Финальный повторный рендер из текущих исходников и browser smoke: PASS, 40/40, включая закрытое по умолчанию мобильное меню и его переключение. Баланс functional финально: 38 / 530.
- PHPStan: OK (4268); Twig: OK (406); PHP style и strict types: OK (4304); unit: OK (4819 / 26765); frontend lint/build и 152 unit tests: OK.
- Bundle: legacy JS 123920 → 123920 bytes; CSS 48224 → 48924 (+700 bytes, 1.45%). Только локальные layout-правила под .vf-balance, без новых entry и зависимостей.
- Internal: свежий агент + допроверки mobile/title и logging-теста — 0 BLOCKER/IMPORTANT/MINOR. При визуальной приёмке исправлены заголовок и mobile collapse. Открытых находок нет.
- External: обязательный handoff не завершён: скрипт exit 2, `ревьюер 'claude' не установлен`. REVIEW_GREEN не заявляется.
- Full suite: после исправления пользователя локального контейнера — 9262 tests / 52066 assertions, единственный устаревший селектор формы удаления в logging-тесте. Адаптирован к submitConfirmTrigger с сохранением 422/warning/no ERROR+; Logging + Balance повторно OK 40 / 541, focused CS green. Полный набор после адаптации теста не повторялся.
- Один итоговый коммит по approved plan. Далее handoff после полного набора тестов; Draft до внешнего review.
