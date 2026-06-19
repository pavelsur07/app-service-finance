# TASK — БЛОК 7: P&L · Проекция канона в витрины + идемпотентная перегенерация

## 0. Сводка

- **Бизнес-цель.** Связать новый канон Ingestion (`FinancialTransaction`) с существующими витринами P&L (`PLDailyTotal`, `PLMonthlySnapshot`). Сделать перегенерацию P&L идемпотентной и автоматически реагирующей на досланные/изменённые данные источников (mark dirty → recompute). Уважать закрытые периоды (`MarketplaceMonthClose`, `financeLockBefore`).
- **Модуль.** `App\Finance` (существующий модуль витрин). Новый код только в `Finance`, Ingestion не трогается, кроме подписки на её событие.
- **Тип.** integration (между модулями через события + Facade).
- **Ветка.** `feature/finance-07-pnl-projection-rebuild`.
- **Подзадачи.** B1 PLDirtyPeriod Entity · B2 Repository · B3 NormalizationCompletedSubscriber · B4 Action mark dirty · B5 rebuildPeriod (ядро) · B6 защита закрытых периодов · B7 воркер пачкой · B8 Facade · B9 Тесты.
- **Затрагивает другие модули.** `App\Ingestion` (подписка на событие `NormalizationCompletedEvent`, чтение через `IngestionFacade::getTransactions`).
- **Требует миграции БД.** Да (1 таблица).
- **Меняет публичный API.** Нет (HTTP — блок 8).

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- В Finance существуют `PLCategory`, `PLDailyTotal`, `PLMonthlySnapshot`, `Document`, `DocumentOperation`. P&L наполняется legacy-кодом из `Marketplace` и `Cash`.
- В Ingestion готов канон `FinancialTransaction` (блок 5), есть событие `NormalizationCompletedEvent` с `AffectedPeriod[]`, есть `IngestionFacade::getTransactions(companyId, from, to, shopRef)`.
- Существует `MarketplaceMonthClose` (закрытый месяц по магазину/маркетплейсу) и `Company.financeLockBefore` (дата, до которой все периоды компании заблокированы).
- В legacy используется команда `app:marketplace:month-preliminary-rebuild`, но она привязана к конкретному источнику, а не к канону.

### 1.2 Желаемое состояние

- Новая Entity `PLDirtyPeriod`: компания + период (год-месяц) + shop + статус (pending/rebuilding/done) + причина (ingest/manual/remap/month_change).
- Подписчик `NormalizationCompletedSubscriber` ловит `NormalizationCompletedEvent`, для каждого `AffectedPeriod` определяет (старый, новый) месяц по `occurredAt` и помечает оба `PLDirtyPeriod` со статусом pending.
- Идемпотентный `PnlFacade::rebuildPeriod(companyId, period, shopRef, source)` читает канон через `IngestionFacade::getTransactions`, **полностью** заменяет P&L-записи периода в одной транзакции БД.
- Воркер `RebuildDirtyPnlPeriodsCommand` (cron + Messenger): берёт pending → зовёт `rebuildPeriod` для каждого → переводит в done.
- Защита от конкурентных перегенераций одного периода — Redis Lock с ключом `pnl_rebuild:{companyId}:{period}:{shopRef}`.
- Закрытые периоды: при попытке `rebuildPeriod` для закрытого периода — НЕ перезаписывать, а создавать `PnlClosedPeriodAlert` (или поднимать флаг в `PLDirtyPeriod` через статус `blocked_by_close`).
- Версионирование: каждая `PLDailyTotal`/`PLMonthlySnapshot` получает поле `rebuiltAt` (timestamp) — для аудита, когда пересчитано последний раз.

### 1.3 In scope

