## Stage 1: Схема, Entity, dual-write, аудит, backfill/verify — DONE

**Риск:** 🟠 HIGH-LOCAL
**Owner gate:** no
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** continue autonomously — Stage 2 это Production Gate A, вход в него только по отдельному разрешению Владельца

### Scope Stage

- Stage base commit: `80ad3cfe26237824682f78526702fe7ee97649ff`
- Work items completed: `1.1`–`1.10`
- Читатели категории **не переключались** — они по-прежнему читают `cash_transaction.cashflow_category_id`.

### Что сделано

- Таблица `cash_transaction_split`: транзакция, `company_id`, категория, положительная сумма, `source`.
  Уникальна пара (транзакция, категория), FK на транзакцию с `ON DELETE CASCADE`, CHECK на положительность.
- Entity `CashTransactionSplit` + enum `CashTransactionSplitSource` (`manual` / `auto` / `import`).
  Компания выводится из транзакции, категория проверяется на принадлежность той же компании.
- `CashTransaction::replaceSplits()` — единственная точка проверки инвариантов набора: состав непустой,
  сумма строк строго равна сумме транзакции (bcmath, scale 2), категории не повторяются, все строки
  принадлежат этой транзакции, при количестве строк > 1 запрещены категории с `allowPlDocument = true`.
- `CashTransactionSplitSynchronizer` — dual-write. Строки повторяют колонку один в один, включая её
  пустое состояние. Ручную мультиразбивку и строки `manual` авторазбивка не перезаписывает.
- Aggregate-`AuditLog` на `CashTransaction` с `diff['splits'] = [before, after]`. Первое заполнение
  не аудируется (покрыто CREATE-записью), во время работы автоправил запись подавляется.
- Команды `app:cash:backfill-transaction-splits` (батчами, идемпотентная, `source` из провенанс-резолвера)
  и `app:cash:verify-transaction-splits` (построчная сверка по 8 проверкам + итоги по
  company × category × direction × currency, печатает покрытие, ненулевой exit code при расхождении).

### Затронутые файлы

- `site/migrations/Version20260801160000.php` — new
- `site/src/Cash/Entity/Transaction/CashTransactionSplit.php` — new
- `site/src/Cash/Enum/Transaction/CashTransactionSplitSource.php` — new
- `site/src/Cash/Repository/Transaction/CashTransactionSplitRepository.php` — new
- `site/src/Cash/Service/Transaction/CashTransactionSplitSynchronizer.php` — new
- `site/src/Cash/Command/BackfillCashTransactionSplitsCommand.php` — new
- `site/src/Cash/Command/VerifyCashTransactionSplitsCommand.php` — new
- `site/src/Cash/Entity/Transaction/CashTransaction.php` — modified (коллекция строк + доменные методы)
- `site/src/Cash/Service/Transaction/CashTransactionService.php` — modified (dual-write при создании и редактировании)
- `site/src/Cash/MessageHandler/ApplyAutoRulesForTransactionHandler.php` — modified (dual-write в воркере)
- `site/src/Cash/Controller/Transaction/CashTransactionAutoRuleController.php` — modified (dual-write при ручном применении правила)
- `site/tests/Unit/Cash/Entity/Transaction/CashTransactionSplitsTest.php` — new (13 тестов)
- `site/tests/Integration/Cash/Service/Transaction/CashTransactionSplitSynchronizerTest.php` — new (7 тестов)
- `site/tests/Integration/Cash/MessageHandler/ApplyAutoRulesForTransactionHandlerTest.php` — modified (новый аргумент конструктора)
- `site/tests/Unit/Cash/Controller/Transaction/CashTransactionAutoRuleControllerTest.php` — modified (новый аргумент действия)
- `ARCHITECTURE.md` — modified (Entity + раздел про разбивку)

### Отклонения от плана и почему

1. **Девятый writer, которого не было в карте плана.** `CashTransactionAutoRuleController::applyOne`
   применяет правило и делает собственный flush. Подключён к синхронизатору. Карта плана была
   неполной даже после того, как внешнее ревью её дополнило.
2. **Три импорта трогать не пришлось.** Они создают транзакцию без категории, а строки зеркалят
   колонку включая пустое состояние, поэтому инвариант держится по построению. План предполагал
   правки в трёх файлах — они оказались лишними.
3. **Инвариант «сумма строк = сумме транзакции» уточнён.** Он применяется к непустому составу;
   «нет категории → нет строк» — легальное зеркало, а не нарушение. Следствие для Stage 3:
   ведомость и экспорт обязаны использовать LEFT JOIN, иначе транзакция без категории пропадёт
   из выдачи. Записано в план как требование к D3/D4.
