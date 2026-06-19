# TASK-FIX-01 — Перенос PLDirtyPeriod из App\Finance в App\Ingestion

## 0. Сводка

- **Бизнес-цель.** `PLDirtyPeriod` описывает состояние пайплайна загрузки («период стал грязным — пересчитай»), а не финансовую концепцию. Нахождение в `App\Finance` нарушает архитектурную границу: Finance не должен знать про источники данных. Перенос устраняет это нарушение без изменения бизнес-логики.
- **Модуль.** `App\Ingestion` (существующий) ← принимает. `App\Finance` (существующий) ← отдаёт Entity/Enum/Repository, сохраняет Action/Message/Handler/Facade.
- **Тип.** refactor.
- **Ветка.** `fix/ingestion-move-pldirtyperiod`.
- **Подзадачи.** B1 Перенос файлов + namespace · B2 Добавить TenantOwnedInterface · B3 Обновить импорты · B4 Doctrine mapping · B5 Тесты.
- **Затрагивает другие модули.** Да → `App\Finance` (Action/Message/Handler/Facade обновляют use-импорты).
- **Требует миграции БД.** Нет (таблица `pnl_dirty_periods` не переименовывается).
- **Меняет публичный API.** Нет.

---

## 1. Контекст и границы

### 1.1 Текущее состояние

- `App\Finance\Entity\PLDirtyPeriod` — `src/Finance/Entity/PLDirtyPeriod.php`.
- `App\Finance\Enum\PLDirtyPeriodStatus` — `src/Finance/Enum/PLDirtyPeriodStatus.php`.
- `App\Finance\Enum\PLDirtyPeriodReason` — `src/Finance/Enum/PLDirtyPeriodReason.php`.
- `App\Finance\Repository\PLDirtyPeriodRepository` — `src/Finance/Repository/PLDirtyPeriodRepository.php`.
- Используется в `App\Finance`: `MarkPnlPeriodDirtyAction`, `MaybeBlockByClosePeriodAction`, `RebuildPnlPeriodAction`, `MarkPnlPeriodDirtyMessage`, `RebuildPnlPeriodMessage`, их Handler'ы, `PnlFacade`, `NormalizationCompletedSubscriber`.
- `PLDirtyPeriod` **не** реализует `TenantOwnedInterface` (`App\Ingestion\Domain\TenantOwnedInterface`).
- Таблица в БД: `pnl_dirty_periods`. Не переименовывается.

### 1.2 Желаемое состояние

- Entity, Enum, Repository живут в `App\Ingestion`.
- `PLDirtyPeriod` реализует `TenantOwnedInterface` → автоматически покрывается `CompanyFilter` (блок 1).
- Весь Finance-код (Action/Message/Handler/Facade) обновляет use-импорты и продолжает работать без изменения логики.
- `doctrine:schema:validate` и все тесты зелёные.

### 1.3 In scope

- Перемещение 4 файлов с обновлением namespace.
- Добавление `implements TenantOwnedInterface` на Entity.
- Обновление всех use-импортов в `App\Finance`.
- Проверка Doctrine mapping (если используется явный путь к namespace в `doctrine.yaml`).

### 1.4 Out of scope

- Изменение логики внутри любого из переносимых классов.
- Переименование таблицы `pnl_dirty_periods`.
- Изменение Action/Message/Handler/Facade в Finance (только use-импорты).
- Любые другие задачи из списка followup.

### 1.5 Допущения

- Допущение: Doctrine mapping настроен по namespace/directory автоматически. Если явный список путей — добавить `src/Ingestion/Entity` в `doctrine.yaml`.
- Допущение: `PLDirtyPeriodRepository` использует `ServiceEntityRepository` — после смены namespace регистрация в DI пройдёт автоматически.

---

## 2. Доменная модель

### 2.1 Сущности (Entity)

#### `App\Ingestion\Entity\PLDirtyPeriod`

Файл после переноса: `src/Ingestion/Entity/PLDirtyPeriod.php`.
Таблица: `#[ORM\Table(name: 'pnl_dirty_periods')]` — **без изменений**.

Добавить: `implements TenantOwnedInterface` (`App\Ingestion\Domain\TenantOwnedInterface`).

Метод `getCompanyId(): string` уже есть в классе — достаточно добавить `implements`.

Все поля, инварианты и поведенческие методы — **без изменений**.

### 2.2 Связи

Без изменений.

### 2.3 Enum

#### `App\Ingestion\Enum\PLDirtyPeriodStatus`

Файл: `src/Ingestion/Enum/PLDirtyPeriodStatus.php`. Только namespace — без изменений в cases.

#### `App\Ingestion\Enum\PLDirtyPeriodReason`

Файл: `src/Ingestion/Enum/PLDirtyPeriodReason.php`. Только namespace.

### 2.4 Матрица переходов

Без изменений (логика в Entity не меняется).

---

## 3. Слой доступа к данным

### 3.1 Repository

#### `App\Ingestion\Repository\PLDirtyPeriodRepository`

Файл: `src/Ingestion/Repository/PLDirtyPeriodRepository.php`. Только namespace — логика без изменений.

| Метод | Что делает | companyId | Возврат |
|---|---|---|---|
| `findOne(string $companyId, int $year, int $month, string $shopRef = ''): ?PLDirtyPeriod` | По уникальному ключу | да | `?PLDirtyPeriod` |
| `findPending(int $limit = 50): list<PLDirtyPeriod>` | Системный: PENDING по всем компаниям для воркера | нет* | `list<PLDirtyPeriod>` |
| `findPendingForCompany(string $companyId): list<PLDirtyPeriod>` | PENDING конкретной компании | да | `list<PLDirtyPeriod>` |
| `countByStatus(string $companyId, PLDirtyPeriodStatus $status): int` | Счётчик по статусу | да | `int` |