- Entity `PLDirtyPeriod` (новая, в `Finance`).
- Enum `PLDirtyPeriodStatus`, `PLDirtyPeriodReason`.
- Repository `PLDirtyPeriodRepository`.
- `NormalizationCompletedSubscriber` в Finance (подписан на событие Ingestion).
- Action `MarkPnlPeriodDirtyAction`, `RebuildPnlPeriodAction`, `MaybeBlockByClosePeriodAction`.
- Service `PnlPeriodResolver` (вычисление месяца из `occurredAt`).
- `PnlFacade` расширяется методами `markPeriodDirty`, `rebuildPeriod`, `getDirtyPeriods`.
- Доменное событие `PnlClosedPeriodTouchedEvent` (для будущих уведомлений в блоке 9).
- Command `app:finance:rebuild-dirty-pnl-periods` (cron-инициируемый воркер).
- Расширение существующих `PLDailyTotal`/`PLMonthlySnapshot` полем `rebuiltAt` (через миграцию).
- Маппинг `TransactionType` (Ingestion) → `PLCategory.flow/code` (Finance) — справочная таблица + резолвер.

### 1.4 Out of scope

- UI для просмотра/запуска перегенерации — блок 8.
- Admin для управления закрытыми периодами — блок 9.
- Email/Telegram-уведомления о доехавших данных в закрытый период — блок 9.
- Существующий legacy-пайплайн P&L (`Marketplace`-источник) НЕ заменяется. Он продолжает писать в те же `PLDailyTotal` — пока Ingestion не покроет тот же набор данных (после блока 6 — только Ozon, остальные — позже).
- Coexistence Ingestion vs legacy в P&L: в shadow-режиме блока 9; в блоке 7 — только инфраструктура rebuild, реальное переключение источника наполнения — в блоке 9.

### 1.5 Допущения и открытые вопросы

- Допущение: «период» в P&L = месяц (year + month). Это уже в `PLMonthlySnapshot`. `PLDailyTotal` хранится по дням, но dirty-флаг — на уровне месяца (перегенерация одного месяца перезаписывает все дневные записи этого месяца).
- Допущение: один dirty-period покрывает один shop (для случая «реклама пришла на конкретный магазин — пересчитываем только его»). Для случая «доехала операция без shop» — `shopRef = ''`, перегенерируется P&L всей компании за период.
- Открытый вопрос: как маппить `TransactionType` Ingestion в существующие `PLCategory` (которые у каждой компании свои)? Решение: сервис `PnlCategoryResolver`, который для пары `(companyId, TransactionType, direction)` отдаёт `PLCategory.id`. Дефолтный маппинг — через `MarketplaceCostPLMapping` (если он применим), fallback — в системную категорию «Прочее». Детализация — отдельной разведкой при старте блока, но контракт сервиса фиксируем сейчас.

---

## 2. Доменная модель

### 2.1 Сущности (Entity)

#### `App\Ingestion\Entity\PLDirtyPeriod`

Файл: `src/Ingestion/Entity/PLDirtyPeriod.php`.
Таблица: `#[ORM\Table(name: 'pnl_dirty_periods')]`.

Использует scalar `companyId` (как новый стиль). Так как Finance — legacy-модуль на ManyToOne `Company`, для НОВОЙ Entity используем scalar (правило CLAUDE.md для нового кода). Это не нарушает существующих сущностей.

| Поле | Тип | Колонка | Nullable | Default | Инвариант |
|---|---|---|---|---|---|
| `id` | string UUID v7 | `id` GUID | нет | — | PK |
| `companyId` | string UUID | `company_id` GUID | нет | — | `Assert::uuid`, неизменяем |
| `periodYear` | int | `period_year` SMALLINT | нет | — | 2020..2100 |
| `periodMonth` | int | `period_month` SMALLINT | нет | — | 1..12 |
| `shopRef` | string | `shop_ref` VARCHAR(255) | нет | `''` | `''` = всех магазинов; неизменяем |
| `status` | PLDirtyPeriodStatus | `status` VARCHAR(32) | нет | PENDING | enumType |
| `reason` | PLDirtyPeriodReason | `reason` VARCHAR(32) | нет | — | enumType |
| `markedAt` | DateTimeImmutable | `marked_at` TIMESTAMP(6) | нет | — | |
| `rebuiltAt` | ?DateTimeImmutable | `rebuilt_at` TIMESTAMP(6) | да | null | время фактической перегенерации |
| `attempts` | int | `attempts` INTEGER | нет | 0 | сколько раз входили в REBUILDING |
| `lastError` | ?string | `last_error` TEXT | да | null | для статуса BLOCKED_BY_CLOSE или FAILED |
| `createdAt` | DateTimeImmutable | `created_at` TIMESTAMP(6) | нет | — | |
| `updatedAt` | DateTimeImmutable | `updated_at` TIMESTAMP(6) | нет | — | |

