Микро план
1. доработка категорий ДДС (flow_kind/is_system/code + миграция + формы)
2. признак “переменные расходы” в структуре ОПиУ
3. snapshot endpoint /api/dashboard/v1/snapshot (DTO + сервис расчёта + кэш)
4. React DashboardGrid на Tabler (виджеты + skeleton/error/empty)

# Dashboard v1 — Structure & Logic (Ваш Финдир) ✅ FIXED


## 0) Цель и принципы
Dashboard v1 = управленческая витрина на базе факта:
- Cash/flow — по ДДС (confirmed transactions)
- Выручка/прибыль — по ОПиУ документам (confirmed, по отгрузке)
- Мультивалюты нет
- Фонды не входят в cash (показываем Free Cash)
- Любая цифра воспроизводима (drill-down в отчёт/список)

Источник истины:
- KPI считаются на backend (Application/Domain)
- Frontend (React + Tabler) только отображает + тренды

---

## 1) Глобальный контекст (filters)
Единый фильтр для всех виджетов:
- period:
    - preset: day / week / month / quarter / year
    - custom: date_from, date_to
- company: active company (server-side)
- vat_mode (setting): include_vat = true/false (на уровне компании, влияет на ОПиУ)
- comparison: previous_period (автоматически)

Правило previous_period:
- days = (date_to - date_from + 1)
- prev_from = date_from - days
- prev_to   = date_from - 1 day

---

## 2) Layout (Tabler grid)
Row 1 (KPI cards):
- Free Cash
- Inflow
- Outflow (+ CapEx)
- Revenue

Row 2:
- Cash Flow Split (Operating / Investing / Financing / Total)
- Profit Snapshot

Row 3:
- Top Expenses (Cash / Pareto 80%)
- Top Expenses (P&L / Pareto 80%)

Row 4 (full width):
- Alerts

---

## 3) Общие правила данных (quality gates)
ДДС:
- учитываем только transactions со статусом CONFIRMED
- исключаем internal transfers из KPI inflow/outflow/flow_split
- возвраты включаем как inflow ТОЛЬКО по кодам категорий (см. раздел “Category Codes”)

ОПиУ:
- учитываем только документы со статусом CONFIRMED
- выручка по отгрузке (по документам)
- VAT учитывается по настройке компании include_vat

Фонды:
- не входят в cash
- участвуют в расчёте Free Cash как “резервы/зарезервировано”

---

## 4) Category Codes (фиксируем чтобы не было “зоопарка”)
Для структуры ДДС вводим обязательные поля у категории:
- flow_kind: OPERATING | INVESTING | FINANCING
- is_system: bool
- code: string (константа)

Список обязательных кодов (v1 минимум):
- INTERNAL_TRANSFER (для исключения из KPI)
- REFUND_SUPPLIER
- REFUND_TAX
- REFUND_PAYROLL
- CAPEX (или набор CAPEX_* по типам)
- LOAN_PRINCIPAL_IN / LOAN_PRINCIPAL_OUT (если нужно для FINANCING)
- OWNER_CONTRIBUTION_IN / OWNER_WITHDRAWAL_OUT (если нужно для FINANCING)

Правило для inflow:
- inflow включает возвраты ТОЛЬКО если category.code in REFUND_* codes
- любые прочие “возвраты” не считаем, пока нет кода

---

## 5) Widgets

### Widget 1 — Free Cash (Остаток / свободные деньги)
Показываем:
- value: free_cash_at_end
- delta_abs: vs period_start
- delta_pct: vs previous_period_end
- last_updated_at

Формулы:
- cash_at_end = Σ balance(account, date_to)
- reserved_at_end = Σ reserved_funds(date_to)
- free_cash_at_end = cash_at_end - reserved_at_end

- free_cash_at_start = cash_at_start - reserved_at_start
- delta_abs = free_cash_at_end - free_cash_at_start

- free_cash_prev_end = cash_prev_end - reserved_prev_end
- delta_pct = (free_cash_at_end - free_cash_prev_end) / max(|free_cash_prev_end|, 1) * 100%

Drill-down:
- Отчёт “Остатки и счета” (date_to)

---

### Widget 2 — Inflow (Поступило денег за период)
Показываем:
- inflow_sum
- delta_abs vs previous_period
- avg_daily
- sparkline: daily inflow

Фильтр транзакций:
- confirmed
- amount > 0
- internal_transfer = false
- включаем возвраты по коду: REFUND_* (это тоже inflow)

Формула:
- inflow_sum = Σ tx.amount (filters) where tx.date in [from..to]
- avg_daily = inflow_sum / days

Drill-down:
- Отчёт ДДС: фильтр “inflow”

---

### Widget 3 — Outflow (Расход денег за период + CapEx)
Показываем:
- outflow_sum_abs
- delta_abs vs previous_period
- ratio_to_inflow
- avg_daily
- capex_sum_abs (отдельно)

