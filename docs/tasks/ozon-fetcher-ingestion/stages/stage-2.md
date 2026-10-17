### Stage 2: выдача кабинетов из Symfony — DONE

**Risk:** HIGH-LOCAL (endpoint отдаёт секреты, запрос без компании)
**Stage base commit:** `d7bfccfd6`
**Branch:** `feat/ozon-fetcher-internal-api`
**Внешнее ревью:** да — в конце этапа

#### Цель
Сервис получает активные Ozon-кабинеты и их ключи одним запросом по контракту
`marketplace-connections` v1.

#### Результат
- `GET /internal/marketplace-connections?provider=ozon` (за firewall
  `internal_api`) → `{"schema_version":"1","generated_at","connections":[…]}`
- `ActiveConnectionCredentialsQuery` (DBAL, `@companyScopeExempt`):
  активные подключения провайдера обоих типов, ключ расшифрован; сбой
  расшифровки — ключ `null` + один агрегированный error-лог на запрос
- `MarketplaceConnectionsSnapshotBuilder` (чистая логика): группировка по
  компании, `connection_ref`, `resource_types`, `updated_at`
- DTO контракта в `Marketplace/Application/DTO/Internal/`

#### Решения
- **`connection_ref`** = id seller-подключения; если seller нет — id
  performance-подключения. Новое подключение = новый ref (данные другого
  кабинета не смешаются в журнале сервиса); правка ключей того же
  подключения ref не меняет.
- **Провайдер v1 — только `ozon`.** Схема `seller` требует `client_id`, у WB
  его нет; `provider` ≠ `ozon` → 422 `unsupported_provider`. Без параметра —
  `ozon`.
- Кабинет без пригодных ключей (нет `client_id` у seller, сбой расшифровки)
  в ответ не попадает: ему нечего грузить.
- `auth_status = FAILED` не фильтруется: у сервиса своя пауза кабинета по 401
  (контракт отдаёт «активные», а не «рабочие» подключения).
- `resource_types`: seller → 4 ресурса первой волны; performance →
  `ozon.ads.statistics`.
- `updated_at` — максимум из двух подключений; время БД (МСК без зоны) →
  ISO 8601 с offset.
- Ключи не логируются; ответ `Cache-Control: no-store`, profiler уже
  выключен для `/internal/*`.

#### Work items
- **2.1** — DTO контракта + builder + unit-тесты всех веток.
- **2.2** — Query с расшифровкой + агрегированный лог.
- **2.3** — контроллер, exempt в `ModuleAccessMap`, functional-тест (сверка
  ключей ответа с `schema.json`, фильтры, расшифровка, 422, без токена → 401).
- **2.4** — `ARCHITECTURE.md`, README контракта (реализация, правило ref).
- **2.5** — гейты по изменённым файлам, внутреннее и внешнее ревью, коммит.

#### Definition of Done / чек-лист готовности
- [x] ответ совпадает по ключам со `schema.json` v1 (верхний уровень, connection, credentials)
- [x] только `marketplace = ozon`, `is_active = true`; неактивные и WB не попадают
- [x] seller + performance одной компании — один элемент; ref = id seller
- [x] только performance — элемент с `seller: null`, ref = id performance
- [x] seller без `client_id` → `seller: null`; без пригодных ключей — элемент не выдаётся
- [x] зашифрованный ключ расшифровывается; legacy plaintext — fallback
- [x] сбой расшифровки одного подключения не роняет ответ; один error-лог со счётчиком
- [x] `provider=wildberries` → 422 `unsupported_provider`
- [x] без токена → 401 (firewall этапа 1)
- [x] ключи не в логах; `Cache-Control: no-store`
- [x] unit-тесты builder, functional-тест endpoint'а
- [x] PHPStan/CS по изменённым файлам; baseline не растёт
- [x] `ARCHITECTURE.md`, README контракта
- [x] внутреннее ревью: BLOCKER/IMPORTANT нет; внешнее ревью — green

#### What was done
- 2.1 — DTO `ActiveConnectionCredentials`, `ConnectionConfig`,
  `MarketplaceConnectionsSnapshot` (`toArray()` по схеме v1);
  `MarketplaceConnectionsSnapshotBuilder` + 5 unit-тестов.
- 2.2 — `ActiveConnectionCredentialsQuery` (`@companyScopeExempt`):
  расшифровка через `ConnectionApiKeyCodec`, сбой → ключ `null` + один
  error-лог со счётчиком и id подключений.
- 2.3 — `MarketplaceConnectionsController` (`GET /internal/marketplace-connections`),
  `App\Marketplace\Controller\Internal\` в `EXEMPT_PREFIXES`; 4 functional-теста
  (контракт, фильтры, legacy plaintext, битый шифротекст, 422, 401 без утечки ключа).
- 2.4 — `ARCHITECTURE.md` (2.18), README контракта «Реализация в Symfony».

#### Checks
- targeted unit + functional — OK; инварианты маршрутов и модульного гейта — OK
- PHPStan по изменённым файлам — OK; php-cs-fixer — OK (1 файл выровнен)

#### Internal review
- iterations: 1; MINOR fixed: пустой legacy plaintext не считается сбоем расшифровки

#### External review
- round 1 (Codex, medium): `REVIEW_GREEN`

#### Next
- handoff PR-группы 2 (этапы 1–2): полные гейты, PR; затем Stage 3
