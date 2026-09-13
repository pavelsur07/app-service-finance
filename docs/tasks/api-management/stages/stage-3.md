# Stage 3 — каталог и подготовленные разрешения

Risk: HIGH-LOCAL. Base: `36b773b3`. Work items: 3.1, 3.2, 3.3. DONE. Definition of Done выполнен.

Добавлены каталог 16 конкретных scopes и явные наборы, независимые selectedScopes/enabledResources с пустыми значениями по умолчанию, вычисление действующих прав и проверка политики endpoint. Все предметные ресурсы отключены. Action не разрешает заранее включить отключённый ресурс: последующее подключение требует отдельного действия владельца.

Сохранение прав проверяет фактического владельца, блокирует и перечитывает ключ в компании, сравнивает версию и атомарно записывает изменения с аудитом. Конфликт версии — 409; неизвестные права и включение неподключённого ресурса — 422. No-op не создаёт событие; права не продлевают срок. Аутентификация перечитывает подготовленные и действующие права.

Файлы: Api Domain/Entity/Application/Security/exception mapping, migration Version20260913090200, API tests и test-only routes/services, README и ARCHITECTURE.md.

Проверки: Api module + write gate 338 tests / 1354 assertions PASS; focused PHPStan 0 errors; CS 54 files, один порядок импортов исправлен и повторная проверка чистая. Миграция применена только в локальном TEST. Матрица 16×16 проверяет независимость действий; HTTP проверяет запрет отключённых ресурсов и свежее чтение. Тесты записи проверяют stale browser/identity map, владение, no-op и rollback после фактического SQL UPDATE. Production route collection проверена: auth/check есть, test permission routes отсутствуют.

Независимый внутренний review: 0 BLOCKER / 0 IMPORTANT / 0 MINOR. Внешний review: round 1, REVIEW_GREEN, 0 BLOCKER / 0 IMPORTANT / 2 MINOR. Оба MINOR исправлены: тест HTTP-маппинга409/422 и удаление избыточного array_values перед sort. Проверка исправлений:14 tests /42 assertions PASS, PHPStan/CS PASS; fixed without re-run.

Следующий Stage: реальная форма матрицы прав и HTTP 409 с перечитыванием, затем полные handoff gates.
