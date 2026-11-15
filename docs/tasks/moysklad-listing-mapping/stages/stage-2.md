### Stage 2: ревизии маппинга и tenant-инварианты — DONE

**Risk:** HIGH-LOCAL. **Stage base:** `bd3b59bf411639badbdb101ef55abb5c74d535b8`.
**Work items:** 2.1, 2.2, 2.3.

#### Результат
- Одна новая таблица ревизий set/clear, private immutable поля и append-only API.
- Составные tenant FK, уникальности revision/requestId, JSON/target/revision CHECK.
- Серверные snapshots, actor, UTC timestamps, expectedRevision для replay.
- Новые builders product/variant/revision; схема согласована с SchemaExtension.
- Миграция Version20261115153000 создаёт пустую таблицу, без backfill; down
  отказывает при данных. Production не изменялся.

#### Проверки
- RED до реализации: unit 5 cases и storage 15 cases.
- GREEN до review: module 270 tests / 1057 assertions; focused CS/PHPStan PASS.
- Migration up/down/up на пустой isolated DB PASS; down при данных — AbortMigration.
- Schema dump не содержит mapping table/FK/index drift (глобально остаётся
  существующий test_money_holder из тестовой метадаты).
- UUID regression: две формы URN/braced доказанно RED; строгий regex → GREEN.
  Ramsey Uuid::isValid в установленной версии тоже принимает обёртки, поэтому
  использован canonical regex, как альтернативно предложено reviewer.
- Итоговый module run: 280 tests / 1067 assertions — PASS.
- git diff --check PASS.

#### Внутреннее review
- Полный Stage diff: scope, tenant/FK/indexes, SQL NULL, snapshots, no flush в repo,
  rollback guard, Entity без final. BLOCKER/IMPORTANT 0/0.
- 6, затем 2 typing ошибки focused PHPStan исправлены; baseline не менялся.
  Новая миграция добавлена в scanFiles по существующему паттерну regression tests.

#### Внешнее review
- Required: да, HIGH-LOCAL diff 795 строк.
- Claude CLI, effort medium, один раунд: точный маркер REVIEW_GREEN.
- BLOCKER/IMPORTANT 0/0; 3 MINOR подтверждены и исправлены:
  JSON []/JSON null cases; прямые несогласованные constructor arguments;
  strict canonical UUID validation с regression RED→GREEN.
- Повтор не требуется; minor fixes verified without re-run.
- Reviewer только читал; тесты/schema checks выполнял implementing agent.

#### Решения
- expected_revision сохранён отдельной колонкой, чтобы принятый requestId
  сравнивался с исходным CAS payload; constraint revision = expected_revision + 1.
- Один уникальный индекс company/connection/listing/revision покрывает lookup
  последней ревизии обратным проходом; дублирующий DESC-индекс не добавлен.

#### Next
- Автоматически Stage 3: Action/Command и bounded read models, начиная с RED.
