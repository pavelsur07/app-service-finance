# Stage 1: таблица справочника `accrual/types`

## 1. Какую задачу решаем

Справочник Ozon лежит только сырым файлом в S3. Из него читается `id → name`, а
`description` теряется. Новый тип, смена названия или пропажа типа нигде не видны.
Нужна таблица справочника в Ingestion, которая обновляется при каждой новой версии.

Факты Stage 0: справочник одинаков у всех кабинетов (один `hash` в один день) →
таблица глобальная, без `company_id`; версии меняются каждые 1–4 недели;
неизменная версия новой сырой записи не создаёт (дедупликация по `hash`), поэтому
нормализация — и синхронизация — запускаются только на новой версии.

## 2. Что будет результатом

- **Entity** `App\Ingestion\Entity\OzonAccrualType`, таблица `ingest_ozon_accrual_types`:
  `id` (UUID v7), `type_id` (int, unique), `name` (string 255), `description`
  (?string 500, пусто → null), `first_seen_at`, `removed_at` (?),
  `last_raw_record_id` (uuid версии, из которой строка изменена последний раз),
  `created_at`, `updated_at`. Методы: `applyVersion(name, description, rawRecordId, at)`
  → признак изменения; `markRemoved(at)`; `restore()`.
  Без `companyId`: справочник Ozon — не данные компании.
- **Repository** `OzonAccrualTypeRepository`: `findAllIndexedByTypeId()`
  (`@companyScopeExempt` — справочник общий для всех компаний).
- **Action** `SyncOzonAccrualTypesAction(companyId, rawRecordId)`: читает строки
  версии через `RawStorageFacade` (скоуп компании), разбирает `{id|type_id, name,
  description}`, upsert, `flush()`. Возвращает DTO `OzonAccrualTypesSyncResult`
  (`created`, `updated`, `removed`, `restored`, `unchanged`, `skipped`).
  Защиты: версия без валидных строк ничего не меняет (WARNING); версия старше
  последней применённой (`fetchedAt` меньше) только добавляет новые типы, ничего не
  помечает пропавшим и не перезаписывает названия.
- **Хук**: `NormalizeRawRecordHandler` после успешной нормализации сырой записи
  `ozon / ozon_finance_accrual_types` вызывает Action (по образцу ветки заказов).
  Повтор сообщения безопасен: нормализация DONE возвращается сразу, синхронизация
  идемпотентна.
- **Команда** `app:ingestion:ozon-accrual:sync-types [--company-id] [--dry-run]`:
  первичное заполнение и ручной прогон из последней DONE-версии каждой компании.
- **Миграция** таблицы (обратима), Builder `tests/Builders/Ingestion/OzonAccrualTypeBuilder`.
- **INFO** лог: компания, версия, счётчики, `type_id` новых/изменённых/пропавших.
- `ARCHITECTURE.md` — раздел Ingestion (сущность, Action, команда) + Changelog;
  `docs/ingestion/ozon-mapping.md` — где теперь живёт справочник.

## 3. Что считаем выполненной работой

- Integration-тесты Action: фикстура 126 типов → 126 строк; повтор той же версии →
  0 изменений; смена `description` → `updated`, `name` и `first_seen_at` не меняются;
  тип пропал → `removed_at`, не DELETE; вернулся → `restore`; пустая версия → 0
  изменений; более старая версия не стирает и не переименовывает.
- Тест хука: нормализация сырой записи типов заполняет таблицу; запись другого
  ресурса — нет.
- Тест команды: `--dry-run` ничего не пишет, без флага пишет.
- Unit-тест Entity на инварианты (`type_id > 0`, непустой `name`).
- Гейты: PHPStan level 8 без роста baseline, CS, strict_types; миграция up/down/up.
- Разбор `StoredOzonAccrualTypeNameResolver` и разметка by-day не меняются (Stage 2).

## 4. Чек-лист приёмки

- [x] Entity + Repository + Builder + миграция (up/down/up)
- [x] `SyncOzonAccrualTypesAction` + DTO результата, тесты всех веток
- [x] хук в `NormalizeRawRecordHandler` + тест
- [x] команда `sync-types` + тест `--dry-run`
- [x] INFO-лог без тела ответа Ozon
- [x] `RepositoryCompanyScopeRule` зелёный (исключение обосновано)
- [x] `ARCHITECTURE.md`, `ozon-mapping.md`
- [x] PHPStan, CS, strict_types, полный набор тестов модуля
- [ ] прод (после handoff): `sync-types` заполнил таблицу, 126+ типов

## Итог

**DONE 2026-10-18** (кроме проверки на проде — после handoff).

- Файлы: `Entity/OzonAccrualType`, `Repository/OzonAccrualTypeRepository`,
  `Application/Action/SyncOzonAccrualTypesAction`, DTO `OzonAccrualTypesSyncResult` и
  `OzonAccrualTypesRawVersion`, `Infrastructure/Query/OzonAccrualTypesRawVersionQuery`,
  `Command/OzonAccrualSyncTypesCommand`, `Exception/NotOzonAccrualTypesRawRecordException`,
  хук в `MessageHandler/NormalizeRawRecordHandler`, миграция `Version20261018140000`.
- Отступление от плана: отдельного `restore()` и счётчика `restored` нет —
  восстановление пропавшего типа делает `applyVersion()`, считается в `updated`.
- Тесты: 28 (unit Entity 8, Action 12, команда 3, хук 2, load-types +1 проверка);
  Ingestion + Architecture — 923 OK; PHPStan level 8 — No errors; CS и strict_types —
  0 из 3108; миграция down/up OK, в diff схемы новой таблицы нет.
- Внутреннее ревью (свежий агент): BLOCKER 0 / IMPORTANT 3 / MINOR 6. Исправлено:
  гонка прогонов — `wrapInTransaction()` + `pg_advisory_xact_lock`; частично
  разобранная версия больше не помечает типы пропавшими (+тест); тесты хука (другой
  ресурс не трогает таблицу, повтор DONE безвреден); сбой синхронизации логируется
  отдельно и вне catch нормализации; stale-версия создаёт неизвестный тип сразу
  пропавшим (+тест); пояснение dry-run в выводе команды; доменное исключение вместо
  `\InvalidArgumentException`; `final readonly class` у Query; ограничения (V1→V2→V1)
  записаны в `ozon-mapping.md`. Не сделано: тест настоящей конкуренции двух
  соединений — блокировка проверена ревью, на проде один воркер pipeline.
- Прод после handoff: `app:ingestion:ozon-accrual:sync-types` (запись — через
  Владельца), ожидание 126+ строк.