Конструктор: `__construct(string $companyId, int $periodYear, int $periodMonth, string $shopRef, PLDirtyPeriodReason $reason)`.

Инварианты: `Assert::range($periodYear, 2020, 2100)`, `Assert::range($periodMonth, 1, 12)`.

Поведенческие методы:
- `markRebuilding(): void` — переход PENDING → REBUILDING, `attempts++`.
- `markDone(?DateTimeImmutable $at = null): void` — переход REBUILDING → DONE, `rebuiltAt = $at ?? now`.
- `markFailed(string $reason): void` — переход REBUILDING → FAILED, `lastError = $reason`.
- `markBlockedByClose(string $reason): void` — переход PENDING/REBUILDING → BLOCKED_BY_CLOSE.
- `reopen(): void` — переход DONE/FAILED → PENDING (для повторной пометки, если ещё досылка).

#### Изменения в существующих сущностях

`PLDailyTotal` — добавить поле:
- `rebuiltAt: ?DateTimeImmutable` (nullable), миграция с default null.
- Сеттер: `setRebuiltAt(DateTimeImmutable $at): void`.

`PLMonthlySnapshot` — добавить поле `rebuiltAt` аналогично.

Это **поля для аудита**, не для бизнес-логики; legacy-код продолжает работать (не заполняет их).

### 2.2 Связи

Внутри модуля: `PLDirtyPeriod` — самостоятельная Entity, без связей с `PLDailyTotal` (один dirty-period покрывает все daily-records месяца).

Между модулями: `PLDirtyPeriod.companyId` — ссылка строкой на Ingestion/Company. `shopRef` — открытая строка (совпадает с тем, что в каноне Ingestion и Marketplace).

### 2.3 Enum

#### `App\Ingestion\Enum\PLDirtyPeriodStatus`

Backed string.

| Case | value | Когда | Метка | Терминальный |
|---|---|---|---|---|
| `PENDING` | `pending` | Создан/переоткрыт | «Ожидает перегенерации» | нет |
| `REBUILDING` | `rebuilding` | `markRebuilding()` | «Перегенерируется» | нет |
| `DONE` | `done` | `markDone()` | «Готово» | да* |
| `FAILED` | `failed` | `markFailed()` | «Ошибка» | да* |
| `BLOCKED_BY_CLOSE` | `blocked_by_close` | Период закрыт | «Заблокировано закрытием» | да* |

*Терминальный условно — `reopen()` возвращает в PENDING при необходимости.

Методы: `label()`, `isTerminal()`, `canTransitionTo()`.

Матрица переходов:

| из/в | PENDING | REBUILDING | DONE | FAILED | BLOCKED_BY_CLOSE |
|---|---|---|---|---|---|
| PENDING | ❌ | ✅ | ❌ | ❌ | ✅ |
| REBUILDING | ❌ | ❌ | ✅ | ✅ | ✅ |
| DONE | ✅ (reopen) | ❌ | ❌ | ❌ | ❌ |
| FAILED | ✅ (reopen) | ❌ | ❌ | ❌ | ❌ |
| BLOCKED_BY_CLOSE | ✅ (reopen после открытия периода) | ❌ | ❌ | ❌ | ❌ |

#### `App\Ingestion\Enum\PLDirtyPeriodReason`

| Case | value | Когда устанавливается | Метка |
|---|---|---|---|
| `INGEST` | `ingest` | Подписчик на NormalizationCompletedEvent | «Новые данные источника» |
| `MANUAL` | `manual` | Пользователь нажал «пересчитать» (в блоке 8) | «Ручной запуск» |
| `REMAP` | `remap` | Изменился маппинг категорий | «Изменение маппинга» |
| `MONTH_CHANGE` | `month_change` | Операция перенесена в другой период | «Смена периода операции» |

---

## 3. Слой доступа к данным

### 3.1 Repository

