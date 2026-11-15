### Stage 4: legacy UI и финальная приёмка — DONE

**Risk:** HIGH-LOCAL. **Stage base:** `17a0bc3bf`.
**Work items:** 4.1, 4.2, 4.3, 4.4.

#### Результат
- Общий legacy layout содержит отдельные вкладки «Маппинг Ozon» и
  «Маппинг Wildberries», сохраняющие подключение. Twig/Tabler и существующие
  partials; новых React/Vite entry, JS/CSS и зависимостей нет.
- Выбор подключения 0/1/N; страницы листингов по 25, целей/истории по 20.
- Ручной выбор product/variant, точные подсказки артикула и локальный поиск.
  Текущая связь, причины недоступности, дата импорта, история snapshots.
- READ/WRITE, active company, CSRF до Form, scoped 404, own-outside-choices
  422, CAS/idempotency 409, POST/redirect 303. CAS сохраняется при поиске.
- Отдельное снятие связи: существующая confirm modal + noscript форма.
  История удалённого листинга доступна по серверному snapshot.

#### Проверки
- UI RED: 11 функциональных cases, 10 failures на отсутствующих маршрутах.
- Первоначальный GREEN: 11/79; расширенный functional module 48/345 PASS.
- Дополнительные две регрессии внутреннего review RED: replay после замены/
  архивации и запись между preflight down и исполнением queued SQL.
- Полный текущий module/related suite после исправлений: 394 tests /
  1662 assertions PASS. Actual Action/import races проверены в обеих
  очередностях для INSERT variant и архивации parent.
- Mutation check: замена Action lock на обычный find даёт 2 failures;
  восстановленный production lock даёт GREEN. В тестах мониторинга PostgreSQL
  добавлен pg_stat_clear_snapshot перед повторным чтением новых workers.
- Browser: Chromium desktop1440/mobile375, назначение Ozon product и WB
  variant/размер M, история, JS confirm modal и no-JS clear PASS, 0 JS errors.
  Внешних API-вызовов нет. Отдельная локальная БД и mock session тестового
  Symfony; локальный router явно наследует только task environment.
- При замене SELECT * сначала пропущен parent_external_id: module warning и
  browser screenshot выявили ошибку; колонка возвращена, повторный module и
  browser smoke чистые. Ошибки не скрывались и baseline не менялся.
- Full gates: unit 4819/26765, PHP CS/strict, Twig407 PASS; full PHPStan
  содержит 30 unmatched baseline в посторонних неизменённых файлах, focused
  task stan PASS. Full suite 9254/51773 с одним прежним Ozon timeout5s;
  весь соответствующий класс отдельно GREEN4/42. Первый full был прерван137.
  Полные ограничения и результаты сохранены в handoff.md.

#### Внутреннее review
- Fresh-context gpt-6-astra, полный diff от task base, только spec/diff/checklists.
- Найдено B/I 1/2 + MINOR 1; все исправлены и проверены:
  atomic ACCESS EXCLUSIVE + recheck + DROP в одном DO; accepted HTTP replay
  независимо от текущих choices с повторной проверкой payload в Action;
  полные Action/import races; явные SQL-колонки.
- Не оценивал production/external API, будущую финансовую семантику; browser
  вид и Bootstrap дополнительно проверены исполнителем реальным Chromium.

#### Внешнее review
- Final Claude CLI, effort medium, полный task diff, 1 раунд — REVIEW_GREEN.
- B/I 0/0, MINOR 2. Пустой trim-search нормализован в null: regression
  RED на лишнем LIKE; Facade suite GREEN 23/69, focused CS/PHPStan PASS.
- Совет hoist forConnections отклонён технически: два вызова запрашивают
  разные entityType, перенос в переменные не уменьшает SQL; текущий Query
  принимает один тип, isReady решает другую задачу. Нового batch API не добавлено.
- Reviewer получает pointer на исключённый fixture worker и факты уже
  исправленных находок; write/Git mutation/external services запрещены.
