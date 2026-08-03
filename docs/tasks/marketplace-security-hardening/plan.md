# План: Marketplace security hardening — H3 → H4 → M5/M10 → H1 → (распил + Money)

Источник: бриф владельца в чате по результатам аудита модуля `site/src/Marketplace`.
Порядок задан владельцем: H3 → H4 → M5/M10 → H1 → распил контроллера и Money.

Большая задача (security + финансовый модуль) → staged workflow по AGENTS.md.
План задачи также сохранить в `docs/tasks/marketplace-security-hardening/plan.md` + вести `checkpoint.md` (Work item Stage 1.1).

Проверенная фактура (основание):
- Паттерн CSRF в модуле есть: `isCsrfTokenValid('toggle'.$id, ...)` + скрытый `_token` (напр. `MarketplaceController.php:672`, форма в `templates/marketplace/index.html.twig:118-123`).
- `MarketplaceRawDocument` имеет `getCompany()` (ManyToOne, `Entity/MarketplaceRawDocument.php:36,110`).
- Pagerfanta уже используется: `MarketplaceSalesController`, `MarketplaceReturnsController`, `MarketplaceController.php:630`.
- Инфраструктура шифрования существует и НЕ используется: `src/Shared/Security/` (`SodiumFieldEncryptionService`, `FileBasedSecretKeyProvider`, env `APP_ENCRYPTION_KEY_FILE` / `APP_ENCRYPTION_CURRENT_KEY_VERSION`, `services.yaml:385-391`). В Ingestion — эталонный паттерн версионированного кодека `SecretCodec`/`PlaintextSecretCodec` (version 0 = plaintext) + `EncryptedJsonType`.
- Читатели `apiKey` открытым текстом: `OzonAdapter`, `WildberriesAdapter`, `OzonRealizationFetcher`, `RefreshWbListingCatalogAction`, `SyncWbFinancialReportDayHandler`, `CreatePerformanceConnectionController`, `MarketplaceController`, DBAL `MarketplaceCredentialsQuery` (читает колонку напрямую из SQL!).

---

## Stage 1: H3 — мутации только через POST + CSRF
Risk: HIGH-LOCAL (security, локально)
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: записать перед стартом (master HEAD)

Definition of Done:
- Ни один state-changing роут модуля не доступен по GET (405).
- Все POST-мутации проверяют CSRF-токен; запрос без/с невалидным токеном отклоняется.
- Поведение для пользователя (flash-сообщения, редиректы) не изменилось.
- Функциональные тесты: GET → 405, POST без токена → отказ, POST с токеном → успех.

Work items:
- 1.1 Создать `docs/tasks/marketplace-security-hardening/plan.md` + `checkpoint.md` (копия этого плана).
- 1.2 `MarketplaceSaleMappingController`: `toggle` (`:218`) → `methods: ['POST']` + `isCsrfTokenValid('toggle'.$id)`; `create` (`:88`) и `edit` (`:162`) → добавить CSRF-проверку. Шаблон `templates/marketplace/pl_mappings.html.twig:164` — ссылку toggle заменить inline POST-формой с `_token`; в модальные формы create/edit добавить скрытый `_token`.
- 1.3 `MarketplaceController`: `testConnection` (`:199`), `syncConnection` (`:251`), `syncConnectionPeriod` (`:297`) → `methods: ['POST']` + CSRF. Шаблон `templates/marketplace/index.html.twig`: ссылки «Проверить» (`:84`) и «Синхронизировать» (`:87`) → inline POST-формы по паттерну `:118`; модальная форма `#form-sync-period` (`:420`) — `method="get"` → `method="post"` + `_token`, читать `date_from/date_to` из `$request->request` вместо `$request->query`.
- 1.4 Функциональные тесты: новый `tests/Functional/Marketplace/Controller/MarketplaceConnectionSecurityTest.php` и кейсы для pl_mappings toggle (405 по GET, отказ без токена, успех с токеном).
- 1.5 Проверки: targeted functional тесты → полный functional Marketplace → unit.

Reviewer focus: не сломаны ли остальные ссылки/формы на тех же страницах; `MarketplaceType::from()` на сыром вводе НЕ трогаем (LOW, отдельно).

