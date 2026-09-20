### Stage 3: фоновый полный проход — CLOSED

**Risk:** HIGH-LOCAL (Messenger, транзакции). **Stage base commit:** `d1fd044b93d2e8eadde98dd0c0436346b204c776`. **Work items:** 3.1, 3.2.

#### What was done

- `SyncCounterpartiesAction` делает два полных прохода active/archive. Advisory lock удерживается между страницами; HTTP работает вне транзакции. Одна страница и её счётчики коммитятся вместе. `cursor` продвигается в одной транзакции с успешным статусом запуска.
- Upsert по `(connection_id, external_id)` читает существующие записи пачкой; повторный проход обновляет `loaded_at`, не создавая дублей. Отсутствие в выборке не удаляет данные.
- После сбоя предыдущие страницы остаются, запуск получает безопасную категорию, старый cursor остаётся. Оставшийся после падения `running` получает `failed/internal` перед новым запуском.
- Сообщение несёт только ID компании/подключения и номер попытки; `async_sync` вызывает Handler. 429 и временные ошибки получают до трёх новых отложенных попыток, задержка ограничена 1 часом и учитывает `X-Lognex-Retry-After`.

#### Definition of Done

- [x] Первичная загрузка, повтор, обновление, архив и сбой второго прохода проверены интеграционными тестами.
- [x] Изоляция компании и блокировка второго процесса проверены на БД.
- [x] `running` после падения восстанавливается; partial unique index защищает конкурентное создание.
- [x] Статус, счётчики и cursor сохраняются по контракту Stage 0.

#### Checks and review

- `tests/Unit/MoySklad tests/Integration/MoySklad` после исправлений — 109 tests / 465 assertions, PASS.
- Focused PHPStan — no errors; focused CS Fixer — 0 fixable files; `debug:messenger` видит Handler.
- Internal review: 0 BLOCKER, 1 IMPORTANT fixed — замена per-row чтения существующих контрагентов на одно tenant-scoped чтение страницы; 0 open findings.
- External review: 1 round, 0 BLOCKER, 2 IMPORTANT, 3 MINOR. Обе IMPORTANT исправлены: `EntityManager::clear()` после каждой страницы ограничивает размер UnitOfWork; добавлен проход через 100+2 записей с проверкой `offset=100`. MINOR исправлены: warning для временных ошибок, unlock не перекрывает исходную ошибку при потере соединения, тест проверяет отсутствие тела ответа/токена в журнале. После исправлений 109 тестов / 465 assertions, focused PHPStan/CS — PASS. По политике: **fixed without re-run**.

#### Risks / next

- Внешний `offset` не гарантирует снимок коллекции, поэтому следующий запуск делает полный проход. Проверка статуса подключений и экран администратора — Stage 4.