#### `App\Ingestion\Repository\PLDirtyPeriodRepository`

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findOne(string $companyId, int $year, int $month, string $shopRef = ''): ?PLDirtyPeriod` | По уникальному ключу | да | `?PLDirtyPeriod` |
| `findPending(int $limit = 50): list<PLDirtyPeriod>` | Для воркера: PENDING со всех компаний, ORDER BY markedAt | нет* | `list<PLDirtyPeriod>` |
| `findPendingForCompany(string $companyId): list<PLDirtyPeriod>` | PENDING конкретной компании | да | `list<PLDirtyPeriod>` |
| `countByStatus(string $companyId, PLDirtyPeriodStatus $status): int` | Для admin/UI | да | `int` |

*`findPending` — системный запрос воркера (по всем тенантам). В блоке 1 был зафиксирован паттерн: системные методы НЕ принимают companyId. Применяется здесь.

#### `App\Finance\Repository\PLDailyTotalRepository` (правка)

Добавить метод:
- `deleteByCompanyShopAndMonth(string $companyId, string $shopRef, int $year, int $month): int` — DELETE через DBAL для атомарной очистки месяца перед перезаписью. Возвращает кол-во удалённых строк.

Если `shopRef = ''` — удалить все записи месяца компании независимо от shop'а.

Аналогично `PLMonthlySnapshotRepository::deleteByCompanyShopAndMonth`.

### 3.2 Query

Не вводим в блоке 7. Будет в блоке 8.

### 3.3 Индексы

`pnl_dirty_periods`:
- UNIQUE `(company_id, period_year, period_month, shop_ref)` → `uniq_pdp_key`.
- INDEX `(status, marked_at)` → `idx_pdp_status_marked` (для воркера).
- INDEX `(company_id, status)` → `idx_pdp_company_status`.

`pl_daily_totals` и `pl_monthly_snapshots`:
- Добавить колонку `rebuilt_at` (TIMESTAMP nullable, без индекса).

---

## 4. Слой приложения

### 4.1 Action

#### `App\Finance\Application\Action\MarkPnlPeriodDirtyAction`

Вход: `MarkPnlPeriodDirtyCommand` (`string $companyId, int $year, int $month, string $shopRef, PLDirtyPeriodReason $reason`).

Шаги:
1. `findOne($companyId, $year, $month, $shopRef)`.
2. Если null — создать `PLDirtyPeriod(status=PENDING)`, persist.
3. Если найден в DONE/FAILED — вызвать `reopen()`.
4. Если найден в PENDING/REBUILDING/BLOCKED_BY_CLOSE — не трогать (уже помечен).
5. flush.

Идемпотентно: 5 вызовов подряд → одна строка.

#### `App\Finance\Application\Action\RebuildPnlPeriodAction`

Вход: `RebuildPnlPeriodCommand` (`string $companyId, int $year, int $month, string $shopRef = ''`).

Шаги (в одной транзакции БД, под Redis Lock):
1. Acquire Lock `pnl_rebuild:{companyId}:{year}-{month}:{shopRef}` с TTL 10 мин.
2. Проверить, закрыт ли период через `MaybeBlockByClosePeriodAction`:
   - Прочитать `Company.financeLockBefore` — если `>= last day of (year, month)` → блокирован.
   - Прочитать `MarketplaceMonthClose` для каждого `marketplace` (или для shop'а, если shopRef задан) — если закрыт → блокирован.
   - Если блокирован: найти/создать `PLDirtyPeriod`, вызвать `markBlockedByClose($reason)`, опубликовать `PnlClosedPeriodTouchedEvent`, flush, return.
3. Перевести `PLDirtyPeriod` в REBUILDING (если он есть).
4. Определить границы периода: `from = first day of month`, `to = last day of month`.
5. `deleteByCompanyShopAndMonth` для `PLDailyTotal` и `PLMonthlySnapshot`.
6. Итерировать канон: `IngestionFacade::getTransactions($companyId, $from, $to, $shopRef === '' ? null : $shopRef)`.
7. Для каждой `FinancialTransaction`:
   - Резолвить `PLCategory` через `PnlCategoryResolver::resolve($companyId, $type, $direction)`.
   - Агрегировать сумму по (день, плКатегория, projectDirection=null) в `PLDailyTotal`.
   - Параллельно агрегировать по (месяц, плКатегория) в `PLMonthlySnapshot`.
8. persist всех агрегатов + flush.
9. `PLDirtyPeriod->markDone()`.
10. flush.
11. Release Lock.

При исключении в шагах 4-10: rollback транзакции, `markFailed($reason)`, release lock, rethrow.

`PnlCategoryResolver` — сервис в `App\Finance\Application\Service\PnlCategoryResolver`:
- `resolve(string $companyId, TransactionType $type, TransactionDirection $direction): string` — возвращает `PLCategory.id`. При отсутствии маппинга — фолбэк в системную категорию «Прочее» (создание если нет). Алгоритм маппинга — отдельная разведка legacy `MarketplaceCostPLMapping` при старте блока, но контракт фиксирован.

#### `App\Finance\Application\Action\MaybeBlockByClosePeriodAction` (internal)

Вход: `(string $companyId, int $year, int $month, string $shopRef)`.

Шаги:
1. Загрузить `Company` (через legacy Facade или Repository — у Finance уже есть доступ).
2. Если `Company.financeLockBefore !== null` и `financeLockBefore >= lastDayOfMonth` → return `true`.
3. Если `shopRef !== ''` — найти `MarketplaceMonthClose` по `(companyId, marketplace=derived, year, month)` (marketplace выводится из формата shopRef — обсудить при разведке). Если найден и `stage = CLOSED` → return `true`.
4. Если `shopRef === ''` — проверить **все** `MarketplaceMonthClose` для компании за этот период; если хотя бы один закрыт → return `true` (консервативно: не пересчитываем затронутый закрытым).
5. Иначе return `false`.

Возвращает `bool` + (опционально) причину блокировки строкой.

### 4.2 Подписчик на событие Ingestion

#### `App\Finance\EventSubscriber\NormalizationCompletedSubscriber`

Файл: `src/Finance/EventSubscriber/NormalizationCompletedSubscriber.php`. `final class`, имплементит `EventSubscriberInterface`.

Подписка: `NormalizationCompletedEvent::class => 'onNormalizationCompleted'`.

Шаги в `onNormalizationCompleted(NormalizationCompletedEvent $event): void`:
1. Для каждого `AffectedPeriod $period` из `$event->affectedPeriods`:
   - Вычислить новый месяц: `(year, month) = PnlPeriodResolver::from($period->newOccurredAt)`.
   - Если `$period->oldOccurredAt !== null` и `month старого ≠ month нового` → вычислить и старый период.
   - Для каждого периода: dispatch `MarkPnlPeriodDirtyMessage($companyId, $year, $month, $period->shopRef, INGEST)` в `ingest_normalize` (использовать тот же transport — обработка быстрая).

Подписчик НЕ зовёт `RebuildPnlPeriodAction` напрямую — только маркирует. Сама перегенерация — асинхронная пачка через воркер.

Альтернатива (более простая на старте): подписчик сразу dispatch'ит `RebuildPnlPeriodMessage` для каждого периода. Выбираем **через mark dirty** — это даёт сглаживание нагрузки (досыл за 200 дней не запустит 200 одновременных пересчётов).

### 4.3 PnlPeriodResolver

#### `App\Finance\Application\Service\PnlPeriodResolver`

`final class`. Чистая логика, без зависимостей.

Методы:
- `from(DateTimeImmutable $at): array{0: int, 1: int}` — `[year, month]` в TZ `Europe/Moscow` (для согласованности с финансовой отчётностью). Конвертация UTC → Europe/Moscow → year/month.

### 4.4 DTO

`MarkPnlPeriodDirtyCommand`:
| Поле | Тип | Обязательно | Валидация |
|---|---|---|---|
| `companyId` | string | да | UUID |
| `year` | int | да | 2020..2100 |
| `month` | int | да | 1..12 |
| `shopRef` | string | да | может быть '' |
| `reason` | PLDirtyPeriodReason | да | enum |

`RebuildPnlPeriodCommand`:
| Поле | Тип | Обязательно | Валидация |
|---|---|---|---|
| `companyId` | string | да | UUID |
| `year` | int | да | 2020..2100 |
| `month` | int | да | 1..12 |
| `shopRef` | string | нет | '' = все магазины |

### 4.5 Facade

#### `App\Finance\Facade\PnlFacade` (расширение существующего, если есть; иначе новый)

Методы (новые):
- `markPeriodDirty(MarkPnlPeriodDirtyCommand $command): void`.
- `rebuildPeriod(RebuildPnlPeriodCommand $command): void`.
- `getDirtyPeriods(string $companyId): list<PLDirtyPeriodView>` — для UI блока 8.
- `getProgress(string $companyId): PnlProgressView` — счётчики `pending/rebuilding/done/failed/blocked` для admin (блок 9).

`PLDirtyPeriodView` — `final readonly class` в `App\Ingestion\Application\DTO`; `PnlProgressView` — `final readonly class` в `App\Finance\Application\DTO`.

### 4.6 Доменное событие

#### `App\Finance\Domain\Event\PnlClosedPeriodTouchedEvent`

`final readonly class`.

Поля: `companyId: string`, `year: int`, `month: int`, `shopRef: string`, `reason: string`.

Публикуется в `MaybeBlockByClosePeriodAction` когда период заблокирован. Подписчиков в блоке 7 нет (заглушка для блока 9, где появится уведомление).

---

## 5. Асинхронность (Messenger)

### 5.1 Сообщения

#### `App\Finance\Message\MarkPnlPeriodDirtyMessage`

`final readonly class`, реализует `CompanyAwareMessage` (из блока 1).
Поля: `companyId, year, month, shopRef, reasonValue`. Метод `getCompanyId()`.

Routing: `ingest_normalize` (быстрая операция, на тех же воркерах что и нормализация — экономим на отдельном пуле).

#### `App\Finance\Message\RebuildPnlPeriodMessage`

`final readonly class`, реализует `CompanyAwareMessage`.
Поля: `companyId, year, month, shopRef`.

Routing: `pnl_rebuild` — новый transport (см. §5.3).

### 5.2 Handler'ы

`MarkPnlPeriodDirtyHandler` — вызывает `MarkPnlPeriodDirtyAction`. IdempotentHandlerTrait по natural key `(companyId, year, month, shopRef)`.

`RebuildPnlPeriodHandler` — вызывает `RebuildPnlPeriodAction`. IdempotentHandlerTrait по тому же key.

### 5.3 Routing

`config/packages/messenger.yaml`:

```yaml
framework:
  messenger:
    transports:
      pnl_rebuild:
        dsn: '%env(MESSENGER_TRANSPORT_DSN_PIPELINE)%'   # тот же DSN что async_pipeline
        retry_strategy:
          max_retries: 3
          delay: 30000
          multiplier: 2
    routing:
      App\Finance\Message\MarkPnlPeriodDirtyMessage: ingest_normalize
      App\Finance\Message\RebuildPnlPeriodMessage: pnl_rebuild