### Stage 1 — уточнение scope по ходу review (зафиксировано)
Внешний review расширил исходный список роутов аудита (toggle/create/edit pl-mappings, test/sync/sync-period) на ВСЕ HTML-формовые POST-мутации модуля в тех же контроллерах и соседних потоках. В Stage 1 покрыто: `processRealization`, `createConnection`, `editConnection`, `syncRealization`, `reprocess`, `performance/create` (Ozon Performance, общая форма), month-close `close-stage`/`reopen-stage`/`reconcile`/`preflight`, `preliminary/rebuild`, cost-pl-mapping `bulk-save`, admin `mapping-errors/{id}/resolve`, inventory `set-cost`/`sync-barcodes`/`sync-barcode`/`import-cost-price`, `recalculate-cost-price`.

FOLLOW-UP (зафиксировано, вне Stage 1):
- `CostsDebugController` (debug-эндпоинты xlsx-parse/reconcile-debug) — судьба debug-контроллеров решается отдельной задачей (L2 аудита).
- JSON API-контроллеры (`Controller/Api/*`: теги, listings map, reconciliation upload и др.) — session-authenticated JSON API; добавление CSRF требует прокидывания токена в fetch-вызовы фронта, отдельное дизайн-решение и задача.

---

## Stage 2: H4 — tenant-сверка внутри Actions обработки raw-документов
Risk: HIGH-LOCAL (финансовая обработка, только guard, семантика не меняется)
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: записать перед стартом

Definition of Done:
- `ProcessOzonRealizationAction` и `ProcessMarketplaceRawDocumentAction` отвергают raw-документ чужой компании исключением, даже если вызваны напрямую (минуя контроллер).
- Регрессионные тесты: чужой документ → исключение; свой документ → обрабатывается как раньше (существующие тесты зелёные).

Work items:
- 2.1 `ProcessOzonRealizationAction.php:65-85`: после загрузки `$rawDoc` — сверка `(string)$rawDoc->getCompany()->getId() === $companyId`, иначе `RuntimeException` (сообщение без чужих данных).
- 2.2 `ProcessMarketplaceRawDocumentAction.php:50`: сверка `$document->getCompany()->getId()` с `$command->companyId`.
- 2.3 Регрессионные тесты (integration): чужой rawDoc → исключение в обоих Actions.
- 2.4 Проверки: integration Marketplace + unit.

Reviewer focus: исключение не утекает во flash с деталями; Messenger retry-поведение не изменилось (Unrecoverable при tenant-mismatch — проверить маппинг исключений в handlers, при необходимости `UnrecoverableMessageHandlingException`).

---

## Stage 3: M5 + M10 — пагинация productsIndex и транзакция тегов
Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: записать перед стартом

Definition of Done:
- `/marketplace/products` отдаёт страницу через Pagerfanta (как sales/returns), шаблон рендерит pager.
- Создание тега + вставка назначений атомарны: сбой между ними не оставляет тег-сироту.
- Существующие `ListingTagsApiTest` зелёные; новый тест на пагинацию.

Work items:
- 3.1 `MarketplaceController::productsIndex` (`:642-659`) → Pagerfanta по паттерну `MarketplaceSalesController`; обновить `templates/marketplace/products.html.twig` (pager-nav).
- 3.2 `AssignListingTagAction::__invoke` (`:29-38`) → обернуть findOrCreate-flush + `assignments->assign()` в `$this->em->wrapInTransaction()`; комментарий «ponytail» про гонку актуализировать. Важно: `ListingTagAssignmentRepository` — DBAL на том же connection, транзакция EntityManager его покрывает.
- 3.3 Тесты: пагинация products; повторное присвоение/idempotency остаётся зелёным.
- 3.4 Проверки: targeted + unit + functional Marketplace.

Reviewer focus: pager по умолчанию (page=1) не ломает существующие ссылки; вложенные транзакции отсутствуют.

---

## Stage 4: H1 — шифрование apiKey MarketplaceConnection (expand/contract)
Risk: HIGH-LOCAL + Production Gate на backfill/провижининг ключей
owner_gate: **yes** (перед деплоем switch-этапа и production backfill)
release_candidate: yes
independently_deployable: no (этапы 4.1-4.2 совместимы с продом, 4.3 — после backfill)
stage_base_commit: записать перед стартом

