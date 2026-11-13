# Этап 2. Канал фактов и модуль `SourceFacts`

План утверждён Владельцем 13.11.2026.


## 1. Какую задачу решаем

ozon-fetcher каждую ночь скачивает начисления Ozon по дням и кладёт их в S3 неизменяемыми
версиями, но приложение их не получает: отчёты и ОПиУ считает легаси, которое ходит в Ozon само.
Этап прокладывает канал «сервис → приложение» и создаёт первый модуль нового контура —
`SourceFacts`, который хранит данные источника как есть. Всё остальное (переводчик `MarketplaceOzon`,
канон, экономика SKU) строится поверх него на этапах 3–6.

Что уже есть и переиспользуется:
- `fetch_task` в сервисе уже ведёт ревизию дня (`revision`, `content_hash`, `content_pages`):
  новая ревизия = Ozon изменил день (`services/shared/fetchkit/store/tasks.go`, `FinishTask`).
- Таблица `outbox_batch` создана в ядре fetchkit, кодом не используется.
- Чтение сырья из S3 в сервисе: `rawstore.Reader.Get`, `store.Tasks.ContentPages`.
- Образец выдачи по HTTP: `services/shared/fetchkit/httpapi/snapshot.go` (+ тесты на фейках).
- Образец pull с подтверждением в приложении: `site/src/ServiceGateway/Application/Action/ImportAiResultsAction.php`
  (HTTP вне транзакции, ack после commit, изоляция сбоя одного элемента).
- Образец массовой вставки: `MarketplaceBarcodeCatalogRepository` (чанки по 500, `ON CONFLICT`).

## Ключевое решение: факт — запись источника целиком

Черновик контракта `source-fact-envelope` v1 описывает факт как уже разложенную сумму (комиссия,
услуга доставки) без SKU и номера отправления. Чтобы так отдавать, в Go пришлось бы повторить
весь разбор начислений из PHP (`OzonAccrualByDayPreviewMapper`) и держать два разбора в паритете.

**Делаем иначе:** факт = одно начисление Ozon как пришло (`payload` — исходный JSON),
ключ `ozon:accrual-by-day:{accrual_id}`. Разбор на компоненты — в переводчике `MarketplaceOzon`
(этап 4).

- Сервис остаётся простым: режет страницу на записи и достаёт ключ, ничего не интерпретирует.
- SKU, отправление и любые новые поля Ozon доезжают автоматически.
- Ошибку разбора чиним в приложении и пересобираем из фактов, без повторной загрузки из Ozon.
- `SourceFacts` действительно ничего не знает о маркетплейсах: payload для него непрозрачен.

Контракт в статусе «проект» — правка v1 допустима; после этапа он публикуется и замораживается.

## 2. Что будет результатом

### PR A — контракт и сервис (без изменений в поведении приложения)

**Контракт** `services/_contracts/source-fact-envelope/v1/` (README, schema, CHANGELOG, примеры):
- Пачка = одна ревизия одного периода одного кабинета:
  `batch_id`, `connection_ref`, `company_id`, `provider`, `resource_type`, `period_key`,
  `revision`, `content_hash`, `page_count`.
- `GET /internal/source-facts?resource_type=&after=&limit=` — список неподтверждённых пачек
  (только метаданные, курсор обязан расти).
- `GET /internal/source-facts/{batch_id}/pages/{n}` — записи страницы:
  `source_key`, `payload_hash` (sha256 записи), `payload`; `record_count`.
- `POST /internal/source-facts/ack {batch_ids}` — после него пачка больше не выдаётся.
- Ошибки и авторизация — как у `service-snapshot` v1 (Bearer `SERVICE_API_TOKEN`).
- Статус «действует»; `services/AGENTS.md` §1, §3 и `_contracts/README.md` — синхронизировать
  формулировки (факт = запись источника; категорию и разбор назначает приложение).

**fetchkit** (`services/shared/fetchkit`):
- `store`: `Options.PublishResources`; в транзакции `FinishTask` при росте `revision` для
  публикуемого ресурса — строка `outbox_batch` (`batch_id = conn:resource:period:rN`, payload —
  только указатель: ключ задачи, ревизия, sha256 страниц; денег в БД сервиса нет, §9 соблюдён).
  Методы `ListNewBatches`, `BatchPages`, `AckBatches`. Схему ядра не меняем.
- `httpapi/facts.go` + `FactsDeps` по образцу `snapshot.go`: три обработчика, белый список
  ресурсов, разбор страницы через интерфейс `RecordSplitter`, который даёт сервис.

**ozon-fetcher:**
- `internal/facts/accrual_by_day.go` — `RecordSplitter`: элементы `accruals[]`, ключ из
  `accrual_id`, без него — `fallback-{hash16}` (как в PHP-маппере), `payload_hash`.
