# moysklad-store-account-suggest — handoff

**Branch:** `feat/moysklad-store-account-suggest` · base `master` (`16a9ec7ac`)

## Summary of stages
- **Stage 0** — эталон: 36 активных складов Сухоносова (b57d7682), разметка, правила закрывают 25 (69 %).
- **Stage 1** — правила `StoreAccountRulePolicy`, блок «Предложение» на `/moy-sklad/.../stores`, «Принять» и
  «Принять все уверенные»; `SaveStoreBalanceLinkAction(onlyIfUnassigned)`.
- **Stage 2** — ИИ-задача `moysklad_store_account_suggest` (ai-service + контракт `ai-task/v1` v1.4.0), таблица
  `moysklad_store_account_suggestions`, постановка после снимка остатков, потребитель ответа, бейджи «ИИ» / «проверьте».
- Внутреннее ревью (свежая сессия) и внешнее (Codex) — находки исправлены, см. `stages/stage-2.md`.

## Files changed
- `site/src/MoySklad/` — Domain (правила), Application (предложения, Accept/Request Action, consumer), Query-хранилище,
  контроллеры, `SyncStockSnapshotHandler` (триггер), SchemaExtension.
- `site/templates/moy_sklad/stores/index.html.twig` — блок предложения, кнопки.
- `site/migrations/Version20261127150000.php`.
- `services/ai-service/` — `tasks/moysklad_store_account_suggest`, маршрут, stub, evals (25 кейсов), тесты.
- `services/_contracts/ai-task/v1/` — схемы, примеры, CHANGELOG; копии примеров в `site/tests/Fixtures`.
- `ARCHITECTURE.md` (MoySklad: склады → счета Баланса, Changelog 2.260), `tests/Support/ServiceGateway/FakeAiTasks.php`.

## Migrations
`Version20261127150000` — новая таблица `moysklad_store_account_suggestions` (FK на склад `CASCADE`, unique
`(company_id, ai_task_id)`). Аддитивная, обратимая (`down` — DROP TABLE), данных не меняет — бэкап не обязателен.
Выпуск — отдельным release (процесс миграций): «run the migration and deploy #N».

## Public API / contract changes
- Новый POST `moysklad_store_accept_suggestions` (READ+WRITE+CSRF, право проводить в Балансе).
- `ai-task/v1` v1.4.0 — аддитивно: `store_account_suggest_payload` / `_output`.
- Messenger routing не менялся; `SyncStockSnapshotHandler` после снимка ставит ИИ-задачи (best-effort).

## Checks
- ai-service: ruff, format, mypy — OK; pytest (not evals) 194 OK; evals 65 OK (живые модели не вызывались).
- Symfony: MoySklad (unit/integration/functional) 624 OK; Architecture, ServiceGateway, MarketplaceOzon, схема
  и routing — OK; PHPStan по затронутым каталогам — OK; CS, twigcs, lint:twig — OK.
- Полный `phpunit`: 9998 tests, 56166 assertions — OK (19 мин).

## Reviews
- Внутреннее (свежая сессия): 2 IMPORTANT (fbo → Ozon для WB-складов; FAILED навсегда) + MINOR — исправлены.
- Внешнее (Codex, 1 раунд): 5 IMPORTANT — 4 исправлены, 1 отклонена с причиной (маскировщик платформы).

## Risks, limitations, follow-ups
- **После деплоя:** живой прогон evals (`evals-compare` в контейнере ai-service на app-сервере) — порог ≥ 90 % верных
  среди уверенных; предложения появятся после ближайшей синхронизации остатков и только у компаний с доступом к ИИ.
- Разметку эталона (FBS-склады, «СВОИ», «Возвраты») подтверждает Владелец.
- FOLLOW-UP: N+1 в «Принять все»; компании с несколькими подключениями МойСклад (счёт занят первым → `failed` у второго);
  правило совпало, но счёта нет — ИИ не спрашивается; тест триггера в handler; маскирование имён без отчества.

## Owner decision
«run the migration and deploy #N»