Подход — версионированный кодек по эталону Ingestion `SecretCodec` (не изобретать новый): reads принимают plaintext (v0) и ciphertext (v1); writes шифруют. Переиспользовать `Shared/Security` (`SodiumFieldEncryptionService`, `FileBasedSecretKeyProvider`).

Definition of Done (этапы внутри Stage 4 — по принципу expand/contract, каждый этап отдельно деплоюmable):
- 4.1 Expand: миграция добавляет `api_key_encrypted` (text, nullable) + `api_key_key_version` (smallint, nullable); `MarketplaceConnection` получает codec-aware accessor (v1 ciphertext → decrypt; иначе plaintext legacy-колонка); новые/обновлённые подключения пишут ОБА поля. Plaintext-readers переведены на accessor, включая DBAL `MarketplaceCredentialsQuery` (decrypt после fetch).
- 4.2 Backfill: консольная команда `app:marketplace:encrypt-connection-keys` (dry-run по умолчанию, `--execute` по owner approval) шифрует существующие строки. Production run — только с явным approval, в тихое окно.
- 4.3 Switch: запись plaintext прекращается; чтение plaintext остаётся fallback до contract.
- 4.4 Contract (ОТДЕЛЬНАЯ будущая задача): дроп legacy-колонки — не входит в этот план.
- Env-провижининг `APP_ENCRYPTION_KEY_FILE`/`APP_ENCRYPTION_CURRENT_KEY_VERSION` на проде — обязательное условие перед деплоем 4.1; генерация и размещение ключа — действия владельца/DevOps (owner gate).
- Тесты: round-trip encrypt/decrypt, mixed rows (v0+v1), все readers, команда backfill (dry-run/execute) на тестовой БД.

Work items:
- 4.1.1 Codec-сервис для Marketplace (по паттерну Ingestion SecretCodec) + миграция expand.
- 4.1.2 Entity + все readers/writers на codec (список из фактуры выше).
- 4.2.1 Команда backfill + тесты.
- 4.3.1 Отключение plaintext-записи + тесты.
- 4.5 Stage Report + STOP на owner gate: провижининг ключа на проде, деплой, backfill-run.

Reviewer focus: секреты не логируются (в т.ч. в исключениях и команде); `MarketplaceCredentialsQuery` не возвращает plaintext наружу; Ozon Performance `client_secret` (те же поля) покрыт; rollback-совместимость 4.1 (старая версия кода читает plaintext, новая пишет оба → безопасно).

---

## Stage 5 (ТОЛЬКО ОБЗОРНО, реализация — следующим планом): распил MarketplaceController + перевод денег на Money
- Распил `MarketplaceController` (885 строк, 12 роутов) на single-action контроллеры + вынос flush в Actions; замена чужих Repository на Facade (M1/M2).
- Money: float/bc-арифметика → `App\Shared\Domain\ValueObject\Money` в `MappingError`, `ReconciliationLog`, `MarketplaceOzonRealization` (M6) — отдельными финансово-ревьюируемыми задачами с тестами эквивалентности расчётов.
- Детальный Phase 0 и DoD — перед стартом Stage 5, отдельным планом.

---

## Общие правила исполнения
- 1 задача = 1 ветка `task/marketplace-security-hardening` = 1 Draft PR; Stage 1-3 — коммиты в него последовательно (owner_gate: no → продолжаем автономно).
- Stage 4 в той же ветке, но перед 4.2/4.3-production-действиями — STOP на owner gate.
- Каждый Stage: полные Stage-проверки → внутренний review → внешний Claude review до REVIEW_GREEN → Stage Report (`docs/tasks/marketplace-security-hardening/stages/stage-N.md`) → commit/push → обновление Draft PR.
- Базлайн перед Stage 1: `make site-test-unit` + functional Marketplace (зафиксировать).
- Что НЕ трогаем: financial formulas/знаки/периоды; живой модуль MarketplaceAnalytics; debug-контроллеры; legacy-команды; `MarketplaceType::from()` (LOW); inline-стили и прочие LOW-находки вне затрагиваемых файлов.
