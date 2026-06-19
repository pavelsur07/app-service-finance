# TASK-FIX-03 — Системные контрагенты маркетплейсов + удаление Ingestion\Counterparty

## 0. Сводка

- **Бизнес-цель.** В блоке 5 была создана заглушка `App\Ingestion\Entity\Counterparty`, дублирующая существующий `App\Company\Entity\Counterparty`. При нормализации Ozon-транзакций контрагент (маркетплейс) должен ссылаться на системный справочник, а не на внутреннюю заглушку. Вводим таблицу `system_counterparties` для маркетплейсов (Ozon, WB) — данные одинаковы для всех компаний — и удаляем дублирующую сущность.
- **Модуль.** `App\Ingestion` (существующий).
- **Тип.** refactor + bugfix.
- **Ветка.** `fix/ingestion-system-counterparties`.
- **Подзадачи.** B1 Новая Entity `SystemCounterparty` + миграция · B2 Удалить `Ingestion\Counterparty` + миграция DROP · B3 Обновить `FinancialTransaction.counterpartyId` · B4 `SystemCounterpartyResolver` · B5 Обновить `NormalizeRawRecordAction` · B6 Тесты.
- **Затрагивает другие модули.** Нет (`App\Company\Entity\Counterparty` не трогаем).
- **Требует миграции БД.** Да (CREATE TABLE `system_counterparties` + DROP TABLE `ingest_counterparties`).
- **Меняет публичный API.** Нет.

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- `App\Ingestion\Entity\Counterparty` — `src/Ingestion/Entity/Counterparty.php`, таблица `ingest_counterparties`. **Таблица пустая в prod.**
- `App\Ingestion\Entity\FinancialTransaction.counterpartyId` — ссылается на `Ingestion\Counterparty.id` (строка).
- `App\Company\Entity\Counterparty` — полноценный справочник с ИНН, типом, скорингом. Таблица `counterparty`.
- `NormalizeRawRecordAction` — создаёт/резолвит `Ingestion\Counterparty` при нормализации.
- Для маркетплейс-транзакций контрагент — сам маркетплейс (Ozon/WB). ИНН публичны, одинаковы для всех компаний.

### 1.2 Желаемое состояние

- Новая Entity `App\Ingestion\Entity\SystemCounterparty` — глобальный справочник маркетплейсов (не привязан к company).
- Таблица `system_counterparties` с seed-данными через миграцию.
- `FinancialTransaction.counterpartyId` ссылается на `SystemCounterparty.id` (строка).
- `SystemCounterpartyResolver` резолвит контрагента по `IngestSource` → `SystemCounterparty.id`.
- `Ingestion\Counterparty` (заглушка) и таблица `ingest_counterparties` удалены.
- `NormalizeRawRecordAction` использует `SystemCounterpartyResolver` вместо `Ingestion\Counterparty`.

### 1.3 In scope

- Новая Entity `SystemCounterparty` + Repository + миграция CREATE TABLE + seed.
- DROP TABLE `ingest_counterparties` + удаление `Ingestion\Counterparty` + Repository заглушки.
- Обновление `FinancialTransaction` — поле `counterpartyId` остаётся string, семантика меняется (теперь ссылается на `system_counterparties.id`).
- `SystemCounterpartyResolver` — резолвинг по `IngestSource`.
- Обновление `NormalizeRawRecordAction` — использовать resolver.

### 1.4 Out of scope

- `App\Company\Entity\Counterparty` — не трогаем.
- Связь `FinancialTransaction` с `App\Company\Entity\Counterparty` — отдельная задача.
- Покупатели/физлица как контрагенты — не в этой задаче.
- HTTP API для `SystemCounterparty` — не нужен (только внутренний резолвинг).

### 1.5 Допущения

- Допущение: `SystemCounterparty` — глобальная (без companyId). **Не реализует `TenantOwnedInterface`**. Repository явно не принимает companyId (данные одинаковы для всех).
- Допущение: seed-данные для Ozon и WB вставляются в миграции через `INSERT` с фиксированными UUID v5.

---

## 2. Доменная модель

### 2.1 Сущности (Entity)

#### `App\Ingestion\Entity\SystemCounterparty`

Файл: `src/Ingestion/Entity/SystemCounterparty.php`.
Таблица: `#[ORM\Table(name: 'system_counterparties')]`.
**Не реализует `TenantOwnedInterface`** — глобальная сущность.