4. **Автодобор «Не распределено» перенесён в Stage 4.** В Stage 1 он не нужен: fallback на
   системную категорию уже делает воркер автоправил, и дублировать его в синхронизаторе означало бы
   менять наблюдаемое поведение импорта (колонка `NULL` превратилась бы в «Не распределено» раньше
   срока). Полноценный автодобор появится вместе с формой мультиразбивки.

### Баг, пойманный собственным тестом

Пересоздание строки с той же категорией падало на `uniq_cts_tx_category`: Doctrine выполняет INSERT
раньше DELETE в одном flush. Симптом воспроизводился на любом редактировании суммы транзакции.
Исправлено в корне — `replaceSplits()` переиспользует строку с совпадающей категорией
(`changeAmount()` / `changeSource()`), а не пересоздаёт её. Это же чинит будущую форму
мультиразбивки в Stage 4, где сохранение состава с частично совпадающими категориями — норма.

### Self-review

- [x] Scope compliance — читатели не тронуты, за границы Stage не выходили
- [x] Patterns / naming — `final class` у сервисов и команд, Entity без `final`, `declare(strict_types=1)` во всех новых файлах
- [x] Forbidden actions — none: нет `dump()`, нет `new Service()`, нет `flush()` в Repository, нет бизнес-логики в контроллере
- [x] Security — каждый метод `CashTransactionSplitRepository` принимает `string $companyId`; компания строки выводится из транзакции; категория из другой компании отвергается в конструкторе
- [x] Тесты — 20 новых, включая негативные на каждый инвариант
- [x] ARCHITECTURE.md обновлён

### External review

- Reviewer: Codex CLI 0.146.0, `codex exec -s read-only --ephemeral`
- Iterations: 5
- Result: **REVIEW_GREEN**
- Ограничения ревьюера: без доступа к шеллу и БД. Факты, которые он не мог добыть сам —
  версия PostgreSQL и наличие bcmath, срез прода, события `AuditLogSubscriber`, результаты
  прогонов — передавались в промпте. Дифф передавался через stdin.

**Подтверждённые находки, исправленные по итерациям:**

| Итерация | Уровень | Находка | Исправление |
|---|---|---|---|
| 1 | IMPORTANT | `source` затирался при каждой синхронизации: правка суммы помечала авто-категорию ручной | `source` меняется только вместе с категорией |
| 1 | IMPORTANT | Ранний выход по «есть ручная строка» оставлял колонку и строки рассинхронизированными | Синхронизация безусловна; защита ручной категоризации остаётся на уровне колонки |
| 1 | IMPORTANT | Ложный зелёный в verify: агрегаты компенсировали перепутанные категории | Проверка `expand_phase_mismatch` |
| 1 | IMPORTANT | Аудит терял первое назначение категории существующей транзакции | Подавляется только состав новой транзакции |
| 1 | IMPORTANT | bcmath scale 2 усекает, `NUMERIC(18,2)` округляет — «1.999» ложилось как «2.00» | Валидация точности |
| 1 | IMPORTANT | `clearSplits()` не проверял отсутствие категории | Проверка добавлена |
| 2 | BLOCKER | Конкурентный writer оставлял лишнюю строку, состав не самовосстанавливался | Предикат backfill совпал с `expand_phase_mismatch` |
| 2 | IMPORTANT | `source` первой строки legacy-транзакции брался из текущей операции | Восстановление через провенанс-резолвер |
| 2 | IMPORTANT | verify не проверял `source` | Проверка `unknown_source` + `CHECK`-constraint |
| 2 | IMPORTANT | Изменяемая коллекция наружу, мёртвый `changeSource()` | `getSplits()` отдаёт снимок, `changeSource()` удалён |
| 2 | MINOR | Фиксированный scale 6 пропускал «1.0000001» | Проверка канонического формата |
| 2 | MINOR | PHPDoc обещал вызов из импортов | Формулировка исправлена |
| 3 | BLOCKER | Самовосстановление работало в одну сторону: снятая категория с оставшейся строкой не подхватывалась | Предикат покрыл оба направления, добавлен `clearSplits()` |
| 3 | IMPORTANT | `resolveSource` не отличал изменение категории от операции над другим полем | Сравнение с `UnitOfWork::getOriginalEntityData` |
| 3 | IMPORTANT | Прямая мутация строки обходила агрегат | Guard на уровне Doctrine |
| 3 | IMPORTANT | backfill возвращал `SUCCESS` при незавершённом переносе | Пересчёт остатка → `FAILURE` |
| 3 | MINOR | `idx_cts_transaction` дублировал левый префикс уникального индекса | Индекс убран |
| 4 | IMPORTANT | Guard закрывал только `PreUpdate` и пропускал неинициализированную коллекцию владельца | `PrePersist` + `PreUpdate`, ранний выход убран, два теста обхода |
| 4 | MINOR | N+1 в командах | Батчевая загрузка транзакций |