- Подключение в `internal/httpapi/httpapi.go` и `cmd/ozon-fetcher/main.go`;
  `FactResources = [ozon.finance.accrual-by-day]`.
- Миграция `00008`: постановка в `outbox_batch` уже загруженных дней (`INSERT … SELECT` из
  `fetch_task`, `ON CONFLICT DO NOTHING`) — история с 08.09 без ручных команд на проде.
- README, `AGENTS.md`, `docs/maintenance/ozon-fetcher-prod.md`.

Тесты (Go): table-driven для splitter + fuzz ключа; обработчики на фейках (порядок, курсор,
401/404/422, повторная выдача до ack, ack идемпотентен); интеграционный на testcontainers —
`FinishTask` с новой ревизией создаёт ровно одну пачку, с тем же хешем — ни одной.

### PR B — модуль `SourceFacts` (с миграцией — отдельный релиз «миграция и деплой»)

**ServiceGateway** (клиент к сервису живёт здесь, по `docs/architecture/service-gateway.md` §3):
- `Infrastructure/Api/SourceFactsClient` — три вызова, проверка ответа по схеме v1, таймауты,
  `X-Trace-Id`, исключения `ContractViolationException` / `ServiceUnavailableException`.
- `Facade/SourceFactsGatewayFacadeInterface`: список пачек, страница, ack. Здесь же сверка
  `connection_ref → companyId` по спискам `MarketplaceFacade::getActive*Connections()`
  (в легаси ничего не добавляем); `company_id` из ответа сервиса на веру не берётся.
- Тест контракта на фикстурах из `_contracts/.../examples`.

**Модуль `site/src/SourceFacts`** (по `docs/workflow/new-module.md`; таблицы `sf_*`):

| Таблица | Назначение | Ключ |
|---|---|---|
| `sf_batch` | журнал принятых пачек | unique `(service, batch_id)` |
| `sf_period` | текущая ревизия периода, `change_seq` — отметка «окно изменилось» для этапа 4 | unique `(company_id, connection_ref, resource_type, period_key)` |
| `sf_fact` | версии записей: `source_key`, `payload_hash`, `payload` jsonb, `first_revision`, `last_seen_revision` | unique `(company_id, connection_ref, resource_type, period_key, source_key, payload_hash)` |
| `sf_quarantine` | пачка, которую нельзя принять, и причина | unique `(service, batch_id)` |

Как применяется ревизия N периода (две названные фазы, каждая идемпотентна):
1. **Загрузка страниц.** По странице: HTTP → короткая транзакция → `INSERT … ON CONFLICT DO UPDATE
   SET last_seen_revision = GREATEST(…)`, чанки по 500, дубли внутри чанка отсекаются в памяти.
2. **Активация.** Одна транзакция под `pg_advisory_xact_lock` периода: `sf_period.current_revision = N`
   (только если N больше текущей), новый `change_seq`, строка `sf_batch`. После commit — ack.

Действующие записи периода — те, у которых `first_revision ≤ N ≤ last_seen_revision`. До активации
новые версии не видны, после — исчезнувшие и изменённые старые версии перестают быть действующими
сами; ничего не удаляется и не перезаписывается. Оборванный прогон безопасно повторяется.

Правила приёма:
- ревизия не новее текущей → пачка подтверждается без применения (`superseded`);
- неизвестная `schema_version`, неизвестный кабинет, чужая компания, расхождение `record_count`
  или `payload_hash` → строка в `sf_quarantine`, пачка **не** подтверждается и придёт снова;
- сбой одной пачки не останавливает остальные; в конце прогона один агрегированный `error`.

Состав модуля: `Entity` (4 + Builders), `Application/Action/PullSourceFactsAction`,
`Infrastructure/Query` (запись фактов через DBAL, проверки гейтов), `Command`:
- `app:source-facts:pull` — cron каждые 15 минут, `LockableTrait`;
- `app:source-facts:health-check` — cron 09:10 МСК: у каждого включённого кабинета Ozon есть
  активированный вчерашний день; карантин пуст; нет пачек старше 6 часов без применения.

Включение: `SOURCE_FACTS_ENABLED` и `SOURCE_FACTS_COMPANIES` (пусто — никто) в `.env`,
`deploy.yml` и окружении scheduler.

**Границы и документы в том же PR:**
- `site/tests/Architecture/ModuleBoundaryRules.php`: `App\SourceFacts` не зависит от
  `App\Marketplace`, `App\Ingestion`, `App\MarketplaceAds`, `App\MarketplaceAnalytics`,
  `App\Finance`; из внешних модулей — только фасад `ServiceGateway`.
- `ARCHITECTURE.md`: устав модуля (причина изменений, таблицы, чего в модуле быть не должно),
  кто коммитит, порядок блокировок, cron-таблица, Changelog; `docs/workflow/health-gates.md`;
  `docker/cron/app.cron`; `config/packages/doctrine.yaml`.