| Поле | Тип PHP | Колонка БД | Nullable | Default | Инвариант / правило |
|---|---|---|---|---|---|
| `id` | string UUID v5* | `id` GUID | нет | — | PK; фиксированный UUID v5 от имени маркетплейса |
| `source` | IngestSource | `source` VARCHAR(64) | нет | — | enumType=IngestSource; уникален; неизменяем |
| `name` | string | `name` VARCHAR(255) | нет | — | `Assert::notEmpty`; неизменяем |
| `inn` | string | `inn` VARCHAR(12) | нет | — | ИНН маркетплейса; `Assert::regex('/^\d{10}(\d{2})?$/')` |
| `createdAt` | DateTimeImmutable | `created_at` TIMESTAMP(6) | нет | — | в конструкторе |

*UUID v5 генерируется детерминированно от `source.value`, чтобы seed был идемпотентным.

Конструктор: `__construct(string $id, IngestSource $source, string $name, string $inn)`.

Инварианты: `Assert::uuid($id)`, `Assert::notEmpty($name)`, `Assert::regex($inn, '/^\d{10}(\d{2})?$/')`.

Геттеры: `getId()`, `getSource()`, `getName()`, `getInn()`.

**Seed-данные (вставляются в миграции):**

| source | name | inn | UUID v5 |
|---|---|---|---|
| `ozon` | «Ozon» | `7704217370` | детерминированный от `system:ozon` |
| `wildberries` | «Wildberries» | `7721546864` | детерминированный от `system:wildberries` |

#### `App\Ingestion\Entity\FinancialTransaction` (правка)

Изменение только семантики поля `counterpartyId` — **тип и колонка не меняются**:

- `counterpartyId: ?string` — теперь ссылается на `system_counterparties.id`.
- Миграция не нужна (колонка `counterparty_id` уже существует, nullable).

#### Удалить: `App\Ingestion\Entity\Counterparty`

Файл `src/Ingestion/Entity/Counterparty.php` — удалить.
Таблица `ingest_counterparties` — DROP в миграции (таблица пустая).

### 2.2 Связи

`SystemCounterparty` — самостоятельная Entity, без связей с другими Entity.
`FinancialTransaction.counterpartyId` → `system_counterparties.id` (строка, не ManyToOne).

### 2.3 Enum

Новых enum нет. Используется существующий `IngestSource` (OZON, WILDBERRIES).

### 2.4 Матрица переходов

N/A.

---

## 3. Слой доступа к данным

### 3.1 Repository

#### `App\Ingestion\Repository\SystemCounterpartyRepository`