*`findPending` — системный метод воркера (обходит всех тенантов осознанно).

### 3.2 Query

N/A.

### 3.3 Индексы

Без изменений (таблица уже создана с индексами в миграции `Version20260618140000`).

---

## 4. Слой приложения

### 4.1 Action

Все Action **остаются в `App\Finance`**. Меняются только use-импорты:

- `src/Finance/Application/Action/MarkPnlPeriodDirtyAction.php`
- `src/Finance/Application/Action/MaybeBlockByClosePeriodAction.php`
- `src/Finance/Application/Action/RebuildPnlPeriodAction.php`

Заменить:
```
use App\Finance\Entity\PLDirtyPeriod       → use App\Ingestion\Entity\PLDirtyPeriod
use App\Finance\Enum\PLDirtyPeriodStatus   → use App\Ingestion\Enum\PLDirtyPeriodStatus
use App\Finance\Enum\PLDirtyPeriodReason   → use App\Ingestion\Enum\PLDirtyPeriodReason
use App\Finance\Repository\PLDirtyPeriodRepository → use App\Ingestion\Repository\PLDirtyPeriodRepository
```

Аналогично для:
- `src/Finance/EventSubscriber/NormalizationCompletedSubscriber.php`
- `src/Finance/Message/MarkPnlPeriodDirtyMessage.php`
- `src/Finance/Message/RebuildPnlPeriodMessage.php`
- `src/Finance/MessageHandler/MarkPnlPeriodDirtyHandler.php`
- `src/Finance/MessageHandler/RebuildPnlPeriodHandler.php`
- `src/Finance/Facade/PnlFacade.php`
- `src/Finance/Command/RebuildDirtyPnlPeriodsCommand.php` (если существует)

### 4.2 Domain Service

N/A.

### 4.3 DTO

N/A.

---

## 5. Асинхронность (Messenger)

Без изменений в routing. `MarkPnlPeriodDirtyMessage` и `RebuildPnlPeriodMessage` остаются в `App\Finance\Message` — транспорты не меняются.

---

## 6. Обработка ошибок

Без изменений.

---

## 7. HTTP API

N/A.

---

## 8. Разбивка на подзадачи

| Этап | Что входит | Зависит от | Риск | Тесты |
|---|---|---|---|---|
| B1 | Переместить 4 файла, обновить namespace | — | 🔴 | — |
| B2 | Добавить `implements TenantOwnedInterface` на Entity | B1 | 🟢 | unit: getCompanyId() |
| B3 | Обновить use-импорты во всех Finance-файлах | B1 | 🟡 | — |
| B4 | Проверить/обновить Doctrine mapping в `doctrine.yaml` | B1 | 🟡 | `doctrine:schema:validate` |
| B5 | Прогнать все тесты | B1-B4 | 🟢 | все suite |

**B1 — детализация:**
- Цель: переместить файлы без изменения логики.
- Создаёт:
  - `src/Ingestion/Entity/PLDirtyPeriod.php`
  - `src/Ingestion/Enum/PLDirtyPeriodStatus.php`
  - `src/Ingestion/Enum/PLDirtyPeriodReason.php`
  - `src/Ingestion/Repository/PLDirtyPeriodRepository.php`
- Удаляет:
  - `src/Finance/Entity/PLDirtyPeriod.php`
  - `src/Finance/Enum/PLDirtyPeriodStatus.php`
  - `src/Finance/Enum/PLDirtyPeriodReason.php`
  - `src/Finance/Repository/PLDirtyPeriodRepository.php`
- DoD: файлы на новых путях, старые удалены, namespace обновлён.

**B3 — найти все импорты командой:**
```bash
grep -rn "Finance\\\\Entity\\\\PLDirtyPeriod\|Finance\\\\Enum\\\\PLDirtyPeriod\|Finance\\\\Repository\\\\PLDirtyPeriod" site/src/
```

---

## 9. Ограничения и запреты

- Не изменять логику внутри переносимых классов.
- Не переименовывать таблицу `pnl_dirty_periods`.
- Не трогать Action/Message/Handler/Facade в Finance (только use-импорты).
- Не трогать миграции — таблица уже создана корректно.
- Миграция не нужна: zero-downtime.
- Не трогать блоки 8, 9 и любые другие followup-задачи.

---

## 10. Критерии приёмки

- [ ] `grep -rn "Finance\\\\.*PLDirtyPeriod" site/src/` — пусто.
- [ ] `PLDirtyPeriod implements TenantOwnedInterface`.
- [ ] `lint:container --env=test` — зелёный.
- [ ] `doctrine:schema:validate --skip-sync --env=test` — зелёный.
- [ ] `make site-test-unit` — зелёный (1063 tests).
- [ ] Focused integration Ingestion/Finance — зелёный (47 tests).
- [ ] `php-cs-fixer` по изменённым файлам — зелёный.
- [ ] `git diff --check` — зелёный.

---

## 11. План отката

Revert коммита. Таблица `pnl_dirty_periods` не затронута — данные не теряются.

---

## 12. Чек-лист качества ТЗ

- [x] Полный namespace и путь для каждого файла.
- [x] Таблица полей Entity — без изменений (ссылка на существующий класс).
- [x] Enum cases — без изменений.
- [x] Все use-импорты перечислены явно с grep-командой.
- [x] `implements TenantOwnedInterface` — явное требование.
- [x] HTTP — N/A.
- [x] Миграция БД — не нужна, обоснование дано.
- [x] Out of scope зафиксирован.
- [x] Plan отката — revert без потери данных.
- [x] DoD с конкретными командами проверки.
- [x] Не трогать логику — явный запрет.
- [x] Системный метод `findPending` без companyId — помечен и обоснован.
