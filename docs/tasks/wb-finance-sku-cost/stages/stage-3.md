### Stage 3: HTML, CSV и финальный Release Gate — DONE

**Risk:** MEDIUM
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes

#### Stage scope

- Stage base commit: `92cccfae0a9d00b7b6302178c4693c445a464bc9`
- Work items completed: `3.1`, `3.2`, `3.3`, `3.4`, `3.5`

#### What was done

- После расшифровки удержаний и перед `reportId` добавлен блок «Товары и
  себестоимость».
- Summary показывает SKU, проданное/возвращённое/нетто-количество, суммы без
  и с СПП, товарное перечисление, себестоимость, coverage и результат.
- Каждая строка SKU показывает идентификаторы, mapping status, продажи,
  возвраты, `forPay`, себестоимость, missing/fallback и результат.
- Результат обозначен как результат до общих расходов WB, а не чистая прибыль.
- Неполное покрытие скрывает недостоверный результат; negative, missing,
  partial, fallback, conflict и unmapped состояния различимы.
- Рентабельность явно определена относительно нетто-продаж без СПП.
- CSV содержит тот же summary и полный 29-колоночный SKU-раздел.
- Внешние product strings защищены от spreadsheet formula injection.
- Размер `UNKNOWN` показывается пользователю как отсутствие размера.

#### Files changed

- `site/templates/marketplace/wb_finance_report.html.twig`
- `site/src/Marketplace/Controller/WbRawFinancialReportController.php`
- `site/tests/Functional/Marketplace/Controller/WbRawFinancialReportControllerTest.php`
- `site/src/Marketplace/WB_API_V5_FIELDS.md`
- `docs/tasks/wb-finance-sku-cost/plan.md`
- `docs/tasks/wb-finance-sku-cost/checkpoint.md`

#### Definition of Done

- [x] Блок расположен после удержаний и перед `reportId`.
- [x] Общая сводка и строки каждого SKU используют один read model.
- [x] Продажи/возвраты, без/с СПП, `forPay`, cost и result видимы.
- [x] Missing, negative, fallback, partial, conflict и empty states видимы.
- [x] Результат не назван чистой прибылью.
- [x] CSV повторяет значения HTML и защищает внешние строки.
- [x] Сверка продаж и нераспределённый `forPay` видимы.
- [x] Формулы и caveats документированы.

#### Checks

- targeted integration + functional: `OK (10 tests, 173 assertions)`.
- controller functional: `OK (7 tests, 107 assertions)`.
- Marketplace bounded-context suite: `OK (788 tests, 5509 assertions)`.
- full unit suite: `OK (1645 tests, 9560 assertions)`.
- Twig lint: green.
- Symfony container lint: green.
- targeted PHP CS Fixer: green.
- `git diff --check`: clean.
- full `make site-cs-check`: pre-existing repository failure, 582 из 2156
  файлов; task-owned PHP-файлы проходят тот же fixer config.

#### Internal automatic review

- Iterations: 3
- BLOCKER: none
- IMPORTANT fixed:
  - база рентабельности определена и названа как нетто-продажи без СПП.
- MINOR fixed:
  - sales reconciliation видна в HTML;
  - alerts доступны и при пустом SKU-наборе;
  - status labels имеют один источник;
  - проценты локализованы, footer и KPI grid сделаны читаемыми;
  - payout label отделяет общую сумму от суммы по SKU;
  - counts и data attributes форматируются безопасно;
  - fallback/partial/conflict/unallocated и полная CSV-строка покрыты тестами.
- FOLLOW-UP:
  - unbounded SKU table требует отдельного owner-решения о pagination/top-N;
  - позиционные CSV-consumers должны учитывать новый обязательный раздел.

#### External Claude Code review

- Iterations: 3
- Final result: `REVIEW_GREEN`
- Unresolved BLOCKER/IMPORTANT/MINOR: none.

#### Risks / reviewer focus

- Результат не включает логистику, хранение, штрафы и другие нетоварные
  расходы.
- Fallback считается покрытием, но его количество явно показано.
- Возвраты / продажи — отношение внутри выбранного периода и может быть выше
  100%.
- Финансовые транзакции не читаются и не изменяются.

#### Release Gate

- Stage готов к commit/push и обновлению Draft PR #2257.
- После финального full-task review требуется решение владельца: переводить ли
  Draft PR в Ready и мержить в `master`.
- Production Gate в scope не входит.