Файл: `src/Ingestion/Repository/SystemCounterpartyRepository.php`. `final class`.

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findBySource(IngestSource $source): ?SystemCounterparty` | Поиск по source. Глобальный — нет companyId. | нет* | `?SystemCounterparty` |
| `getBySource(IngestSource $source): SystemCounterparty` | То же, бросает `SystemCounterpartyNotFoundException` если нет | нет* | `SystemCounterparty` |

*Глобальный справочник — companyId не нужен. Метод явно помечен как системный.

#### Удалить: `App\Ingestion\Repository\CounterpartyRepository` (заглушка)

Файл `src/Ingestion/Repository/CounterpartyRepository.php` — удалить.

### 3.2 Query

N/A.

### 3.3 Индексы

`system_counterparties`:
- UNIQUE `(source)` → `uniq_system_counterparty_source`.

---

## 4. Слой приложения

### 4.1 Action

#### `App\Ingestion\Application\Action\NormalizeRawRecordAction` (правка)

Изменить шаг резолвинга контрагента:

Было: создавать/искать `Ingestion\Counterparty` через `CounterpartyRepository::getOrCreate`.

Стало:
1. Получить `IngestSource` из `$rawRecord->getSource()`.
2. Вызвать `SystemCounterpartyResolver::resolve($source)` → `string $counterpartyId`.
3. Записать `counterpartyId` в `MappedTransaction` (или напрямую в `UpsertFinancialTransactionAction`).

Если resolver вернул null (неизвестный source) — `counterpartyId = null`, лог WARNING.

### 4.2 Domain Service

#### `App\Ingestion\Application\Service\SystemCounterpartyResolver`

Файл: `src/Ingestion/Application/Service/SystemCounterpartyResolver.php`. `final class`.

Конструктор: `SystemCounterpartyRepository $repository`.

Методы:
- `resolve(IngestSource $source): ?string` — возвращает `SystemCounterparty.id` для данного source. Кешируется в `array` свойстве на время жизни объекта (один запрос к БД за source за весь batch). Возвращает null если source не найден в справочнике.

### 4.3 DTO

N/A — изменения только в `NormalizeRawRecordAction`, DTO не меняется.

---

## 5. Асинхронность (Messenger)

Без изменений. `NormalizeRawRecordMessage` и handler не меняются.

---

## 6. Обработка ошибок

| Класс | Когда | HTTP-статус | error.code | error.message |
|---|---|---|---|---|
| `App\Ingestion\Exception\SystemCounterpartyNotFoundException` | `getBySource` не нашёл запись | 500 | `system_counterparty_not_found` | «Системный контрагент не найден для источника» |

`final class`, extends `\RuntimeException`.

---

## 7. HTTP API

N/A.

---

## 8. Разбивка на подзадачи

| Этап | Что входит | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| B1 | `SystemCounterparty` Entity + Repository + миграция CREATE + seed | — | 🔴 | unit инвариантов; integration: seed-данные присутствуют |
| B2 | DROP `ingest_counterparties` + удаление `Ingestion\Counterparty` + его Repository | B1 | 🔴 | `doctrine:schema:validate` |
| B3 | `SystemCounterpartyResolver` | B1 | 🟢 | unit: кеш, null для неизвестного source |
| B4 | Правка `NormalizeRawRecordAction` | B3 | 🟡 | integration: `counterpartyId` ссылается на `system_counterparties` |
| B5 | Тесты + `ARCHITECTURE.md` | B1-B4 | 🟢 | полный suite |

**B1 — детализация:**
- Создаёт: `src/Ingestion/Entity/SystemCounterparty.php`, `src/Ingestion/Repository/SystemCounterpartyRepository.php`, `site/migrations/Version20260619110000.php`.
- Миграция: CREATE TABLE `system_counterparties` + INSERT двух seed-записей с фиксированными UUID.
- DoD: `bin/console doctrine:migrations:execute` проходит; seed-записи есть.

**B2 — детализация:**
- Удаляет: `src/Ingestion/Entity/Counterparty.php`, `src/Ingestion/Repository/CounterpartyRepository.php`.
- Создаёт: `site/migrations/Version20260619120000.php` (DROP TABLE `ingest_counterparties`).
- DoD: `doctrine:schema:validate` зелёный; grep на `Ingestion\\Entity\\Counterparty` — пусто.

---

## 9. Ограничения и запреты

- Не трогать `App\Company\Entity\Counterparty` и таблицу `counterparty`.
- Не добавлять companyId в `SystemCounterparty` — это глобальный справочник.
- Миграция DROP — безопасна, таблица пустая. Проверить перед запуском: `SELECT COUNT(*) FROM ingest_counterparties`.
- `FinancialTransaction.counterpartyId` — nullable, тип колонки не меняется, миграция не нужна.
- Не создавать ManyToOne связь между `FinancialTransaction` и `SystemCounterparty`.
- Zero-downtime: сначала B1 (CREATE), потом B2 (DROP).

---

## 10. Критерии приёмки

Функциональные:
- [ ] `system_counterparties` содержит записи для OZON и WILDBERRIES после миграции.
- [ ] `SystemCounterpartyResolver.resolve(IngestSource::OZON)` возвращает UUID Ozon.
- [ ] `NormalizeRawRecordAction` пишет корректный `counterpartyId` из `system_counterparties`.
- [ ] `FinancialTransaction.counterpartyId` ссылается на `system_counterparties.id`.
- [ ] `ingest_counterparties` таблица удалена.
- [ ] `Ingestion\Entity\Counterparty` класс удалён.

Технические:
- [ ] `grep -rn "Ingestion\\\\Entity\\\\Counterparty" site/src/` — пусто.
- [ ] `doctrine:schema:validate --skip-sync --env=test` — зелёный.
- [ ] `lint:container --env=test` — зелёный.
- [ ] `make site-test-unit` — зелёный.
- [ ] `php-cs-fixer` — зелёный.
- [ ] `ARCHITECTURE.md` обновлён: добавлен `SystemCounterparty`.

---

## 11. План отката

- Миграция B2 (DROP) откатывается через `down()` — воссоздаёт `ingest_counterparties`. Данных нет — безопасно.
- Миграция B1 (CREATE) откатывается DELETE seed + DROP TABLE.
- Восстановить удалённые файлы через git revert.
- `FinancialTransaction.counterpartyId` — nullable, при откате просто null.

---

## 12. Чек-лист качества ТЗ

- [x] Полный namespace и путь для каждого класса.
- [x] Таблица полей `SystemCounterparty` с инвариантами.
- [x] Seed-данные с ИНН указаны явно.
- [x] Обоснование отсутствия companyId в глобальном справочнике.
- [x] Порядок миграций: CREATE перед DROP.
- [x] Проверка пустоты таблицы перед DROP.
- [x] `FinancialTransaction` — только семантика меняется, не схема.
- [x] HTTP — N/A.
- [x] Out of scope: Company\Counterparty, покупатели.
- [x] Plan отката без потери данных.
- [x] grep-команда для проверки удаления.
- [x] Не ManyToOne — явный запрет.
