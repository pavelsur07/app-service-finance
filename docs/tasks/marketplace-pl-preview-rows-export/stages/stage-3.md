# Stage 3: Exporter — «Покрытие», «Реклама», «Предупреждения»

stage_base_commit: `7aabfcb05`. Risk: LOW — плоский вид уже посчитанных блоков отчёта.

## 1. Какую задачу решаем

Потребителю нужно видеть рядом с суммами то, что в ОПиУ не попало или может не попасть,
расхождение рекламы «учёт / статистика» и предупреждения расчёта (ТЗ §4.3–4.5), тоже строками.

## 2. Что будет результатом

Три листа после «Паспорта», порядок листов — `Данные`, `Паспорт`, `Покрытие`, `Реклама`,
`Предупреждения`. Источник — отчёты каналов (не общий: иначе строки задвоятся), канал — в
каждой строке.

- `Покрытие`: `period_key, marketplace, section, status, category_code, category_name, rows_count, amount, has_active_mapping`.
  - `cost` ← `costCoverage`: `status` = `PlPreviewCostStatus`, код и название категории затрат
    (нет категории — пустые), `rows_count`, `amount` = `netAmount`;
  - `sales` ← `salesCoverage`: `status` = `PlPreviewSalesKind`, `category_name` — подпись вида,
    `has_active_mapping` 0/1;
  - `unplaced` ← `unplaced`: `status` = `PlPreviewUnplacedReason`, `category_name` — статья
    (удалена — «Статья удалена или недоступна», как в широкой выгрузке), `category_code` пуст (кода
    у таких статей в отчёте нет), `rows_count` пуст, `amount` = `values['total']`.
- `Реклама`: `period_key, marketplace, ads_ledger, ads_statistics, ads_on_listings, ads_unallocated, ads_difference`.
- `Предупреждения`: `period_key, marketplace, text`; пустой лист — только шапка.

Пустые числовые поля — пустые ячейки, не «0» и не пустая строка.

## 3. Что считаем выполненной работой

Листы пишутся по контракту; unit-тест экспорта проверяет их; integration-тест Action
подтверждает, что строки покрытия и рекламы каналов в сумме дают блоки общего отчёта
(= широкая выгрузка без канала, критерий приёмки 6); PHPStan и CS чистые.

## 4. Чек-лист приёмки

- [x] Пять листов в порядке ТЗ.
- [x] «Покрытие»: три секции, статусы — значения enum, суммы — числа, `has_active_mapping` только у `sales`.
- [x] `unplaced`: `rows_count` пуст, сумма из `total`.
- [x] «Реклама»: пять сумм по каналу числами.
- [x] «Предупреждения»: строка на предупреждение; без предупреждений — только шапка.
- [x] Покрытие и реклама каналов в сумме = блоки общего отчёта на `PlPreviewFixture`.
- [x] Названия категорий и тексты — `StringCell`.
- [x] PHPStan и CS по файлам без замечаний; тесты предпросмотра зелёные.

## Итог этапа

Сделано: листы «Покрытие», «Реклама», «Предупреждения» в `PlPreviewRowsXlsxExporter`; два unit-теста
экспорта (блоки и пустые листы), integration-тест `testChannelBlocksAddUpToAllReport` — затраты,
продажи и реклама каналов как мультимножество строк равны блокам общего отчёта, `costTotal`
каналов в сумме = общему. Чек-лист закрыт.

Проверки: `phpunit tests/Integration/Marketplace/PlPreview tests/Functional/Marketplace/Controller/PlPreviewControllerTest.php tests/Unit/Marketplace/Infrastructure/Export`
— OK, 49 tests / 220 assertions. PHPStan по трём файлам — No errors; php-cs-fixer — исправлен
порядок `use` в тесте, остальное 0.

Внутреннее ревью: источник — только отчёты каналов (без задвоения общим); тексты предупреждений и
названия — `StringCell` (XML листа без `<f>`); пустые числовые поля — пустые ячейки. BLOCKER/IMPORTANT нет.
