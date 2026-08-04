# План: env-only ротация ключей шифрования (tooling)

Источник: бриф владельца в чате — ротация ключа `APP_ENCRYPTION_FALLBACK_KEY` полностью через GitHub Secrets, без key-файла на хосте.

Цель: дать безопасный механизм ротации `v1 → v2` (и далее) для `marketplace_connections.api_key_encrypted`: старые версии читаются из карты ключей в секрете, новые записи — активной версией, перешифровка — командой.

Фактура (проверено):
- `FileBasedSecretKeyProvider` (`site/src/Shared/Security/Service/FileBasedSecretKeyProvider.php`): `resolveKeyMaterial` = файл → fallback-env (только текущая версия). Версий >1 из env сейчас не поддерживает.
- Проводка: `services.yaml:9-10` (params `app.encryption.key_file`, `app.encryption.current_key_version`), `:385-391` (провайдер + алиасы); `.env:91-93` (KEY_FILE, CURRENT_KEY_VERSION=v1, FALLBACK_KEY=); `.env.test:18-20` (тестовый fallback-ключ).
- `SecretRotationService::rotate()` — decrypt старой версией + encrypt активной (готов).
- `ConnectionApiKeyCodec` (`site/src/Marketplace/Infrastructure/Security/`) — точка доступа к полю; единственное шифрованное поле в системе — `marketplace_connections.api_key_encrypted` (+ `api_key_key_version`).
- Прод-схема env: GitHub Secret → `deploy.yml` export (2 блока) → `docker-compose.prod.yml` (`x-php-env` anchor + scheduler) → контейнер. `APP_ENCRYPTION_CURRENT_KEY_VERSION` на проде сейчас из baked `.env` (=v1), проброса нет.
- Backfill-команда `EncryptConnectionKeysCommand` — образец dry-run/--execute.
- Потребители провайдера: только `SodiumFieldEncryptionService` (через `SecretKeyProviderInterface`) и `SecretRotationService` — малый blast radius.

## Stage 1: карта версий ключей из env + проброс конфигурации
Risk: MEDIUM (security config, локально)
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: записать перед стартом (master HEAD)

Definition of Done:
- Провайдер читает версии из `APP_ENCRYPTION_KEYS_JSON` (JSON-объект version→base64 key), приоритет: файл → env-карта → fallback-env (только текущая версия) — обратная совместимость с текущей схемой (FALLBACK_KEY продолжает работать).
- Невалидный JSON/пустые значения — безопасный fallback на существующее поведение (исключение только при реальном отсутствии ключа нужной версии).
- `APP_ENCRYPTION_KEYS_JSON` и `APP_ENCRYPTION_CURRENT_KEY_VERSION` проброшены: services.yaml params, `.env`/`.env.test` (пустые/тестовые дефолты), `docker-compose.prod.yml` (anchor + scheduler), `deploy.yml` (2 блока).
- Юнит-тесты провайдера: карта из env, приоритет источников, битый JSON, неверная длина ключа, fallback-совместимость.

Work items:
- 1.1 `FileBasedSecretKeyProvider`: конструктор +`?string $keysJsonFromEnv`; `resolveKeyMaterial`: файл → `readKeysFromEnvJson()` → fallback. Парсинг толерантный к пустой строке/битому JSON (пустая карта), валидация ключей — через существующий `decodeAndValidateKey` при выдаче.
- 1.2 Проводка: `services.yaml` (param `app.encryption.keys_json` + аргумент провайдера), `.env` (`APP_ENCRYPTION_KEYS_JSON=` пустым), `.env.test` (тестовая карта с двумя версиями для тестов ротации).
- 1.3 Прод-конфиг: `docker-compose.prod.yml` (anchor + scheduler: `APP_ENCRYPTION_KEYS_JSON`, `APP_ENCRYPTION_CURRENT_KEY_VERSION: ${...:-v1}`), `.github/workflows/deploy.yml` (export в обоих блоках).
- 1.4 Юнит-тесты `tests/Unit/Shared/Security/FileBasedSecretKeyProviderTest.php` (новый или дополнить существующий — проверить наличие).
- 1.5 Проверки: unit (targeted + полный), lint:container, yaml-валидация workflow, `docker compose -f docker-compose.prod.yml config --quiet`.

Reviewer focus: приоритет источников не сломал существующее поведение (file wins, fallback только для current); секреты не логируются в исключениях провайдера.

## Stage 2: команда ротации + integration-тесты + runbook
Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: записать перед стартом

Definition of Done:
- Команда `app:marketplace:rotate-connection-keys`: dry-run показывает распределение строк по версиям и сколько требует ротации; `--execute` перешифровывает строки с `key_version != активной` батчами; идемпотентна; вывод без секретов.
- `ConnectionApiKeyCodec::rotateIfNeeded(MarketplaceConnection): bool` на базе `SecretRotationServiceInterface`.
- Integration-тест: строка на v1 → после ротации v2, plaintext не изменился (decrypt v2 == исходный); legacy plaintext-строки (без encrypted-пары) команда не трогает; dry-run ничего не меняет; в выводе нет ключевого материала.
- Runbook ротации в `docs/maintenance/` (пошагово: генерация ключа, обновление секретов, деплой, dry-run, execute, контроль, удаление старой версии).

Work items:
- 2.1 `ConnectionApiKeyCodec`: `rotateIfNeeded()` (decrypt через EncryptedPayload(stored version) → encrypt активной; обновляет пару в entity).
- 2.2 Команда `RotateConnectionKeysCommand` (по образцу `EncryptConnectionKeysCommand`): dry-run — `GROUP BY api_key_key_version`; execute — батчи 100, flush+clear, пост-контроль.
- 2.3 Integration-тест `tests/Integration/Marketplace/Infrastructure/Security/ConnectionApiKeyRotationTest.php`: два ключа в тестовой карте (`.env.test`), переключение активной версии через прямую сборку провайдера/кодека в тесте (не через container-параметры), проверка round-trip.
- 2.4 Runbook `docs/maintenance/encryption-key-rotation.md`.
- 2.5 Проверки: targeted integration → integration Marketplace → unit полный → lint:container.

Reviewer focus: строки без encrypted-пары не затрагиваются; ротация не меняет plaintext-колонку; идемпотентность; отсутствие утечек в выводе.

## Общие правила
- 1 задача = 1 ветка `task/encryption-key-rotation` = 1 Draft PR (новая задача, НЕ вливать в закрытый PR #2291).
- Каждый Stage: проверки → внутренний review → внешний Claude review до REVIEW_GREEN → Stage Report → commit/push.
- Задача — только tooling: сама ротация на проде (обновление секретов, деплой, запуск) — отдельный owner gate в handoff, выполняется владельцем по runbook'у в выбранное время.
- Не трогаем: contract-задачу (прекращение plaintext-записи, drop колонки), Ingestion SecretCodec, содержимое секретов.