**Отклонённые находки с обоснованием:**

- Итерация 1, BLOCKER про `clear()` + повторный `add()` на коллекции с `orphanRemoval`:
  опровергнут доказательно. Doctrine ORM 3.6.3, `PersistentCollection::add()` вызывает
  `cancelOrphanRemoval()`. Написаны два интеграционных теста с `flush` + `clear` + reload
  (правка суммы и смена категории) — оба зелёные. Замечание про пробел в тестах было верным,
  тесты остались в наборе. На ORM 2.x находка была бы верна.
- Итерация 3, первый сценарий искажения `source` (ручная смена категории A→B у legacy
  auto-категории): не воспроизводится. `latestChangeIsAutoAssigned` требует, чтобы
  `diff['changes'][field]['after']` совпал с текущим значением; после смены на B оно не совпадает,
  и провенанс возвращает «не авто». Второй сценарий той же находки был реален и исправлен.
- Итерация 2, требование сверять `source` с пересчитанным провенансом в verify: сначала отклонено
  как тавтология, на итерации 3 **отклонение снято** — довод ревьюера верен, сохранённое значение
  писал другой код в другой момент, поэтому сверка ловит дрейф. Проверка добавлена.
- Итерация 4, батчевая загрузка `AuditLog` в провенанс-резолвере: не делалось. Резолвер —
  существующий сервис, изменение его контракта выходит за scope Stage 1. Стоимость
  зафиксирована комментарием.

### Команды для проверки

- `docker compose run --rm site-php-cli vendor/bin/phpunit tests/Unit/Cash/Entity/Transaction/CashTransactionSplitsTest.php`
- `docker compose run --rm site-php-cli vendor/bin/phpunit tests/Integration/Cash/Service/Transaction/CashTransactionSplitSynchronizerTest.php`
- `make site-test`
- `docker compose run --rm site-php-cli php bin/console lint:container`
- `php bin/console app:cash:backfill-transaction-splits` / `--execute`
- `php bin/console app:cash:verify-transaction-splits`

### Результаты проверок

- **Полный прогон:** 2938 тестов, 15972 утверждения, **1 падение** —
  `DashboardSnapshotGoldenTest::testSnapshotGoldenValuesForCurrentMonthFromA22Fixtures`.
  Падение **предсуществующее и доказано**: изменения были убраны в stash, тест прогнан на
  `80ad3cfe` и упал ровно так же. Тест зависит от календарной даты (сегодня первое число месяца,
  окно «текущий месяц» пустое). К задаче отношения не имеет.
- **Новые тесты:** 32/32 зелёные; весь модуль Cash — 267/267.
- **`lint:container`:** зелёный.
- **CS:** baseline репозитория красный (десятки предсуществующих файлов в `src/Cash`).
  По изменённым файлам прогнан php-cs-fixer точечно — чисто; чужие нарушения не трогали.
- **Миграция:** применяется на локальной и тестовой БД; цикл `down` → `up` прогнан на тестовой БД.
- **Команды:** на локальной БД backfill перенёс 10 транзакций, verify зелёный по всем 8 проверкам
  и по итогам, повторный запуск backfill даёт 0 — идемпотентность подтверждена.

### Риски / на что обратить внимание ревьюеру

- Синхронизатор вызывается вручную из четырёх мест. Пятый writer, добавленный позже без вызова
  синхронизатора, разойдётся с колонкой молча. Это осознанный выбор в пользу явности против
  Doctrine `onFlush`-листенера; verify-команда — страховочная сеть.
- `CashTransactionSplit::__construct` инициализирует прокси категории ради проверки company.
  На единичных операциях это один лишний запрос; в массовых сценариях категория уже загружена.
- Проверки `amount_mismatch` и `single_split_category_mismatch` в verify рассчитаны на окно
  dual-write. После Stage 4 (мультиразбивка + проекция колонки в «Не распределено») их семантика
  изменится, команду придётся адаптировать.

### Открытые вопросы

- D4: питает ли XLSX-выгрузка 1С или внешнюю таблицу — влияет на реализацию 3.3.
- Оператор и wrapper для backfill/verify/backup на проде — определить до Production Gate A.
