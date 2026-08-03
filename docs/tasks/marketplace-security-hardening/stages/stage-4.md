### Stage 4: H1 — шифрование apiKey MarketplaceConnection (expand + backfill-команда) — DONE (code)

**Risk:** HIGH-LOCAL (+ Production Gate на провижининг ключа и backfill)
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** no
**Next action:** STOP, owner action required

#### Stage scope
- Stage base commit: `c2e7e351`
- Work items completed: 4.1.1, 4.1.2, 4.2.1 (+ scope-уточнение: 4.3/4.4 → contract-задача)

#### What was done
- Миграция `Version20260803200103` (expand, недеструктивная): `api_key_encrypted` TEXT NULL, `api_key_key_version` VARCHAR(32) NULL; plaintext-колонка не тронута; `down()` откатывает
- `ConnectionApiKeyCodec` (на `Shared/Security` sodium secretbox, версии ключей): `applyApiKey` (dual-write), `encryptExisting` (backfill), `apiKeyFor` (decrypt-or-fallback)
- Entity `MarketplaceConnection`: encrypted-поля + accessors; legacy accessor неизменён
- Writers на dual-write: `MarketplaceController::createConnection`, `CreatePerformanceConnectionController` (Ozon Performance client_secret покрыт тем же полем)
- Readers на `apiKeyFor`: `OzonAdapter`, `WildberriesAdapter` (3 места), `OzonRealizationFetcher`, `RefreshWbListingCatalogAction`, `SyncWbFinancialReportDayHandler`, `MarketplaceController` (2 места валидации), DBAL `MarketplaceCredentialsQuery` (decrypt + fallback)
- Команда `app:marketplace:encrypt-connection-keys`: dry-run подсчёт, `--execute` батчами по 100, идемпотентна, вывод без секретов
- Тесты: интеграционный `ConnectionApiKeyEncryptionTest` (dual-write, legacy fallback, DBAL decrypt, backfill dry-run/execute/идемпотентность/отсутствие plaintext в выводе); ~12 существующих тестов адаптированы к новым сигнатурам
- **Отклонение от первоначального DoD:** 4.3 (стоп plaintext-записи) и 4.4 (drop колонки) вынесены в contract-задачу — PR остаётся rollback-safe (деплой одним атомарным шагом не может предшествовать backfill). Зафиксировано в plan.md

#### Files changed
- `site/migrations/Version20260803200103.php` — new
- `site/src/Marketplace/Infrastructure/Security/ConnectionApiKeyCodec.php` — new
- `site/src/Marketplace/Command/EncryptConnectionKeysCommand.php` — new
- `site/src/Marketplace/Entity/MarketplaceConnection.php` — modified
- `site/src/Marketplace/Controller/{MarketplaceController,CreatePerformanceConnectionController}.php` — modified
- `site/src/Marketplace/Service/Integration/{OzonAdapter,WildberriesAdapter,OzonRealizationFetcher}.php` — modified
- `site/src/Marketplace/Application/RefreshWbListingCatalogAction.php` — modified
- `site/src/Marketplace/MessageHandler/SyncWbFinancialReportDayHandler.php` — modified
- `site/src/Marketplace/Infrastructure/Query/MarketplaceCredentialsQuery.php` — modified
- `site/tests/Integration/Marketplace/Infrastructure/Security/ConnectionApiKeyEncryptionTest.php` — new
- 12 существующих тестов (unit/integration) — modified

#### Definition of Done
- [x] 4.1 Expand: миграция + codec-aware чтение/запись, dual-write, rollback-safe
- [x] 4.2 Backfill-команда (dry-run/execute) + тесты
- [x] Секреты не логируются и не попадают в вывод/исключения (тестом подтверждено)
- [x] Ozon Performance client_secret покрыт (то же поле)
- [ ] 4.3/4.4 — перенесены в contract-задачу (зафиксировано)
- [ ] Env-провижининг ключа на проде — owner gate (ниже)

#### Baseline
- Stage 3 final: unit 1722 OK; integration+functional 348 OK

#### Checks
- targeted: `ConnectionApiKeyEncryptionTest` — OK (4 tests, 19 assertions)
- module: `tests/Integration/Marketplace tests/Functional/Marketplace tests/Functional/Admin` — OK (352 tests)
- full stage: `make site-test-unit` — OK (1722 tests); `lint:container` — OK
- миграция применена локально (dev + test БД)

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: двойной decrypt в RefreshWbListingCatalogAction (по MINOR внешнего review)
- FOLLOW-UP: `index.html.twig:48` маскированный вывод apiKey — в contract-задачу (plan.md)

#### External Claude Code review
- Iterations: 1
- Result: REVIEW_GREEN
- Confirmed findings fixed: MINOR (double decrypt) — исправлено
- Rejected findings with reason: none

#### Review fixes applied
- `RefreshWbListingCatalogAction`: один decrypt в локальную переменную

#### Risks / reviewer focus
- **Перед деплоем на прод обязателен ключ шифрования**: без `APP_ENCRYPTION_KEY_FILE` (с версией v1) или `APP_ENCRYPTION_FALLBACK_KEY` запись новых подключений упадёт с `MissingEncryptionKeyException`. В `.env` репозитория fallback пуст
- Для backfill на проде нужен wrapper `codex-console` с allowlist на `app:marketplace:encrypt-connection-keys` (пустые аргументы или ровно `--execute`)
- Rollback-safe: plaintext пишется всегда, откат кода безопасен

#### Checkpoint
- `docs/tasks/marketplace-security-hardening/checkpoint.md` updated
- exact next action: owner gate (ниже), затем финальный Release Gate задачи

#### Open questions
- none

#### Expected owner response
Recommended response:
`Ключ на проде размещён, wrapper добавлен, мерж PR #2291 одобряю, после деплоя запусти backfill --execute`

Alternative responses:
- `Мерж одобряю, ключ и wrapper добавлю сам, backfill запущу вручную`
- `Не мержить, нужны правки: <комментарий>`
