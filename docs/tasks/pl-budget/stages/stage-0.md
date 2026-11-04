# Stage 0: модель и операции без UI

**Risk:** HIGH-LOCAL (новый модуль, миграция)
**Stage base commit:** `ecf23ca53`

## 1. Какую задачу решаем

Хранить варианты бюджета ОПиУ и плановые суммы так, чтобы инварианты держала
БД и Entity: один утверждённый вариант на год, правка только черновика, только
листовые статьи своей компании и допустимые пары «проект × ЦФО», повтор
сохранения без дублей. Без экранов.

## 2. Что будет результатом

Модуль `src/Budget/`:

| Слой | Что |
|---|---|
| Enum | `BudgetVersionStatus` (`draft` / `approved` / `archived`), `BudgetLineSource` (`manual` / `sales_plan`) |
| Entity | `BudgetVersion` (`budget_versions`): id, companyId, year, name, status, copiedFromId, createdAt, updatedAt; переходы `approve()`, `returnToDraft()`, `archive()` |
| Entity | `BudgetLine` (`budget_lines`): id, companyId, version (ManyToOne, CASCADE), plCategoryId, month (1-е число), projectDirectionId, responsibilityCenterId, amount `NUMERIC(18,2)`, source, comment, updatedAt |
| БД | unique строки по `(version_id, pl_category_id, month, project_direction_id, responsibility_center_id)`; частичный unique `(company_id, year) WHERE status = 'approved'`; FK на `companies`; без FK на статьи/проекты/ЦФО |
| Action | `CreateBudgetVersionAction` — пустой или копией (ID задаёт вызывающий, повтор → тот же вариант); `ChangeBudgetVersionStatusAction` — утвердить / вернуть в черновик / архив / восстановить, утверждение и возврат утверждённого — только владелец; `SaveBudgetLinesAction` — пачка ячеек, upsert `ON CONFLICT`, ноль без комментария удаляет строку |
| Finance | `PLCategoryDTO::$type` — тип статьи (для проверки «только листовые») |
| Прочее | Builders `tests/Builders/Budget/`, `BudgetSchemaExtension`, регистрация модуля (doctrine, `ModuleBoundaryRules`), раздел в `ARCHITECTURE.md` |

Транзакции: каждое Action — точка входа и коммитит само. Сохранение ячеек и
переходы статуса берут блокировку варианта (`pg_advisory_xact_lock`, как в
Billing: проверяется тестом со второго соединения) и перечитывают статус —
сохранение не проскочит в вариант, который параллельно утверждают. Порядок
блокировок: вариант → строки.

## 3. Что считаем выполненной работой

- Все Actions написаны через TDD: сначала интеграционный тест, красный, затем
  код.
- Тесты модуля и Finance зелёные; PHPStan и CS по изменённым файлам чистые;
  `SchemaInSyncWithMigrationsTest` и `MessengerRoutingTest` зелёные.
- Внутреннее ревью diff без BLOCKER/IMPORTANT; внешнее ревью — если diff
  > 500 строк (HIGH-LOCAL).
- `ARCHITECTURE.md` и `checkpoint.md` обновлены, коммит этапа.

## 4. Чек-лист приёмки

- [x] Пустой вариант создаётся в черновике; повтор с тем же ID → тот же
      вариант, одна строка в БД.
- [x] Копия переносит все строки с новыми ID, год берётся из исходника,
      исходник не меняется; копия чужого варианта — отказ.
- [x] Второй утверждённый вариант на тот же год отклоняется (Action и БД).
- [x] Утверждает и возвращает утверждённый в черновик только владелец
      компании; участник получает отказ.
- [x] Сохранение в утверждённый или архивный вариант — отказ.
- [x] Сохранение не-листовой статьи, чужой статьи, неактивной/чужой пары,
      месяца вне года варианта, неверной суммы — отказ, ничего не записано.
- [x] Повторное сохранение той же пачки → те же суммы и то же число строк.
- [x] Ноль без комментария удаляет строку; ноль с комментарием — хранится.
- [x] Все чтения и записи ограничены `companyId`; вариант чужой компании не
      находится ни одним Action.
