### Stage 1: карта версий ключей из env (APP_ENCRYPTION_KEYS_JSON) — DONE

**Risk:** MEDIUM
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously (Stage 2)

#### Stage scope
- Stage base commit: `bf6e3694`
- Work items completed: 1.1, 1.2, 1.3, 1.4, 1.5

#### What was done
- `FileBasedSecretKeyProvider`: поддержка `APP_ENCRYPTION_KEYS_JSON` (карта version→base64 key из env); приоритет: файл → env-карта → fallback (только текущая версия); битый/пустой JSON → пустая карта без исключений
- Проводка: `services.yaml` (inline `%env(default::APP_ENCRYPTION_KEYS_JSON)%`), `.env` (пусто + комментарий про одинарные кавычки и запрет `'` в значении), `.env.test` (тестовая карта v1/v2)
- Прод-конфиг: `docker-compose.prod.yml` (anchor + scheduler: KEYS_JSON, CURRENT_KEY_VERSION c дефолтом v1), `deploy.yml` (export в обоих блоках, KEYS_JSON в одинарных кавычках)
- Тесты: `FileBasedSecretKeyProviderTest` расширен 3→12 (оригинальные 3 сохранены — при первой записи были случайно перезаписаны, восстановлены по git)

#### Files changed
- `site/src/Shared/Security/Service/FileBasedSecretKeyProvider.php` — modified
- `site/config/services.yaml` — modified
- `site/.env`, `site/.env.test` — modified
- `docker-compose.prod.yml` — modified
- `.github/workflows/deploy.yml` — modified
- `site/tests/Unit/Shared/Security/Service/FileBasedSecretKeyProviderTest.php` — modified
- `docs/tasks/encryption-key-rotation/plan.md` — new

#### Definition of Done
- [x] Провайдер читает версии из env-карты; приоритет файл→env→fallback; обратная совместимость
- [x] Битый JSON — безопасный fallback без падения приложения
- [x] Проброс в services/.env/.env.test/docker-compose.prod.yml/deploy.yml
- [x] Юнит-тесты: карта, приоритеты, битый JSON, длина ключа, fallback

#### Baseline
- `make site-test-unit` — OK (1722 tests)

#### Checks
- targeted: `FileBasedSecretKeyProviderTest` — OK (12 tests, 29 assertions)
- full stage: `make site-test-unit` — OK (1731 = 1722 + 9); `lint:container` — OK
- `docker compose -f docker-compose.prod.yml config` с реалистичным JSON — значение доезжает нетронутым
- `deploy.yml` — yaml валиден; dotenv single-quote разбор проверен эмпирически

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: восстановлены 3 оригинальных теста файла (случайная перезапись Write'ом); выявлено требование одинарных кавычек dotenv (двойные съедаются)
- FOLLOW-UP: none

#### External Claude Code review
- Iterations: 1
- Result: REVIEW_GREEN
- Confirmed findings fixed: MINOR (style) — inline env вместо промежуточного параметра; MINOR (doc) — замечание про запрет `'` в значении секрета добавлено в `.env`
- Rejected findings with reason: none
- FOLLOW-UP accepted: эмпирическая проверка compose config с JSON — выполнена на месте

#### Review fixes applied
- services.yaml: inline binding; .env: предупреждение о символе `'` в значении

#### Risks / reviewer focus
- Секрет с JSON в одинарных кавычках: deploy.yml export и .env документируют ограничение
- Обратная совместимость: при пустом APP_ENCRYPTION_KEYS_JSON поведение идентично прежнему

#### Checkpoint
- `docs/tasks/encryption-key-rotation/checkpoint.md` updated
- exact next action: Stage 2 — команда ротации + integration-тесты + runbook

#### Open questions
- none

#### Expected owner response
- not required; continuing autonomously
