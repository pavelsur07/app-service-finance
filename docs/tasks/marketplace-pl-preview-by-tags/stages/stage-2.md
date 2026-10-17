# Stage 2: расчёт дерева ОПиУ по колонкам (Finance Facade)

stage_base_commit: `cbb8ca525`

## План этапа

### Что нужно

Marketplace (этапы 3–5) соберёт суммы по статьям ОПиУ для каждой колонки отчёта (тег, «Общие /
без тега», «Итого»). Finance должен превратить их в строки ОПиУ ровно так, как это сделал бы
реальный документ `marketplace_pl`, и посчитать дерево (подытоги, формулы, KPI) — ничего не
записывая.

### Как считает реальный ОПиУ (прочитано в коде)

1. `FinanceFacade::createPLDocument` — сумма строки со знаком: `is_negative` → «−».
2. `PLRegisterUpdater` для `marketplace_pl` складывает суммы статьи со знаком, от итога берёт
   модуль; природа (`PLCategory::nature()` по flow) решает, доход это или расход. У статьи без
   flow природа — по знаку суммы.
3. `PLDailyTotalFactsProvider` — факт статьи = доход − расход, ищется по `code` статьи.
4. `PlReportCalculator` берёт факты только у `LEAF_INPUT` по `code`; подытоги и KPI считает сам.

Следствие: сумма на статье, которая не `LEAF_INPUT` или без `code`, в регистр попадает, но в отчёт
ОПиУ — нет. Предпросмотр показывает такие суммы отдельно, а не теряет молча.

### Решения

1. **Вход** — `PlPreviewColumn(key, amountsByCategoryId)`: суммы со знаком документа
   `marketplace_pl` (после `is_negative`), строки bcmath. Знак и природу применяет Finance —
   это его соглашение, Marketplace его не повторяет.
2. **Правило природы** (по колонке и статье, S — сумма со знаком): flow INCOME → `+|S|`, flow
   EXPENSE → `−|S|`, без flow → `S`.
3. **Расчёт дерева** — существующий `PlReportCalculator`. ~~Необязательный параметр
   `calculate(..., ?FactsProviderInterface $facts)`~~ — отклонено при реализации: новые строки
   выше анонимного класса `Env` меняют его имя (`…PlReportCalculator.php:NN`), 6 записей baseline
   перестают совпадать, а типизация класса тянет каскад новых ошибок. Вместо этого —
   `withFacts(FactsProviderInterface): self` в конце класса: тот же калькулятор с другим
   источником фактов. Поведение обычного ОПиУ не меняется.
4. **Факты из памяти** — `App\Finance\Facts\InMemoryFactsProvider` (`final readonly`, массив
   «код → значение», сравнение кода без учёта регистра, как в калькуляторе).
5. **Сервис** — `App\Finance\Report\PlPreviewCalculator`: группирует суммы, применяет правило
   природы, раскладывает «непопавшие» суммы, вызывает калькулятор по колонке, сводит строки.
   Калькулятор читает дерево на каждый вызов — как `PlReportGridBuilder` по периодам; в пределах
   запроса сущности приходят из identity map. Число колонок = число тегов + 2. Это отступление
   от строки «дерево загружается один раз» общего плана; обоснование — повторное использование
   калькулятора без переписывания.
6. **Непопавшие суммы** — `PlPreviewUnplacedAmount(categoryId, categoryName?, reason, values)`,
   причина — enum `PlPreviewUnplacedReason`: `category_not_found` (статьи нет в компании —
   удалена или чужая), `not_leaf_input`, `no_code`.
7. **Выход** — `PlPreviewResult(rows, unplaced, warnings)`, строка — `PlPreviewRow(id, code,
   name, level, type, format, values[columnKey => float])`.
8. **Facade** — `FinanceFacade::calculatePlPreview(string $companyId, \DateTimeImmutable $from,
   \DateTimeImmutable $to, list<PlPreviewColumn> $columns): PlPreviewResult`. Компания —
   `getReference`; дерево и статьи берутся только своей компании (`findBy company`), поэтому
   чужой `categoryId` уходит в `category_not_found`. Пустой список колонок —
   `\InvalidArgumentException` (ошибка программиста). DTO — в `Finance/Application/DTO`
   (тип контракта Facade).

### Конечный результат

Marketplace может вызвать один метод Facade с суммами по колонкам и получить дерево ОПиУ со
значениями по каждой колонке и список сумм, которые не попадут в отчёт. В БД ничего не пишется.

### Чек-лист

- [x] Unit `PlPreviewCalculatorTest`: правило природы (INCOME / EXPENSE / без flow, включая
      сторно больше начислений), подытог и KPI по колонкам, независимость колонок, три причины
      непопавших сумм, пустой список колонок → исключение.
- [x] Unit `PlReportCalculatorTest`: `withFacts()` — переданный провайдер используется, внедрённый не
      вызывается; старые тесты калькулятора зелёные.
- [x] Unit `InMemoryFactsProviderTest`: код без учёта регистра, неизвестный код → 0.
- [x] Functional/integration на Facade: компания с деревом в БД, чужая статья → `category_not_found`.
- [x] Ни `persist`, ни `flush` в новом коде.
- [x] PHPStan / CS по изменённым файлам чистые.
- [x] `ARCHITECTURE.md`: метод Facade, DTO, enum, правило природы; Changelog.

## Stage Report — DONE

**Сделано.** `FinanceFacade::calculatePlPreview()`; `Report\PlPreviewCalculator` (правило природы,
непопавшие суммы, сведение колонок); `Facts\InMemoryFactsProvider`; `PlReportCalculator::withFacts()`;
DTO `PlPreviewColumn`, `PlPreviewRow`, `PlPreviewUnplacedAmount`, `PlPreviewResult`; enum
`PlPreviewUnplacedReason`.

**Решение по ходу.** Найдено при тестах: базовое дерево (`config/finance/default_pl_tree.json`)
хранит расходы отрицательными фактами и складывает их формулами `CM1 = REV_TOTAL + COGS_TOTAL`;
фикстура `MiniTreeFactory` вычитает. В тесте предпросмотра формула маржи приведена к реальному
соглашению, фикстура не менялась.

**Проверки.**
- `phpunit tests/Unit/Finance tests/Integration/Finance/FinanceFacadePlPreviewTest.php` — OK
  (157 tests / 564 assertions).
- Integration: дерево своей компании, чужая статья → `category_not_found` без названия,
  `documents` и `pl_daily_totals` пусты.
- php-cs-fixer — исправлено форматирование phpdoc, повторно чисто; PHPStan по 16 файлам — exit 0,
  baseline не тронут.

**Внутреннее ревью.** Статьи берутся только своей компании; запись — нет (`persist`/`flush`
отсутствуют); ключи колонок уникальны; формат и тип строки — enum'ы контракта. BLOCKER/IMPORTANT: 0.
MINOR: тело калькулятора читает дерево на каждую колонку — принято (решение 5).