Фильтр:
- confirmed
- amount < 0
- internal_transfer = false
- включаем все виды потоков (operating + investing + financing)

Формулы:
- outflow_sum_abs = |Σ tx.amount|
- ratio_to_inflow = outflow_sum_abs / max(inflow_sum, 1)
- avg_daily = outflow_sum_abs / days

CapEx:
- capex_sum_abs = |Σ tx.amount where category.code in CAPEX_CODES|

Drill-down:
- Outflow → отчёт ДДС “outflow”
- CapEx → ДДС “investing/capex”

---

### Widget 4 — Cash Flow Split (Operating/Investing/Financing + Total)
Показываем:
- net_operating + delta_abs
- net_investing + delta_abs
- net_financing + delta_abs
- net_total

Фильтр:
- confirmed
- internal_transfer = false
- распределение по category.flow_kind

Формулы:
- net(kind) = Σ tx.amount where flow_kind=kind
- delta_abs(kind) = net(kind) - net_prev(kind)
- net_total = net(OP) + net(INV) + net(FIN)

Drill-down:
- клик по строке → ДДС с фильтром по виду потока

---

### Widget 5 — Revenue (ОПиУ, по отгрузке)
Показываем:
- revenue_sum
- delta_abs vs previous_period
- sparkline (по неделям/дням)

Источник:
- confirmed P&L docs, revenue lines
- VAT: include_vat setting
- возвраты уменьшают выручку, если отражены в документах

Формула:
- revenue_sum = Σ revenue_lines(amount_net_or_gross)

Drill-down:
- Отчёт ОПиУ: детализация по документам

---

### Widget 6 — Top Expenses (Cash / Pareto 80%)
Источник:
- ДДС outflow (confirmed, no internal transfers)

Логика:
- группировка по категории: sum_abs
- сортировка desc
- берём top до покрытия >=80% или max N
- остальное в “Прочее”
- считаем delta_abs и индикатор роста

Drill-down:
- категория → список транзакций

---

### Widget 7 — Top Expenses (P&L / Pareto 80%)
Источник:
- ОПиУ confirmed docs: expense lines (по структуре ОПиУ)

Логика Pareto аналогично.

Drill-down:
- категория → ОПиУ детализация

---

### Widget 8 — Profit Snapshot (ОПиУ, accrual) ✅
Показываем:
- Revenue
- Variable Costs (переменные расходы)
- Gross Profit
- OPEX (кроме переменных расходов)
- EBITDA (управленческий аналог)
- Margin %

Ключевое правило (фиксируем):
- OPEX = все расходы ОПиУ, кроме переменных расходов
- Переменные расходы должны иметь признак/группу в структуре ОПиУ (например `is_variable = true` или group_code)

Формулы (v1):
- Revenue = Σ revenue lines
- VariableCosts = Σ expense lines where is_variable=true
- GrossProfit = Revenue - VariableCosts
- OPEX = Σ expense lines where is_variable=false (и не фин.расходы/налоги/амортизация если они отдельно)
- EBITDA = GrossProfit - OPEX
- Margin% = EBITDA / max(Revenue,1) * 100%

Drill-down:
- по строке → ОПиУ с предфильтром

---

### Widget 9 — Alerts (v1 фактовые)
Показываем максимум 5:
- Negative Operating CF (net(OP) < 0)
- Free Cash down vs prev_end (delta_pct < 0)
- Outflow > Inflow (ratio_to_inflow > 1)
- Revenue down vs prev (revenue_delta_abs < 0)
- Margin down vs prev (margin% ниже чем в prev period)

Каждый alert:
- type, title, short reason, link

---

## 6) Backend API (v1)
Рекомендуемый вариант: один snapshot endpoint.

GET /api/dashboard/v1/snapshot?from=YYYY-MM-DD&to=YYYY-MM-DD

Response:
- context: {from,to,prev_from,prev_to,vat_mode,last_updated_at}
- widgets: { free_cash, inflow, outflow, cashflow_split, revenue, top_cash, top_pnl, profit, alerts }

Каждый widget:
- values + deltas
- series (optional)
- drilldown_key (UI routing)

---

## 7) Производительность и кэш
- cache key: company_id + from + to + vat_mode
- TTL 60–300 сек
- last_updated_at = max(updated_at) среди:
    - cash confirmed transactions
    - pnl confirmed documents
    - fund reservations

---

## 8) Open TODO (обязательные)
1) ДДС категории: добавить flow_kind / is_system / code + константы кодов
2) ОПиУ: добавить признак переменных расходов (is_variable) или group_code для строк
3) Настройка компании: include_vat (для ОПиУ)
4) Drill-down маршруты: ключи и страницы-цели (список)

