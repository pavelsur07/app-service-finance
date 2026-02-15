только список эндпоинтов и назначение (как оглавление).
| Endpoint                                                           | Используется в                                     | Назначение                                | Query params (минимум)                                        | Response (ключи/структура)                                                                                                                                |                                                   |      |                       |                                         |
| ------------------------------------------------------------------ | -------------------------------------------------- | ----------------------------------------- | ------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------- | ---- | --------------------- | --------------------------------------- |
| **GET `/api/dashboard/v1/snapshot`**                               | **DashboardGrid v1**                               | Полный snapshot всех виджетов за период   | `from`, `to`                                                  | `context{from,to,prev_from,prev_to,vat_mode,last_updated_at}` + `widgets{free_cash,inflow,outflow,cashflow_split,revenue,top_cash,top_pnl,profit,alerts}` |                                                   |      |                       |                                         |
| GET `/api/cash/v1/transactions`                                    | Drill-down: Inflow/Outflow/CapEx/FlowSplit/TopCash | Список транзакций ДДС с фильтрами         | `from`,`to`,`page`,`per_page`; optional: `direction=in        | out`, `exclude_transfers=1`, `category_id`, `system_code`, `flow_kind`                                                                                    | `items[]` (tx rows) + `meta{page,per_page,total}` |      |                       |                                         |
| GET `/api/cash/v1/balances`                                        | Drill-down: Free Cash → “Остатки и счета”          | Остатки на дату по счетам/итого           | `at` (date)                                                   | `at`, `total_cash`, `accounts[]` (id,name,balance) *(фонды не включать)*                                                                                  |                                                   |      |                       |                                         |
| GET `/api/funds/v1/reserved` *(или `/api/cash/v1/funds/reserved`)* | Расчёт/Drill-down: Free Cash                       | Зарезервировано в фондах/резервах на дату | `at` (date)                                                   | `at`, `reserved_total`, `funds[]` (id,name,reserved)                                                                                                      |                                                   |      |                       |                                         |
| GET `/api/pl/v1/documents`                                         | Drill-down: Revenue/ProfitSnapshot                 | Список документов ОПиУ за период          | `from`,`to`,`page`,`per_page`; optional: `type=revenue        | expense                                                                                                                                                   | cogs                                              | opex | variable`, `vat_mode` | `items[]` + `meta{page,per_page,total}` |
| GET `/api/pl/v1/report`                                            | Drill-down: Profit Snapshot строки                 | Агрегированный ОПиУ (структура + суммы)   | `from`,`to`; optional: `vat_mode`                             | `lines[]` (category totals), `totals{revenue,cogs,variable,opex,ebitda,margin_pct}`                                                                       |                                                   |      |                       |                                         |
| GET `/api/cash/v1/categories`                                      | Filters, Drill-down mapping                        | Справочник категорий ДДС                  | optional: `tree=1`, `only_active=1`, `flow_kind`, `is_system` | `items[]` (flat) или `tree[]` (если `tree=1`)                                                                                                             |                                                   |      |                       |                                         |
| GET `/api/pl/v1/categories`                                        | Filters, Profit Snapshot mapping                   | Справочник категорий ОПиУ                 | optional: `tree=1`, `only_active=1`, `is_variable`            | `items[]` или `tree[]`                                                                                                                                    |                                                   |      |                       |                                         |
| GET `/api/company/v1/settings`                                     | Snapshot context                                   | Настройки влияющие на расчёты             | —                                                             | `vat_mode` (+ позже: timezone, currency)                                                                                                                  |                                                   |      |                       |                                         |
| *(опц.)* GET `/api/dashboard/v1/presets`                           | UI                                                 | Список preset периодов                    | —                                                             | `presets[]` (day/week/month/quarter/year)                                                                                                                 |                                                   |      |                       |                                         |

Маппинг “виджет → drilldown endpoint”
Free Cash → /api/cash/v1/balances?at=... + /api/funds/v1/reserved?at=...
Inflow → /api/cash/v1/transactions?direction=in&exclude_transfers=1&from..to
Outflow → /api/cash/v1/transactions?direction=out&exclude_transfers=1&from..to
CapEx → /api/cash/v1/transactions?direction=out&system_code=CAPEX&from..to
Cash Flow Split → /api/cash/v1/transactions?flow_kind=OPERATING|INVESTING|FINANCING&exclude_transfers=1&from..to
Revenue → /api/pl/v1/documents?type=revenue&from..to
Profit Snapshot → /api/pl/v1/report?from..to (и/или /api/pl/v1/documents?type=...)
Top Expenses Cash → /api/cash/v1/transactions?category_id=...&direction=out...
Top Expenses P&L → /api/pl/v1/documents?type=expense&category_id=...