- [x] Миграция обратима (`down()` удаляет только новые таблицы).

## Work items

- 0.1 — `PLCategoryDTO::$type` (тест на Facade).
- 0.2 — Enum, Entity, Builders, unit-тесты переходов статуса.
- 0.3 — миграция, SchemaExtension, регистрация модуля; гейт синхронности схемы.
- 0.4 — `CreateBudgetVersionAction` (пустой, копия, повтор, чужая компания).
- 0.5 — `ChangeBudgetVersionStatusAction` (один Action вместо трёх: правила
  переходов в одном месте — `BudgetVersionStatus::allowsTransitionTo()`).
- 0.6 — `SaveBudgetLinesAction` (валидация, upsert, удаление нуля, повтор).
- 0.7 — `ARCHITECTURE.md`, ревью, итог этапа.

---

### Stage 0: модель и операции без UI — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `ecf23ca53`
**Work items:** 0.1–0.7

#### What was done
- Модуль `Budget`: `BudgetVersion`, `BudgetLine`, `BudgetVersionStatus`
  (с `allowsTransitionTo()`), `BudgetLineSource`, `BudgetAmount`,
  `BudgetVersionLock`; Actions `CreateBudgetVersionAction`,
  `ChangeBudgetVersionStatusAction`, `SaveBudgetLinesAction`; исключения
  модуля; Builders.
- Миграция `Version20261104090000` (обратима, новые пустые таблицы),
  `BudgetSchemaExtension`, mapping в `doctrine.yaml`.
- `PLCategoryDTO::$type`; `PLCategoryFacade::findByIdAndCompany()` проверяет
  тип `instanceof` — из baseline PHPStan ушло 7 записей.
- `ARCHITECTURE.md`: модуль, раздел «Budget», `PLCategoryDTO`, Changelog 2.127.
- Отклонения от плана: смена статуса — один Action вместо трёх; Facade модуля
  отложен до первого потребителя (`ModuleBoundaryRules` не меняется — модуль
  без Facade туда не вносится); FOR UPDATE заменён advisory-блокировкой
  (проверяемо тестом со второго соединения).

#### Definition of Done
- [x] TDD: каждый тест сначала красный (проверено, в т. ч. тесты блокировок —
      красные без `BudgetVersionLock::acquire`).
- [x] Тесты модуля и Finance, `SchemaInSyncWithMigrationsTest` — зелёные.
- [x] PHPStan и CS по изменённым файлам — чисто.
- [x] `ARCHITECTURE.md`, `checkpoint.md`.

#### Checks
- baseline: `tests/Integration/Billing/AssignTariffActionTest.php` — OK (6).
- module: `tests/Unit/Budget tests/Integration/Budget tests/Integration/Finance
  tests/Unit/Finance tests/Integration/Shared/Doctrine` — OK (430), после
  правок ревью Budget — OK (54).
- `phpstan analyse` изменённых файлов — OK; `php-cs-fixer` (`.php-cs-fixer.php`,
  без кеша) — OK. Полные гейты — на handoff.

#### Internal review
- iterations: 2; IMPORTANT: 2 исправлено — обход «только владелец» при
  параллельной смене статуса (под блокировкой любое расхождение статуса —
  конфликт); участник при повторном утверждении утверждённого получал успех
  (теперь отказ). FOLLOW-UP: маппинг исключений Budget в HTTP — этап 1.

#### External review
- required: yes (HIGH-LOCAL, diff > 500 строк); rounds: 1; result: fixed
  without re-run.
- confirmed fixed: IMPORTANT — UUID варианта в верхнем регистре брал другой
  ключ блокировки; ключ приведён к нижнему регистру, сохранение берёт ID
  загруженного варианта; регрессионный тест
  `testUppercaseVersionIdWaitsForSameLock` (красный до правки).
- rejected: нет.

#### Risks / reviewer focus
- Знак суммы при вводе на экране — вопрос Владельцу (`ui-ux.md` §7).

#### Next
- continue to Stage 1 automatically.