Тесты (PHP): Action — happy-path и негативные (карантин не подтверждается; сервис недоступен);
повторный прогон → то же число строк; ревизия 2 меняет действующий набор (изменённая, исчезнувшая,
новая запись); устаревшая ревизия не откатывает период; конкурентная активация; гейт — красный и зелёный.

### Включение на проде (после обоих PR)

1. Вумджой (эталонный кабинет) в `SOURCE_FACTS_COMPANIES` → история с 08.09 приходит сама.
2. Приёмка на данных (ниже). 3. Остальные кабинеты Ozon.

### Отклонения от плана при выполнении PR A (13.11.2026)

- В пачке нет `company_id` и `provider`: сервис компанию не знает надёжно, приложение всё равно
  определяет её само по `connection_ref`; провайдер следует из `resource_type`.
- Страница пачки — `GET /internal/source-facts/page?batch_id=…&page=N` (в `batch_id` есть двоеточия,
  в пути его пришлось бы кодировать).
- `payload_hash` приложение не пересчитывает: Go при выдаче перекодирует JSON, байты не совпадут.
  Хеш — идентификатор версии записи; целостность страницы приложение проверяет по `record_count`.
  Причина карантина «расхождение `payload_hash`» из правил приёма убрана.
- В `outbox_batch.payload` — указатель на страницы ревизии, обнуления после ack нет
  (`services/AGENTS.md` §9 приведён к этому).

## 3. Что считаем выполненной работой

На проде по всем включённым кабинетам Ozon начисления `accrual-by-day` ежедневно приходят в
`SourceFacts` ревизиями; история с 08.09.2026 принята; гейт свежести и карантина в cron зелёный.

## Что не делаем

Разбор начислений, категории, канон, отчёты, WB; фасад чтения фактов для переводчика (этап 4,
когда появится потребитель); очистка старых версий `payload` (отдельно, когда будет объём);
переключение чего-либо в легаси.

## 4. Чек-лист приёмки

- [ ] Контракт v1 «действует», примеры проходят схему, типы Go и PHP проверены по схеме.
- [ ] Go-гейты: `make ozon-fetcher-lint|vet|test`, fetchkit, wb-fetcher (общий код) — зелёные.
- [ ] PHP-гейты: `site-stan` (baseline не растёт), `site-cs-check`, `site-test-unit`,
      integration-тесты модуля, `SchemaInSyncWithMigrationsTest`, PHPat-правила.
- [ ] Прод, Вумджой, 08.09 – вчера: на каждый день есть активированный период; число действующих
      фактов дня = числу начислений в легаси-сырье того же дня (`marketplace_raw_documents`,
      read-only SQL), множества `accrual_id` совпадают; расхождения объяснены.
- [ ] Повторный `app:source-facts:pull` не меняет счётчики строк.
- [ ] Ночная перезагрузка хвоста даёт новую ревизию только у реально изменившихся дней.
- [ ] `sf_quarantine` пуст; гейт зелёный 3 дня подряд на всех включённых кабинетах.
- [ ] Итог записан в `stages/stage-2.md`, отметка в `TASK.md`.

## Риски и зависимости

- **Биллинг GitHub:** CI и деплой стоят, пока не исправлен (PR #2982 тоже ждёт). Код и локальные
  гейты делаются уже сейчас; выкат — после исправления.
- **Форма ответа by-day** в Go не типизирована, фикстура минимальна — в начале PR A снять
  обезличенный образец страницы из S3-сырья сервиса для фикстуры (суммы и идентификаторы заменить).
- **Объём `payload`** в БД приложения: по Вумджой ~100 начислений в день; для крупных кабинетов
  замерить после включения, порог для задачи на очистку — записать в итог этапа.
- **Отключённый кабинет**: пачки от него уходят в карантин как «неизвестный кабинет» — видно в гейте,
  решение по каждому случаю.

## Critical files

- `services/_contracts/source-fact-envelope/v1/{README.md,schema.json,CHANGELOG.md}`
- `services/shared/fetchkit/store/tasks.go`, `services/shared/fetchkit/httpapi/{httpapi.go,snapshot.go}`
- `services/ozon-fetcher/internal/httpapi/httpapi.go`, `cmd/ozon-fetcher/main.go`,
  `internal/ozonstore/migrations/`
- `site/src/ServiceGateway/Application/Action/ImportAiResultsAction.php` (образец)
- `site/src/SourceFacts/**` (новый), `site/tests/Architecture/ModuleBoundaryRules.php`
- `ARCHITECTURE.md`, `docker/cron/app.cron`, `docs/workflow/health-gates.md`, `.github/workflows/deploy.yml`