# Dashboard v1 API Contract (FIXED)

Этот раздел фиксирует **контракт API** для Dashboard v1 (Ваш Финдир).
KPI считаются на backend; фронт только отображает и делает drill-down.

## Общие определения типов

- `uuid`: строка UUID v4
- `date`: строка `YYYY-MM-DD`
- `datetime`: строка ISO-8601 `YYYY-MM-DDTHH:MM:SSZ`
- `money`: number (в базовой валюте компании, v1 без мультивалюты)
- `pct`: number (проценты, например `12.3`)
- `pp`: number (percentage points, например `-1.2`)
- `trend`: `"up" | "down" | "flat"`

## Контекст периода (previous_period)

`days = (to - from + 1)`  
`prev_from = from - days`  
`prev_to   = from - 1 day`

---

## 1) GET `/api/dashboard/v1/snapshot`

### Назначение
Вернуть полный snapshot Dashboard v1 за период: KPI + блоки + алерты.

### Query
- `from` (date, required)
- `to` (date, required)

### Response
- `context` — параметры расчёта и сравнения
- `widgets` — данные всех виджетов (включая drilldown key/params)

### Response JSON (shape)

```json
{
  "context": {
    "company_id": "uuid",
    "from": "YYYY-MM-DD",
    "to": "YYYY-MM-DD",
    "days": 30,
    "prev_from": "YYYY-MM-DD",
    "prev_to": "YYYY-MM-DD",
    "vat_mode": "include|exclude",
    "last_updated_at": "YYYY-MM-DDTHH:MM:SSZ"
  },
  "widgets": {
    "free_cash": {
      "value": 0,
      "delta_abs": 0,
      "delta_pct": 0,
      "cash_at_end": 0,
      "reserved_at_end": 0,
      "last_updated_at": "YYYY-MM-DDTHH:MM:SSZ",
      "drilldown": {
        "key": "cash.balances",
        "params": { "at": "YYYY-MM-DD" }
      }
    },

    "inflow": {
      "sum": 0,
      "delta_abs": 0,
      "delta_pct": 0,
      "avg_daily": 0,
      "series": [
        { "date": "YYYY-MM-DD", "value": 0 }
      ],
      "drilldown": {
        "key": "cash.transactions",
        "params": {
          "from": "YYYY-MM-DD",
          "to": "YYYY-MM-DD",
          "direction": "in",
          "exclude_transfers": true
        }
      }
    },

    "outflow": {
      "sum_abs": 0,
      "delta_abs": 0,
      "delta_pct": 0,
      "avg_daily": 0,
      "ratio_to_inflow": 0,
      "capex_abs": 0,
      "series": [
        { "date": "YYYY-MM-DD", "value_abs": 0 }
      ],
      "drilldown": {
        "key": "cash.transactions",
        "params": {
          "from": "YYYY-MM-DD",
          "to": "YYYY-MM-DD",
          "direction": "out",
          "exclude_transfers": true
        }
      },
      "capex_drilldown": {
        "key": "cash.transactions",
        "params": {
          "from": "YYYY-MM-DD",
          "to": "YYYY-MM-DD",
          "direction": "out",
          "exclude_transfers": true,
          "system_code": "CAPEX"
        }
      }
    },

    "cashflow_split": {
      "operating": { "net": 0, "delta_abs": 0, "delta_pct": 0 },
      "investing": { "net": 0, "delta_abs": 0, "delta_pct": 0 },
      "financing": { "net": 0, "delta_abs": 0, "delta_pct": 0 },
      "total": { "net": 0 },
      "drilldown": {
        "key": "cash.transactions",
        "params": {
          "from": "YYYY-MM-DD",
          "to": "YYYY-MM-DD",
          "exclude_transfers": true
        }
      },
      "drilldowns_by_kind": {
        "OPERATING": {
          "key": "cash.transactions",
          "params": {
            "from": "YYYY-MM-DD",
            "to": "YYYY-MM-DD",
            "flow_kind": "OPERATING",
            "exclude_transfers": true
          }
        },
        "INVESTING": {
          "key": "cash.transactions",
          "params": {
            "from": "YYYY-MM-DD",
            "to": "YYYY-MM-DD",
            "flow_kind": "INVESTING",
            "exclude_transfers": true
          }
        },
        "FINANCING": {
          "key": "cash.transactions",
          "params": {
            "from": "YYYY-MM-DD",
            "to": "YYYY-MM-DD",
            "flow_kind": "FINANCING",
            "exclude_transfers": true
          }
        }
      }
    },

    "revenue": {
      "sum": 0,
      "delta_abs": 0,
      "delta_pct": 0,
      "series": [
        { "date": "YYYY-MM-DD", "value": 0 }
      ],
      "drilldown": {
        "key": "pl.documents",
        "params": {
          "from": "YYYY-MM-DD",
          "to": "YYYY-MM-DD",
          "type": "revenue",
          "vat_mode": "include|exclude"
        }
      }
    },

    "top_cash": {
      "coverage_target": 0.8,
      "max_items": 8,
      "items": [
        {
          "category_id": "uuid",
          "category_name": "string",
          "sum_abs": 0,
          "share": 0,
          "delta_abs": 0,
          "delta_pct": 0,
          "trend": "up|down|flat",
          "drilldown": {
            "key": "cash.transactions",
            "params": {
              "from": "YYYY-MM-DD",
              "to": "YYYY-MM-DD",
              "direction": "out",
              "exclude_transfers": true,
              "category_id": "uuid"
            }
          }
        }
      ],
      "other": {
        "label": "Прочее",
        "sum_abs": 0,
        "share": 0
      }
    },

    "top_pnl": {
      "coverage_target": 0.8,
      "max_items": 8,
      "items": [
        {
          "category_id": "uuid",
          "category_name": "string",
          "sum": 0,
          "share": 0,
          "delta_abs": 0,
          "delta_pct": 0,
          "trend": "up|down|flat",
          "drilldown": {
            "key": "pl.documents",
            "params": {
              "from": "YYYY-MM-DD",
              "to": "YYYY-MM-DD",
              "type": "expense",
              "category_id": "uuid",
              "vat_mode": "include|exclude"
            }
          }
        }
      ],
      "other": {
        "label": "Прочее",
        "sum": 0,
        "share": 0
      }
    },

    "profit": {
      "revenue": 0,
      "variable_costs": 0,
      "gross_profit": 0,
      "opex": 0,
      "ebitda": 0,
      "margin_pct": 0,
      "delta": {
        "ebitda_abs": 0,
        "margin_pp": 0
      },
      "drilldowns": {
        "revenue": {
          "key": "pl.documents",
          "params": {
            "type": "revenue",
            "from": "YYYY-MM-DD",
            "to": "YYYY-MM-DD",
            "vat_mode": "include|exclude"
          }
        },
        "variable_costs": {
          "key": "pl.documents",
          "params": {
            "type": "variable",
            "from": "YYYY-MM-DD",
            "to": "YYYY-MM-DD",
            "vat_mode": "include|exclude"
          }
        },
        "opex": {
          "key": "pl.documents",
          "params": {
            "type": "opex",
            "from": "YYYY-MM-DD",
            "to": "YYYY-MM-DD",
            "vat_mode": "include|exclude"
          }
        },
        "report": {
          "key": "pl.report",
          "params": {
            "from": "YYYY-MM-DD",
            "to": "YYYY-MM-DD",
            "vat_mode": "include|exclude"
          }
        }
      }
    },

    "alerts": {
      "items": [
        {
          "type": "NEG_OPER_CF|FREE_CASH_DOWN|OUTFLOW_GT_INFLOW|REV_DOWN|MARGIN_DOWN",
          "severity": "info|warning|danger",
          "title": "string",
          "reason": "string",
          "value": 0,
          "drilldown": {
            "key": "cash.transactions|pl.report|pl.documents",
            "params": {}
          }
        }
      ]
    }
  }
}
````

---

## 2) Drill-down endpoints (JSON shapes)

### 2.1) GET `/api/cash/v1/transactions`

#### Назначение

Список транзакций ДДС для drill-down из виджетов: inflow/outflow/capex/flow_split/top_cash.

#### Query (минимум)

* `from` (date, required)
* `to` (date, required)
* `page` (int, default 1)
* `per_page` (int, default 25)

#### Query (optional filters)

* `direction` = `in|out` (по знаку amount)
* `exclude_transfers` = `true|false` (default true для dashboard drilldown)
* `category_id` (uuid)
* `system_code` (string)
* `flow_kind` = `OPERATING|INVESTING|FINANCING`

#### Response JSON (shape)

```json
{
  "context": {
    "company_id": "uuid",
    "from": "YYYY-MM-DD",
    "to": "YYYY-MM-DD",
    "filters": {
      "direction": "in|out|null",
      "exclude_transfers": true,
      "category_id": "uuid|null",
      "system_code": "string|null",
      "flow_kind": "OPERATING|INVESTING|FINANCING|null"
    }
  },
  "items": [
    {
      "id": "uuid",
      "occurred_at": "YYYY-MM-DD",
      "amount": 0,
      "currency": "RUB",
      "comment": "string|null",
      "is_transfer": false,

      "account": { "id": "uuid", "name": "string" },

      "category": {
        "id": "uuid",
        "name": "string",
        "flow_kind": "OPERATING|INVESTING|FINANCING",
        "is_system": false,
        "system_code": "string|null"
      },

      "counterparty": { "id": "uuid", "name": "string|null" },
      "document_ref": { "type": "string", "id": "uuid" },

      "created_at": "YYYY-MM-DDTHH:MM:SSZ",
      "updated_at": "YYYY-MM-DDTHH:MM:SSZ"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 0
  }
}
```

> Если `counterparty` / `document_ref` отсутствуют в модели — возвращайте `null` или не включайте поля. Контракт допускает `null`.

---

### 2.2) GET `/api/pl/v1/documents`

#### Назначение

Список документов ОПиУ для drill-down из Revenue/Profit Snapshot/Top P&L.

#### Query (минимум)

* `from` (date, required)
* `to` (date, required)
* `page` (int, default 1)
* `per_page` (int, default 25)

#### Query (optional filters)

* `type` = `revenue|expense|cogs|opex|variable`
* `category_id` (uuid)
* `vat_mode` = `include|exclude` (если не берём из настроек компании)

#### Response JSON (shape)

```json
{
  "context": {
    "company_id": "uuid",
    "from": "YYYY-MM-DD",
    "to": "YYYY-MM-DD",
    "vat_mode": "include|exclude",
    "filters": {
      "type": "revenue|expense|cogs|opex|variable|null",
      "category_id": "uuid|null"
    }
  },
  "items": [
    {
      "id": "uuid",
      "document_no": "string|null",
      "date": "YYYY-MM-DD",
      "status": "CONFIRMED|DRAFT|CANCELLED",

      "counterparty": { "id": "uuid", "name": "string|null" },

      "amount_total": 0,

      "lines": [
        {
          "id": "uuid",
          "kind": "REVENUE|EXPENSE",
          "category": {
            "id": "uuid",
            "name": "string",
            "is_variable": false
          },
          "amount": 0,
          "amount_net": 0,
          "amount_gross": 0,
          "vat_amount": 0
        }
      ],

      "created_at": "YYYY-MM-DDTHH:MM:SSZ",
      "updated_at": "YYYY-MM-DDTHH:MM:SSZ"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 0
  }
}
```

---

### 2.3) GET `/api/pl/v1/report`

#### Назначение

Агрегированный отчёт ОПиУ (структура + суммы) для drill-down Profit Snapshot.

#### Query

* `from` (date, required)
* `to` (date, required)
* `vat_mode` = `include|exclude` (optional)

#### Response JSON (shape)

```json
{
  "context": {
    "company_id": "uuid",
    "from": "YYYY-MM-DD",
    "to": "YYYY-MM-DD",
    "vat_mode": "include|exclude"
  },
  "totals": {
    "revenue": 0,
    "variable_costs": 0,
    "gross_profit": 0,
    "opex": 0,
    "ebitda": 0,
    "margin_pct": 0
  },
  "lines": [
    {
      "id": "uuid",
      "code": "string|null",
      "name": "string",
      "kind": "REVENUE|EXPENSE",
      "is_variable": false,
      "amount": 0,
      "children": []
    }
  ]
}
```

---

```
::contentReference[oaicite:0]{index=0}
```
