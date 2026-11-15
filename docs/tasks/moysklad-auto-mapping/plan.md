# Автомаппинг листингов МойСклад — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans
> либо superpowers:subagent-driven-development при выбранном делегировании.
> Репозиторные Stage/Work item и полномочия AGENTS.md имеют приоритет.

**Goal:** массово сопоставлять однозначные листинги Ozon/Wildberries с
product/variant МойСклад через предпросмотр в существующем legacy UI.

**Architecture:** источники только через MarketplaceFacade; локальные
кандидаты и pure policy в MoySklad. Отдельный Redis pool хранит короткий
immutable preview с привязкой к сессии;
существующий ChangeListingMappingAction остаётся единственным writer ревизий.
Каждая строка сохраняется своей транзакцией с повторной проверкой решения.

**Tech Stack:** Symfony/Doctrine/PostgreSQL, серверная Session/Redis,
Twig/Tabler, PHPUnit. Новых зависимостей, Messenger/cron и React нет.

**Spec:** [TASK.md](TASK.md). Base планирования: `b8679062a`;
ручной маппинг выпущен merge-коммитом `c0f68e7b9` (#3016).

## Global Constraints

- Владелец разрешил автономную реализацию четырёх Stage. Выпуск отдельно на handoff.
- Реализация Large, HIGH-LOCAL: четыре Stage, backend отдельно от frontend.
- Компания и actor определяются сервером. Межмодульные данные — только Facade.
- Ozon/Wildberries отдельно, в выбранном подключении. Internal Catalog,
  Finance/Inventory/FIFO/отчёт комиссионера не затрагиваются.
- Все предлагаемые правила — TASK.md §«Предлагаемые правила сопоставления».
- Ручной маппинг, CAS, старые requestId и история остаются совместимы.
- No schema migration в v1: audit в существующем JSON snapshot, preview/attempt results в выделенном cache pool на существующем Redis.
  Индексы/постоянные настройки/баркоды — отдельное расширение после замеров,
  не добавлять скрыто. Повторный полный прогон gates только при обосновании.
- Разработка каждого Work item начинается с теста RED; Stage закрывается
  module checks, review, report, commit/push, затем следующий Stage автоматически.
- Для реализации заново создать checkpoint.md и записать stage_base_commit.

## Review Focus

1. Дубликат article, появившийся после preview: запись должна быть отклонена;
   archive/unknown size не должны создавать ложную однозначность. Тесты 1.1/3.1.
2. Ручной clear/replace после preview или после принятого запроса: новая связь
   не перезаписывается, replay ничего не восстанавливает. Тесты 3.1/4.1.
3. Один nmId с разными размерами, разные цвета одного размера, неоднозначный
   id характеристики: выбрать неправильную модификацию нельзя. Тесты 1.1/4.1.
4. Company switch, чужой preview, подмена selectedIds/rule/target: ни чтения
   чужих данных, ни записи. Тесты 2.1/3.1/4.1.
5. Технический сбой после части commit и закрытый EntityManager: уже принятые
   строки сохраняются, остаток не обрабатывается, retry без дублей. Тесты 3.1.

## 1. Что переиспользовать

| Существующий файл | Назначение |
|---|---|
| site/src/Marketplace/Facade/MarketplaceFacade.php | paginateListingsForExternalMapping/getListingsMetaByIds, чтение источников |
| site/src/Marketplace/DTO/ListingMetaDTO.php | sku/supplierSku/size/marketplaceVariantId/isActive |
| site/src/MoySklad/Infrastructure/Query/MoySkladCatalogTargetsQuery.php | readiness, scoped target lookup/row locks |
| site/src/MoySklad/Infrastructure/Query/MoySkladListingMappingsQuery.php | bounded current/history, revision=0 vs clear>0 |
| site/src/MoySklad/Application/Action/ChangeListingMappingAction.php | единственная запись SET/CLEAR, CAS/requestId/snapshots |
| site/src/MoySklad/Application/Action/SyncCatalogAction.php | существующий session advisory lock moysklad-catalog + connectionId |
| site/templates/moy_sklad/mappings/index.html.twig | вход в preview в обеих legacy-вкладках |
| site/tests/Support/MoySklad/MappingFixtures.php | builders-first fixtures |
| site/tests/Fixtures/MoySklad/mapping-worker.php | реальные PostgreSQL workers, расширить режимами |

Не копировать финансовые Preview/ApplyDefaultCostMappingAction: это другой
контекст. Использовать только их композиционный паттерн preview/result.
MarketplaceFacade::getListingBarcodes не подходит для автомаппинга: отдаёт
весь словарь и схлопывает повторный barcode ключом массива; в v1 не вызывается.

## 2. Контракты и файлы будущей реализации

Создать в `site/src/MoySklad/`:

- `Domain/AutomaticListingMappingPolicy.php`: pure решение по TASK, без IO.
- `Application/DTO/AutomaticMappingCandidate.php`: существующий CatalogTargetDTO,
  parentArticle и leaf; parent с вариантами не leaf независимо от archive reason.
- `Application/DTO/AutomaticMappingCandidates.php`: items, totalCount, truncated.
- `Application/DTO/AutomaticMappingDecision.php`: status, reason, target|null,
  matchedRules, comparedSize|null, selectedCharacteristicId|null.
- `Application/DTO/AutomaticMappingPreviewPage.php`: source page, решения и
  счётчики по данной странице, q/page/sizeCharacteristicId.
- `Application/DTO/AutomaticMappingPreviewItem.php`: listingId, nullable
  targetType/externalId, sourceFingerprint, requestId, decision. RequestId
  генерируется сервером один раз; неподходящая строка не становится READY
  только из-за наличия requestId.
- `Application/DTO/AutomaticMappingPreview.php`: id, company/actor/connection,
  marketplace, createdAt/expiresAt, ruleVersion, контекст страницы и items.
  items — list<AutomaticMappingPreviewItem>. Серверная сериализация только
  скаляров/массивов, без PHP serialize DTO и Entity.
- `Application/DTO/AutomaticMappingGuard.php`: previewId, ruleVersion,
  sourceFingerprint и nullable sizeCharacteristicId.
- `Application/DTO/AutomaticMappingApplyItem.php`: listingId, ApplyStatus,
  reason|null, revisionId|null.
- `Application/DTO/AutomaticMappingApplyResult.php`: list<AutomaticMappingApplyItem>,
  accepted/conflicts/failed/notProcessed counts; список выбранных ID сохраняется
  в результате попытки, не берётся из последующего GET клиента.
- `Application/DTO/MappingCharacteristicPage.php`: items[{id,name}], page,
  perPage=20, totalItems. ID — непрозрачная строка до 255 символов, не UUID по догадке.
- `Enum/AutomaticMappingDecisionStatus.php`: READY, NO_ARTICLE, NO_MATCH,
  AMBIGUOUS, TOO_MANY_CANDIDATES, VARIANT_REQUIRED, SIZE_RULE_REQUIRED,
  SIZE_UNKNOWN, SIZE_MISMATCH, SIZE_UNVERIFIABLE, TARGET_UNAVAILABLE,
  EXISTING_MAPPING, CLEARED_MAPPING, LISTING_UNAVAILABLE, CATALOG_NOT_READY.
- `Enum/AutomaticMappingApplyStatus.php`: ACCEPTED, CONFLICT, FAILED, NOT_PROCESSED.
- `Infrastructure/Query/AutomaticMappingCandidatesQuery.php`:
  `forArticles(string companyId,string connectionId,list<string> articles): array<string,AutomaticMappingCandidates>`;
  ключ `article:` + normalized(article), чтобы числовые артикулы не теряли тип/нули.
- `Infrastructure/Query/MappingCharacteristicsQuery.php`:
  `page(string companyId,string connectionId,string q='',int page=1): MappingCharacteristicPage`
  и `find(string companyId,string connectionId,string characteristicId): ?array{id:string,name:string}`.
- `Infrastructure/Query/AutomaticMappingPreviewQuery.php`:
  `page(string companyId,string connectionId,string marketplace,string q,int page,?string sizeCharacteristicId): AutomaticMappingPreviewPage`.
- `Infrastructure/Cache/AutomaticMappingPreviewStorage.php`:
  `put(string sessionId,AutomaticMappingPreview): void`;
  `get(string sessionId,string previewId,string companyId,string actorUserId,string connectionId,string marketplace): ?AutomaticMappingPreview`;
  `putResult(string sessionId,string previewId,string attemptId,AutomaticMappingApplyResult): void`;
  `getResult(string sessionId,string previewId,string attemptId,string companyId,string actorUserId,string connectionId,string marketplace): ?AutomaticMappingApplyResult`.
  Все cache keys включают SHA256 sessionId; raw sessionId не логируется.
  get/getResult сами проверяют полный preview binding и активный индекс.
  put/putResult не перезаписывают существующий id иным payload:409.
  SessionId/hash не возвращаются в HTML и не логируются.
- `Infrastructure/Lock/MoySkladCatalogLock.php`: `tryAcquire(string connectionId): bool`
  и `release(string connectionId): void`; существующие namespace/key и та же
  DBAL-сессия, без нового интерфейса. Caller сначала проверяет company ownership.
- `Application/Command/ApplyAutomaticMappingsCommand.php`: company/actor/
  connection/marketplace, серверный preview, list выбранных listingId, максимум25.
- `Application/Action/ApplyAutomaticListingMappingsAction.php`:
  `__invoke(ApplyAutomaticMappingsCommand): AutomaticMappingApplyResult`.
- Три single-action Controller: `PreviewAutomaticMappingsController.php`,
  `ShowAutomaticMappingsPreviewController.php`, `ApplyAutomaticMappingsController.php`.
- `Form/AutomaticMappingsPreviewType.php` и `Form/ApplyAutomaticMappingsType.php`.

Изменить:
- `site/config/packages/cache.yaml`: отдельный pool
  `cache.moysklad_automatic_mapping`, adapter cache.adapter.redis с существующим
  default_redis_provider. TTL900 и лимиты — константы Storage, не новые env vars.
  Существующий Symfony LockFactory/Redis mutex сериализует изменение индексов;
  не менять общий cache.app и session handler.
- `site/config/services_test.yaml`: только необходимые public test roots для
  новых ещё не используемых UI Query/Storage/Action; production visibility не менять.
- MarketplaceFacade: новый `lockListingMetaForExternalMapping(string companyId,string listingId): ?ListingMetaDTO`.
  `FOR SHARE` источника только внутри транзакции вызывающего Action; метод
  сам не flush/commit. После lock читать свежий DBAL DTO, а не Entity из
  identity map. UUID/company validation и scoped lookup обязательны.
- ChangeListingMappingCommand: последний optional `?AutomaticMappingGuard $automaticGuard=null`;
  старые вызовы и ручные формы не меняются. Guard проверяет previewId UUID,
  ruleVersion=`article_size_v1`, sourceFingerprint=64 hex и размер id<=255.
- ChangeListingMappingAction: guarded branch и audit/replay-проверка, без второго writer.
- SyncCatalogAction: извлечь только существующий lock/finally в общий Lock;
  HTTP/страницы/курсоры/финансовые правила не менять.
- MoySkladListingMappingsController: bounded выбор характеристики на index,
  char_q<=100/char_page>=1, без влияния на q/page листингов. Текущий выбранный
  ID, подтверждённый find, добавлять к ChoiceType вне текущей страницы выбора.
- `ARCHITECTURE.md`: актуальные контракты, lock order и ограничения.

Pure policy: `decide(ListingMetaDTO,AutomaticMappingCandidates,?string sizeCharacteristicId): AutomaticMappingDecision`;
`sourceFingerprint(ListingMetaDTO): string` = SHA256 JSON фиксированных полей
id/marketplace/sku/supplierSku(normalized)/size(normalized|null)/variantId/isActive.
Название не ключ: его изменение не должно само по себе запрещать связь.
Policy содержит единую константу ASCII whitespace (0x09–0x0D и0x20); Query
передаёт её параметром в btrim, Policy использует тот же набор в PHP.

## 3. Получение кандидатов и диагностика

- Preview source page25 через существующий Facade, current batch <=25.
  Отсеять revision>0, inactive и пустые артикулы до запроса кандидатов.
- Один set-based запрос по <=100 уникальным непустым артикулам, всегда
  company+connection. Матч по собственному article и parent.article вариантов.
- Учитывать архивные цели и родителей; не использовать SELECTABLE как
  предварительный фильтр однозначности. DISTINCT по типу+externalId в группе.
- Максимум50 candidates на артикул + один sentinel. totalCount считается SQL;
  при >50 — TOO_MANY_CANDIDATES, никаких выводов по обрезанной выборке.
  Фильтрация по размерам производится только после проверки truncated=false.
- Parent product помечается non-leaf; отсутствие leaf при найденном родителе
  даёт VARIANT_REQUIRED. Несколько неопределимых размеров блокируют READY,
  даже если среди проверяемых строк нашёлся один подходящий вариант.
- `MappingCharacteristicsQuery` читает distinct id/name локального JSONB,
  по20, поиск q<=100; одинаковые имена с разными id не объединяются.
  Характеристика не выбирается программой по имени. Удалённый id — отказ.
- Статистика: totalShown/ready/ambiguous/missingArticle/missingSizeRule/
  unavailable/existing/cleared и причины для каждой строки. Не выдавать долю
  страницы за процент покрытия всего каталога; реальную эффективность v1
  оценивает пользователь по своим preview без чтения production агентом.
- Query count не зависит от количества отображаемых строк. На локальных
  fixtures 50k targets проверить EXPLAIN и отсутствие загрузки всех сущностей;
  результаты измерений записать. Индексы без измерений не придумывать.

## 4. Применение без гонок

1. На HTTP-границе: active company, scoped connection, READ+WRITE, CSRF,
   scalar/UUID/limit validation. Только previewId и selectedIds из формы;
   цели/requestId/actor/компания/rule params берутся из серверного preview, не из POST.
2. Preview store проверяет actor/company/connection/provider/TTL; неизвестный,
   чужой или истёкший preview даёт одинаковый безопасный 404 с предложением
   сформировать новый. Максимум3 preview; четвёртый вытесняет самый старый.
   Mutable индекс перечитывать из Redis под mutex, не из уже загруженной PHP
   Session. Preview immutable; результаты отдельных attemptId не перезаписываются.
   Индексы preview и результатов изменяются под одним session-key mutex;
   cache недоступен до apply ->503 и ноль записей.
3. Bulk Action не открывает общую транзакцию и не делает flush. На каждую
   выбранную READY-строку вызывает существующий ChangeListingMappingAction,
   SET, expectedRevision=0, сохранённый requestId и AutomaticMappingGuard.
4. Внутри транзакции writer порядок: connection FOR SHARE → mapping advisory
   → acceptedRequest replay → catalog try-lock (только auto) → источник FOR SHARE
   через Facade → повтор решения → существующий target lock → повтор проверки
   допустимости → append/flush/commit. В catalog lock не ожидать: при занятости
   вернуть конфликт CATALOG_BUSY, без записи и без закрытия EM.
5. Catalog lock — существующий session lock импорта. Он защищает в том числе
   от INSERT нового дубликата article в другом target, чего row lock старой
   цели не обеспечивает. Все writers каталога сверить по коду перед Stage3.
   Локальный test worker-импорт обязан брать тот же протокол блокировки.
6. Lock освобождается во внешнем finally ПОСЛЕ завершения wrapInTransaction,
   а не в finally callback до внутреннего flush/commit. Потеря DB session
   освобождает lock; ошибка unlock не маскирует первоначальное исключение.
7. Повтор sourceFingerprint, принадлежности provider, active, revision0,
   readiness и всего множества кандидатов. Единственная текущая цель должна
   совпасть с показанной; не перенаправлять автоматически на новую.
8. В listing_snapshot добавить autoMapping{previewId,ruleVersion,
   sourceFingerprint,sizeCharacteristicId,origin:'automatic',matchedRules}.
   matchedRules вычисляются writer повторно, не принимаются из POST. Старые manual snapshots без ключа
   читаются как раньше. Повтор requestId проверяет полный payload И guard;
   guard mismatch либо manual/auto смешение даёт409. Accepted replay идёт до
   catalog lock/новой валидации, чтобы последующая синхронизация его не ломала.
9. Ожидаемые доменные конфликты возвращать из callback, бросать снаружи как
   сейчас: EM остаётся открытым, следующая строка может обработаться.
   Если EM закрыт после technical/unique SQL error — остановить остаток.
   Не reset/reuse EM и не продолжать UnitOfWork внутри того же запроса.
10. Результат частичный: ACCEPTED значит запрос принят сейчас или ранее,
    это не число новых ревизий. CONFLICT/FAILED/NOT_PROCESSED видны отдельно.
    На POST создать server attemptId и сохранить результат по отдельному
    cache key с TTL не дальше expiry preview, максимум3 результата на preview.
    После POST303 открыть GET preview?attempt={attemptId} со свежим kernel/EM и перечитать
    текущие связи; результат replay не выдавать за нынешнее состояние связи.
    Никаких ORM-запросов для рендера после закрытого EM в исходном POST.
    Если cache результата недоступен после commit —503 с честным сообщением
    о возможном сохранении и предложением проверить историю/повторить, не
    утверждать rollback. Потеря/expiry preview не удаляет принятые ревизии.

## Stage 1: правила и bounded read model

Risk: HIGH-LOCAL. Base: записать при старте. DoD: policy и preview Query
проверены на fixtures, никаких записей, решений по обрезанным выборкам нет.

Files: новые Policy/Decision/Candidate/Page DTO, DecisionStatus, три Query
из §2; tests/Unit/MoySklad/Domain/AutomaticListingMappingPolicyTest.php,
tests/Integration/MoySklad/AutomaticMappingCandidatesQueryTest.php,
AutomaticMappingPreviewQueryTest.php, MappingCharacteristicsQueryTest.php.

- [x] **1.1 RED:** тесты точного article, регистра/001 vs1, variant own article,
  parent article+size, TAB/CR краёв и неприравнивания NBSP, дубликатов (включая архивный), неархивного ребёнка
  архивного parent, parent с variants_count0 но реальными children,
  двух цветов размераM, неизвестного размера, «0», отсутствующей и
  повторной характеристики id, equal names/different ids, пустых полей,
  двух правил с одним target и двух разных targets, inactive/current/clear.
  Assert READY только при доказанно единственной доступной leaf-цели;
  ambiguous/unresolved cases имеют target=null. Запустить: ожидается RED.
- [x] **1.2 RED:** integration fixtures foreign company/connection, одинаковый
  externalId двух подключений, 51 candidates (нужный размер у 51-го),
  100 articles и101 rejected, numeric article keys, pagination characteristics,
  query count1 vs25. Ожидается RED на отсутствующих Query/контрактах.
- [x] **1.3 GREEN:** минимальные pure policy и set-based Query; no SELECT *,
  no raw чужих таблиц, no per-listing SQL loop. Переиспользовать Facade/state.
- [x] **1.4 VERIFY/REVIEW:** narrow tests → module set, focused PHPStan/CS,
  EXPLAIN на50k fixture targets; self-review scope/tenant/ambiguity. Обновить
  ARCHITECTURE/checkpoint/StageReport. Commit/push Draft PR, продолжить Stage2.

## Stage 2: серверный предпросмотр

Risk: MEDIUM. DoD: TTL/ownership/limits и повторная отправка имеют точный
контракт; клиент не может подменить серверную цель. Записи ревизий нет.

Files: Preview/Item/Guard DTO, Infrastructure/Cache/AutomaticMappingPreviewStorage,
site/config/packages/cache.yaml и site/config/services_test.yaml; tests/Unit/MoySklad/Infrastructure/AutomaticMappingPreviewStorageTest.php
и tests/Integration/MoySklad/AutomaticMappingPreviewStorageConcurrencyTest.php;
worker tests/Fixtures/MoySklad/automatic-preview-worker.php (только local/test).

- [x] **2.1 RED:** MockClock/ArrayAdapter/Lock test store: TTL15m, максимум3, вытеснение oldest,
  roundtrip scalar serialization, stable requestIds на retry, два preview
  независимы, foreign session/actor/company/connection/provider ->null, expiry ->null.
  Два реальных параллельных put: оба успешны либо один явно409; повтор
  отвергнутого put сохраняет оба preview. Индекс не теряет подтверждённые
  записи, активны не более3. Same id/different payload ->409.
  Два attempt не теряют/не смешивают results, limit3, cache outage до apply.
  Невалидные поля Guard отклоняются DTO. Ожидается RED на новом storage.
- [x] **2.2 GREEN:** storage + envelope, UUIDv7 per preview/per row, source hash
  из Policy. Версия `article_size_v1`. Выделенный Redis pool, существующий
  LockFactory: mutex session-key только на короткое перечитывание/изменение
  индексов, без matcher/SQL/HTTP внутри. Не ждать mutex бесконечно: acquire(false),
  при занятости409, при недоступном Redis503. Не класть Entity, токены МС и
  полный marketplaceData в cache; pruning/TTL без фоновых задач.
- [x] **2.3 VERIFY/REVIEW:** unit/module и focused gates; self-review размера
  cache payload/ownership/TTL/конкурентных обновлений, reports и commit/push. Продолжить Stage3.

## Stage 3: guarded writer и частичное массовое применение

Risk: HIGH-LOCAL. DoD: новый auto path сохраняет ревизии только после
повторного решения; manual/replay сохраняют совместимость, импорты не гоняются.

Files: Lock, Apply Command/Action/Result/ApplyStatus; изменение существующих
SyncCatalogAction, ChangeListingMappingCommand/Action и MarketplaceFacade;
tests/Integration/MoySklad/ApplyAutomaticListingMappingsActionTest.php,
AutomaticMappingConcurrencyTest.php; расширение mapping-worker.php,
ChangeListingMappingActionTest.php, SyncCatalogActionTest.php и MarketplaceFacadeTest.php.

- [x] **3.1 RED:** single/batch25, explicit size rule, no replaces/clear restore,
  source changed/archived/foreign, target moved/duplicate appeared, disabled
  connection, provider mismatch, invalid selection26 and duplicateIDs,
  expired/foreign envelope, id не из READY набора, подмена цели из POST,
  requestId+guard mismatch, retry after partial
  commit, replay after manual clear, ordinary manual legacy snapshots.
  Отдельно EM remains open on domain reject; EM closed -> остаток NOT_PROCESSED.
  Cache outage после успешной строки не скрывает commit; независимые attempt
  имеют свои результаты. Потеря preview посреди запроса не откатывает принятые строки.
- [x] **3.2 RED:** независимые PG-процессы: реальный auto Action vs SyncCatalog
  writer c общим catalog lock в обоих порядках; duplicate INSERT другой цели,
  parent/target archive, source article/size change, manual SET/CLEAR и два
  одинаковых POST. Проверить actual lock wait/try-lock refusal и число ревизий.
  Mutation без catalog guard должна давать RED на duplicate race; без source
  lock — RED на source race. Не подменять тест Action тестом одного Query.
- [x] **3.3 GREEN:** извлечь общий существующий catalog lock, Facade source
  lock с документированной caller transaction; optional guard в Command и
  защищённый путь в том же writer. Batch без внешней транзакции и дублирования
  append. Snapshot audit и полная проверка idempotency guard.
- [x] **3.4 VERIFY/REVIEW:** module + старые sync/manual tests, focused gates,
  full Stage diff review (lock order/phantoms/EM/retry). Внешний Claude review,
  если HIGH-LOCAL diff>500 строк; иначе только handoff. Исправить confirmed
  B/I, reports, commit/push и автоматически перейти Stage4.

## Stage 4: legacy UI и handoff

Risk: HIGH-LOCAL. DoD: пользователь видит основание/причины и может применить
READY-строки одной страницы, проверены security и реальные браузерные состояния.

Files: три Controller и два Form из §2;
site/templates/moy_sklad/mappings/index.html.twig;
новый site/templates/moy_sklad/mappings/automatic_preview.html.twig;
tests/Functional/MoySklad/AutomaticListingMappingsControllerTest.php.

Routes под `/moy-sklad/connections/{connectionId}/mappings/{marketplace}`:
POST `/automatic/preview` (READ+CSRF), GET `/automatic/{previewId}` (READ),
POST `/automatic/{previewId}/apply` (READ+WRITE+CSRF). Requirement UUID для
connectionId/previewId; marketplace только ozon|wildberries.

- [x] **4.1 RED:** выбор правил/характеристики, source page25/q<=100,
  обе вкладки и одинаковый nmId с разными размерами, READY checkboxes,
  disabled reasons, foreign/bad IDs/other provider404, invalid CSRF403,
  malformed arrays/limits422, source/CAS conflicts в summary, истёкшая сессия,
  повтор POST и replay после последующего manual clear. Нет WRITE -> нельзя apply.
- [x] **4.2 GREEN:** кнопка «Найти соответствия на этой странице», явный выбор
  характеристики размера по id (bounded list20 с поиском), таблица с причиной,
  правилами и текущей целью, READY по умолчанию выбраны, остальные disabled.
  Селектор/поиск характеристики на index использует char_q/char_page и
  отдельные hidden q/page; неизвестный/чужой id404, свой вне ChoiceType422.
  Apply только selectedIds; POST303 → summary/current states. Частичную ошибку
  не выдавать за полное успешное сохранение. Нет общей кнопки на весь каталог.
  Tabler/существующие формы, flash, pagination; no new CSS/JS/React.
- [x] **4.3 VERIFY:** functional/module, Twig gates. Browser desktop1440/
  mobile375, keyboard/checkbox, длинные названия, empty/error/no permission,
  no-JS формы, конфликт и частичное применение. Нет document overflow.
- [x] **4.4 HANDOFF:** full gates один раз, fresh-session internal whole-diff
  review, Claude external review по task base и fix cycle. ARCHITECTURE,
  reports/checkpoint/handoff; Ready PR и один запрос merge+deploy. В текущем
  scope миграции нет; если scope изменён — release.md и именованное approval.

## 5. Команды и фиксация доказательств

Baseline перед кодом: `make site-test-unit` и узкий набор существующего
MoySklad/MarketplaceFacade на изолированной local/test БД. Указывать числа,
команду и старые failures. Не использовать production credentials.

Повторные узкие запуски в работающем контейнере, например:

```bash
docker compose exec -T site-php-cli php bin/phpunit tests/Unit/MoySklad/Domain/AutomaticListingMappingPolicyTest.php
docker compose exec -T site-php-cli php bin/phpunit tests/Integration/MoySklad/AutomaticMappingConcurrencyTest.php
```

Work item: targeted test + focused PHPStan/CS. Stage: module tests и review.
Module: tests/Unit/MoySklad + tests/Integration/MoySklad + tests/Functional/MoySklad
и MarketplaceFacadeTest.php. Full gates только на handoff:

```bash
make site-stan
make site-cs-check
make site-cs-strict-types
make site-test-unit
make site-test
make site-cs-twig
```

Внешнее review реализации: `site/bin/external-review.sh <task_base> --reviewer claude --effort medium --context <facts.md>`.
Worker fixtures исключаются скриптом — передать явные read-only pointers
на mapping-worker.php и automatic-preview-worker.php.
Не считать историческое review #3016 ревью новой реализации. Не повторять
round после IMPORTANT/MINOR, если исправления подтверждены; после BLOCKER
повторить. Политика бюджета/timeout — docs/workflow/external-review.md.

## 6. Чек-лист приёмки

- [x] Exact article и parent article+explicit size rule работают для обоих MP.
- [x] Case/ведущие нули не изменены; штрихкод/code/name не подменяют ключ.
- [x] Неоднозначные, truncated, unknown характеристики не дают READY.
- [x] Parent с модификациями никогда не назначается; архивные дубли не спрятаны.
- [x] Current mapped/needs_review/clear не перезаписываются; inactive пропускается.
- [x] Company/actor/connection/provider, READ/WRITE и CSRF проверяются сервером.
- [x] Параллельные preview/attempt не теряют и не смешивают результаты; Redis
  outage до записи не пишет ревизии, после commit не выдаётся за rollback.
- [x] Клиент не может выбрать target, изменить rule/guard/requestId или выйти
  за пределы 25 READY listingId своего preview.
- [x] Истёкший/вытесненный preview не применяется; retry действительного не дублирует.
- [x] После preview изменились source/target/кандидаты/связь — конфликт без retarget.
- [x] Синхронизация каталога и изменение источника защищены реальными locks.
- [x] Catalog lock держится до commit; replay не зависит от текущего catalog lock.
- [x] Partial apply/EM close показаны честно; retry не восстанавливает manual clear.
- [x] Snapshot хранит origin/rule/guard; старые manual snapshots совместимы.
- [x] SQL выдача bounded; число запросов preview не растёт с размером страницы.
- [x] Legacy UI/no-JS, mobile/keyboard, loading/empty/error/role states пройдены.
- [x] Нет side effects в Catalog/Finance/Inventory/FIFO или внешних API.
- [x] Gates/reviews/reports выполнены, open B/I=0/0; Ready PR создан.

## 7. Дальнейшие расширения

После замеров покрытия v1 отдельные планы: импорт и сверка баркодов МС,
явные словари размеров/цветов, постоянная конфигурация правил, обработка
всего каталога с cursor/job/status и отдельным batch ledger. Их schema/API/
очереди/финансовые контракты не добавлять по ходу этой реализации.

## Implementation rulings / финальные уточнения
- Dedicated Redis pool использует AutomaticMappingRedisAdapter (наследник штатного RedisAdapter, текущий native phpredis provider), чтобы transport false/error не становились cache miss и не теряли immutable index. Тот же marshaller/write protocol, без зависимостей.
- Импорт имеет дополнительный session import-intent lock moysklad-catalog-sync: duplicate sync skip сохранён, collision auto→existing temporary retry. Auto shared catalog key не изменён.
- Preview READ+CSRF классифицирован exact existing authenticated-self ROUTE_POLICY только для private cache. Apply WRITE проверен valid-CSRF mutation.
- Полный suite/Stan исключения и green narrow rechecks описаны в handoff; baseline не расширялся. Browser states и fresh/final external reviews завершены.