```

### 5.4 Воркер пачкой

#### `App\Finance\Command\RebuildDirtyPnlPeriodsCommand`

CLI-команда, регистрируется как cron-задача (`docker/cron/app.cron`):

```
*/5 * * * * /usr/local/bin/php /app/bin/console app:finance:rebuild-dirty-pnl --max=20 --no-interaction --quiet
```

Шаги:
1. `findPending(limit=$max)`.
2. Для каждого: dispatch `RebuildPnlPeriodMessage(companyId, year, month, shopRef)` в `pnl_rebuild`.
3. Лог: «Dispatched N rebuild jobs».

Команда сама **не** делает rebuild — только диспатчит сообщения, чтобы рабочая нагрузка размазалась по воркерам и не блокировала cron-тик.

### 5.5 Идемпотентность

`MarkPnlPeriodDirtyAction` идемпотентен по природе (повтор не меняет PENDING).

`RebuildPnlPeriodAction` идемпотентен через:
- Redis Lock — параллельные rebuild'ы одного периода сериализуются.
- DELETE-then-INSERT — повторный запуск даёт тот же результат.

Это закрывает оба сценария: «handler упал между DELETE и INSERT» → лок отпустится, новый запуск увидит, что dirty period в REBUILDING и атаmpt'ом > 0, и сделает rebuild заново.

---

## 6. Обработка ошибок

| Класс | Когда | HTTP-статус | error.code | message |
|---|---|---|---|---|
| `PLDirtyPeriodNotFoundException` | Action не нашёл dirty-period | 404 | `pnl_dirty_period_not_found` | «Запись о грязном периоде не найдена» |
| `PnlPeriodClosedException` | Попытка rebuildPeriod закрытого периода (через Facade без воркера) | 409 | `pnl_period_closed` | «Период закрыт, перегенерация невозможна» |
| `PnlCategoryResolveException` | Не нашли подходящую PLCategory и не смогли создать fallback | 500 | `pnl_category_resolve_failed` | «Не удалось определить категорию P&L» |
| `PnlRebuildLockTimeoutException` | Не смогли взять Redis Lock за таймаут | 503 | `pnl_rebuild_busy` | «Перегенерация уже выполняется, повторите позже» |

Все исключения — `App\Finance\Exception\*`, `final class`.

---

## 7. HTTP API (Controller)

N/A в блоке 7 (будет в блоке 8: эндпоинты для ручного запуска `rebuildPeriod`, просмотра `getDirtyPeriods`, `getProgress`).

---

## 8. Разбивка на подзадачи

| Этап | Что | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| B1 | `PLDirtyPeriod` Entity + enum + миграция + добавление `rebuiltAt` в PLDailyTotal/PLMonthlySnapshot | блок 5 | 🔴 | unit инвариантов |
| B2 | `PLDirtyPeriodRepository` + `deleteByCompanyShopAndMonth` в PLDailyTotal/Snapshot Repo | B1 | 🟡 | tenant-leak, DELETE-тест |
| B3 | `PnlPeriodResolver` (TZ Europe/Moscow) | — | 🟢 | unit на edge cases (00:00 моск.т., decembernewyear, leap year) |
| B4 | `PnlCategoryResolver` + разведка legacy `MarketplaceCostPLMapping` | блок 5 | 🟡 | unit на маппинг каждого `TransactionType` |
| B5 | `MarkPnlPeriodDirtyAction` + `MarkPnlPeriodDirtyMessage` + Handler | B1, B2 | 🟡 | идемпотентность |
| B6 | `NormalizationCompletedSubscriber` | блок 5, B5 | 🟡 | подписан, корректно диспатчит при смене периода |
| B7 | `MaybeBlockByClosePeriodAction` + `PnlClosedPeriodTouchedEvent` | B1 | 🟡 | тест на 4 кейса: financeLockBefore / MarketplaceMonthClose / shop / all-shops |
| B8 | `RebuildPnlPeriodAction` + Redis Lock + `RebuildPnlPeriodMessage` + Handler | B4, B7 | 🔴 | главное ядро: идемпотентность, перезапись, lock |
| B9 | `RebuildDirtyPnlPeriodsCommand` + cron | B8 | 🟡 | integration |
| B10 | `PnlFacade` методы | B5, B8 | 🟢 | integration |
| B11 | `ARCHITECTURE.md` обновить | все | 🟢 | — |

---

## 9. Ограничения и запреты

- Не модифицировать legacy-наполнение `PLDailyTotal`/`PLMonthlySnapshot` со стороны `Marketplace`. Параллельная coexistence: legacy и Ingestion могут писать в одну таблицу. До блока 9 — это допустимо, поскольку Ingestion ещё не покрывает все источники.
  - Важный нюанс: при `rebuildPeriod` мы DELETE'им строки месяца **только** по записям с `rebuiltAt IS NOT NULL` (т.е. ранее созданным новым rebuild'ом). Записи legacy остаются. **НЕТ** — это сложно и хрупко. Лучше: в блоке 7 фактическое выполнение `rebuildPeriod` ЗАПРЕЩЕНО, когда есть legacy-источник, который ещё пишет в этот period. Это контролируется через feature flag (см. блок 9).
  - В блоке 7 `rebuildPeriod` работает, но в production cron `RebuildDirtyPnlPeriodsCommand` отключён (или feature flag), пока не пройдёт shadow в блоке 9. Команда — для тестов и admin.
- Не трогать `Company.financeLockBefore`, `MarketplaceMonthClose` — только читать.
- Не трогать существующий cron `app:marketplace:month-preliminary-rebuild`.
- Не вводить HTTP-эндпоинты.
- Безопасность: `Repository::findPending` (системный, без companyId) — лимит результата 200, ORDER BY чтобы не было голодания одной компании.
- Performance: `rebuildPeriod` для одного месяца одной компании должен укладываться в 30 сек (по канону ~100k транзакций). Использовать `iterateByPeriod` + batch flush каждые 1000 строк.

---

## 10. Критерии приёмки

Функциональные:
- [ ] Создание `PLDirtyPeriod` через `MarkPnlPeriodDirtyAction` идемпотентно.
- [ ] `NormalizationCompletedSubscriber` правильно вычисляет период (TZ Europe/Moscow) и диспатчит сообщения mark dirty.
- [ ] При смене периода операции (`oldOccurredAt` ≠ `newOccurredAt` по месяцу) помечаются ОБА периода.
- [ ] `RebuildPnlPeriodAction` полностью перезаписывает `PLDailyTotal`/`PLMonthlySnapshot` за месяц.
- [ ] Запуск `rebuildPeriod` 5 раз подряд даёт идентичный результат.
- [ ] Конкурентные `rebuildPeriod` одного периода сериализуются через Redis Lock.
- [ ] `financeLockBefore` блокирует rebuild — статус `BLOCKED_BY_CLOSE`, событие `PnlClosedPeriodTouchedEvent` опубликовано.
- [ ] `MarketplaceMonthClose` блокирует rebuild — то же поведение.
- [ ] `reopen()` после открытия периода → возможен новый rebuild.
- [ ] `RebuildDirtyPnlPeriodsCommand` диспатчит до `--max` сообщений, не блокирует cron.
- [ ] `PnlFacade.getDirtyPeriods` возвращает список с view.
- [ ] `PnlFacade.getProgress` возвращает корректные счётчики.

Технические:
- [ ] Миграции применяются/откатываются.
- [ ] `make site-cs-check` + PHPStan зелёные.
- [ ] `make site-test-unit` + `make site-test-integration` зелёные.
- [ ] Tenant-leak на `PLDirtyPeriod`.
- [ ] `RebuildPnlPeriodAction` для 10k транзакций укладывается в 5 сек (integration-бенчмарк).
- [ ] Подписчик правильно регистрируется в `event_dispatcher` (тест: dispatch события → handler вызван).
- [ ] `ARCHITECTURE.md` обновлён.

---

## 11. План отката

- Удалить `NormalizationCompletedSubscriber` → события Ingestion перестают порождать dirty-periods. Канон продолжает писаться, P&L продолжает наполняться legacy.
- DROP `pnl_dirty_periods`. Откат полей `rebuilt_at` — оставить (nullable, никому не мешает; удалять отдельной миграцией позже).
- Удалить cron `app:finance:rebuild-dirty-pnl` из расписания.
- Зависимости вниз: блок 8 (UI) и блок 9 (shadow) используют `PnlFacade.getDirtyPeriods/getProgress`. Их откат — отдельная задача.

---

## 12. Чек-лист качества ТЗ

- [x] Полный namespace и путь.
- [x] Полная таблица `PLDirtyPeriod` с инвариантами.
- [x] Изменения существующих сущностей (`PLDailyTotal.rebuiltAt`, `PLMonthlySnapshot.rebuiltAt`) явно указаны.
- [x] Enum с матрицей переходов.
- [x] Сигнатуры всех методов Repository/Action/Facade.
- [x] `findPending` помечен как системный (без companyId) с обоснованием.
- [x] HTTP — N/A явно.
- [x] Исключения с кодами.
- [x] Индексы с именами.
- [x] Транспорты Messenger.
- [x] TZ Europe/Moscow зафиксирован для вычисления периода.
- [x] Out of scope явно (coexistence с legacy остаётся, переключение — в блоке 9).
- [x] План отката не разрушает данные.
