### Stage 3: Reports — DONE

**Risk:** MEDIUM · **База:** `feat/ds-rollout-base` (#2625)

#### Страницы
`report/{account_balances,account_balances_structured,cashflow,cashflow_ops_check,debug_daily_facts,debug_transactions_raw,transactions_debug,transactions_statement}`.

#### Что сделано
- 27 ячеек остатков и оборотов — `vf_amount` механической заменой; остальное вручную.
- ДДС: макрос суммы — приход зелёный, отрицательное — основной цвет с U+2212, знак
  только у отрицательных (лист: в остатках и итогах); KPI сверки с дашбордом — полная
  сумма `vf_money` с символом валюты; `data-value` с сырым числом не тронут.
- Выписка и отладочные списки операций: приход — зелёный с плюсом, расход — с U+2212,
  без красного; карточки сальдо/прихода/расхода — `vf_amount`.
- Расхождения сверки (`Δ`, «alert-octagon») остаются красными — это сигнал ошибки;
  число в них — `vf_number(2)`.

#### Тесты
- Ожидания под формат DS: `ReportAccountBalancesStructuredControllerTest`,
  `AccountBalancesOpeningBalanceTest`, `CashflowJsonExportControllerTest` (без плюса,
  класс прихода) — проверки данных те же.
- `phpunit tests/Functional/Finance tests/Functional/Cash tests/Functional/Api` ✅ 222;
  `lint:twig` ✅; PHPStan/cs-fixer ✅.
- smoke: остатки, остатки по структуре, ДДС, проверка операций, выписка — 200.
  Отладочные шаблоны без роутов — только `lint:twig`.
