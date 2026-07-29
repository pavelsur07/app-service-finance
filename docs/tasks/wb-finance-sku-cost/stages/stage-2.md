### Stage 2: Историческая себестоимость и результат SKU — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously

#### Stage scope

- Stage base commit: `e601ddb2b1b914e6c34ab621eed80a1d204e2fc1`
- Work items completed: `2.1`, `2.2`, `2.3`, `2.4`

#### What was done

- Листинги и история себестоимости загружаются одним tenant-scoped DBAL
  запросом только для идентификаторов выбранного raw-отчёта.
- Источники `nmId + размер` и barcode сопоставляются консервативно: конфликт
  не разрешается молча.
- Несколько raw-групп одного листинга объединяются в одну строку SKU.
- Себестоимость продаж и возвратов рассчитывается на историческую дату;
  earliest fallback, missing, zero и non-RUB состояния остаются видимыми.
- Результат SKU рассчитывается только при полном покрытии себестоимостью.
- Добавлены сверочные итоги, coverage, unmapped/conflict и нераспределённое
  товарное `К перечислению`.
- Enricher подключён одинаково к HTML и CSV action.

#### Files changed

- `site/src/Marketplace/Application/Service/WbRawFinancialReportProductEnricher.php`
- `site/src/Marketplace/Infrastructure/Query/WbRawFinancialReportProductQuery.php`
- `site/src/Marketplace/Controller/WbRawFinancialReportController.php`
- `site/tests/Integration/Marketplace/Application/Service/WbRawFinancialReportProductEnricherTest.php`
- `docs/tasks/wb-finance-sku-cost/plan.md`
- `docs/tasks/wb-finance-sku-cost/checkpoint.md`

#### Definition of Done

- [x] Сопоставление пакетное и tenant-scoped.
- [x] Barcode/nmId conflict не скрывается.
- [x] Нет N+1 и чтения транзакционных таблиц.
- [x] Продажи и возвраты используют утверждённую цепочку дат.
- [x] Проданная, возвращённая и нетто-себестоимость рассчитаны.
- [x] Неполное покрытие не создаёт ложный результат.
- [x] Общие итоги являются суммой строк SKU.
- [x] Tenant isolation, границы дат, fallback, partial/missing cost,
  barcode-only и identity conflict покрыты тестами.

#### Checks

- targeted integration + functional: `OK (8 tests, 111 assertions)`.
- Marketplace bounded-context suite: `OK (786 tests, 5445 assertions)`.
- Symfony container lint: green.
- targeted PHP CS Fixer для 4 task-owned PHP-файлов: green.
- `git diff --check`: clean.
- full `make site-cs-check`: pre-existing repository failure, 582 из 2156
  файлов; task-owned PHP-файлы проходят тот же fixer config.

#### Internal automatic review

- Iterations: 4
- BLOCKER: none
- IMPORTANT fixed:
  - исключён полный scan каталога через индексируемый `UNION` candidate ids;
  - Money parse exceptions больше не подавляются;
  - checkpoint приведён к фактическому состоянию.
- MINOR fixed:
  - полный набор barcode для barcode-only match;
  - raw fallback для пустого артикула и названия каталога;
  - защищённое вычитание количества;
  - boundary dates и merge raw-групп закреплены тестами;
  - удалены лишние SQL-сортировки.
- FOLLOW-UP:
  - при экстремально больших каталогах рассмотреть chunking идентификаторов;
  - при длинной истории цен рассмотреть раздельные barcode/cost queries.

#### External Claude Code review

- Iterations: 4
- Result: `REVIEW_GREEN`
- Confirmed findings fixed:
  - индексный candidate lookup вместо `OR` поверх joined tables;
  - ошибки Money не превращаются молча в missing cost;
  - checkpoint актуален;
  - barcode lookup нормализован;
  - поздняя raw-группа дополняет пустые display metadata.
- Unresolved BLOCKER/IMPORTANT: none.

#### Risks / reviewer focus

- Fallback-priced quantity считается покрытой, но отдельно видна в
  `fallback_cost_quantity`; Stage 3 обязан показать этот признак.
- Результат — до логистики, хранения, штрафов и прочих нетоварных расходов.
- Нераспределённые корректировки `forPay` показываются отдельно.

#### Checkpoint

- `docs/tasks/wb-finance-sku-cost/checkpoint.md` updated.
- Exact next action: commit/push Stage 2, then start Stage 3.

#### Open questions

- none

#### Expected owner response

- not required; continuing autonomously
