# Дизайн-система в легаси-слое — реестр страниц

Задача `ds-legacy-tabler`. Обновлено 2026-10-11 после пилота (Stage 5).

Глобальный слой (токены, компоненты, пагинация, Tabs — PR #2617–#2620) действует на
все страницы `_layout/legacy.html.twig` без правки шаблонов. «Перенесено» — у страницы
вместе с её partials нет локальных расхождений с DS: деньги через `vf_money*`/`vf_amount`,
нет hex/rgb-цветов в шаблоне и ₽ в ячейках. «Не перенесено» — что осталось (счётчики по
странице и её partials); закрывается раскаткой по модулям (один модуль — один PR) после
подтверждения Владельца.

Итого страниц: 143; перенесено: 96; не перенесено: 47.
Общие partials layout'а (сайдбар, флеши, виджет компании): деньги 0, hex/rgb 0.

Подсчёт: `number_format`/`format_currency` (кроме процентов), hex `#rrggbb`/`#rgb` с
буквой (номера вида `#414` и HTML-сущности не считаются), `rgb(`/`rgba(` с числом.

| Модуль | Шаблон | Статус | Осталось |
|---|---|---|---|
| admin | `admin/tools/messenger_ping.html.twig` | перенесено (глобальный слой) | — |
| ai | `ai/suggestions/list.html.twig` | перенесено (глобальный слой) | — |
| base.html.twig | `base.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/bank1c_import_preview.html.twig` | не перенесено | деньги: 1, hex/rgb: 3 |
| cash | `cash/bank1c_import_result.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/bank1c_import_upload.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/bank_connection/edit.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/bank_connection/index.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/bank_connection/new.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/file_import_mapping.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/file_import_preview.html.twig` | не перенесено | деньги: 1, hex/rgb: 1 |
| cash | `cash/file_import_profiles/edit.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/file_import_profiles/index.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/file_import_profiles/new.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/file_import_queued.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/file_import_upload.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/transaction/audit_index.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/transaction/deleted_index.html.twig` | не перенесено | деньги: 1 |
| cash | `cash/transfer/deleted_index.html.twig` | не перенесено | деньги: 2 |
| cash | `cash/transfer/new.html.twig` | перенесено (глобальный слой) | — |
| cash | `cash/transfer/show.html.twig` | не перенесено | деньги: 2 |
| cash_transaction_auto_rule | `cash_transaction_auto_rule/candidates.html.twig` | перенесено (глобальный слой) | — |
| cash_transaction_auto_rule | `cash_transaction_auto_rule/check.html.twig` | не перенесено | деньги: 1 |
| cash_transaction_auto_rule | `cash_transaction_auto_rule/edit.html.twig` | перенесено (глобальный слой) | — |
| cash_transaction_auto_rule | `cash_transaction_auto_rule/index.html.twig` | перенесено (глобальный слой) | — |
| cash_transaction_auto_rule | `cash_transaction_auto_rule/new.html.twig` | не перенесено | деньги: 1 |
| cashflow_category | `cashflow_category/edit.html.twig` | перенесено (глобальный слой) | — |
| cashflow_category | `cashflow_category/index.html.twig` | не перенесено | hex/rgb: 1 |
| cashflow_category | `cashflow_category/new.html.twig` | перенесено (глобальный слой) | — |
| catalog | `catalog/product/edit.html.twig` | перенесено (глобальный слой) | — |
| catalog | `catalog/product/import.html.twig` | перенесено (глобальный слой) | — |
| catalog | `catalog/product/import_status.html.twig` | перенесено (глобальный слой) | — |
| catalog | `catalog/product/index.html.twig` | перенесено (глобальный слой) | — |
| catalog | `catalog/product/new.html.twig` | перенесено (глобальный слой) | — |
| catalog | `catalog/product/purchase_price_history.html.twig` | перенесено (глобальный слой) | — |
| catalog | `catalog/product/show.html.twig` | не перенесено | деньги: 3 |
| company | `company/company_member/index.html.twig` | перенесено (глобальный слой) | — |
| company | `company/edit.html.twig` | перенесено (глобальный слой) | — |
| company | `company/index.html.twig` | перенесено (глобальный слой) | — |
| company | `company/new.html.twig` | перенесено (глобальный слой) | — |
| company | `company/price/index.html.twig` | перенесено (глобальный слой) | — |
| company | `company/role/form.html.twig` | перенесено (глобальный слой) | — |
| company | `company/role/index.html.twig` | перенесено (глобальный слой) | — |
| company | `company/show.html.twig` | перенесено (глобальный слой) | — |
| counterparty | `counterparty/edit.html.twig` | перенесено (глобальный слой) | — |
| counterparty | `counterparty/index.html.twig` | перенесено (глобальный слой) | — |
| counterparty | `counterparty/new.html.twig` | перенесено (глобальный слой) | — |
| deals | `deals/charge_types/edit.html.twig` | перенесено (глобальный слой) | — |
| deals | `deals/charge_types/index.html.twig` | перенесено (глобальный слой) | — |
| deals | `deals/charge_types/new.html.twig` | перенесено (глобальный слой) | — |
| deals | `deals/error.html.twig` | перенесено (глобальный слой) | — |
| deals | `deals/index.html.twig` | не перенесено | деньги: 3 |
| deals | `deals/new.html.twig` | перенесено (глобальный слой) | — |
| deals | `deals/show.html.twig` | не перенесено | деньги: 7 |
| document | `document/deleted_index.html.twig` | не перенесено | деньги: 3 |
| document | `document/edit.html.twig` | не перенесено | деньги: 3 |
| document | `document/index.html.twig` | не перенесено | деньги: 3 |
| document | `document/new.html.twig` | перенесено (глобальный слой) | — |
| document | `document/show.html.twig` | перенесено (глобальный слой) | — |
| finance | `finance/report/cashflow.html.twig` | перенесено (глобальный слой) | — |
| finance | `finance/report/preview.html.twig` | перенесено (глобальный слой) | — |
| finance | `finance/report/raw.html.twig` | не перенесено | деньги: 1, hex/rgb: 2 |
| finance | `finance/reports/pl_raw.html.twig` | не перенесено | деньги: 4 |
| financial_responsibility_center | `financial_responsibility_center/edit.html.twig` | перенесено (глобальный слой) | — |
| financial_responsibility_center | `financial_responsibility_center/index.html.twig` | перенесено (глобальный слой) | — |
| financial_responsibility_center | `financial_responsibility_center/new.html.twig` | перенесено (глобальный слой) | — |
| fund | `fund/index.html.twig` | не перенесено | деньги: 1 |
| fund | `fund/movement.html.twig` | перенесено (глобальный слой) | — |
| fund | `fund/new.html.twig` | перенесено (глобальный слой) | — |
| home | `home/dashboard.html.twig` | перенесено (глобальный слой) | — |
| home | `home/index.html.twig` | перенесено (пилот) | — |
| import_log | `import_log/index.html.twig` | перенесено (глобальный слой) | — |
| ingestion | `ingestion/verification/coverage.html.twig` | перенесено (глобальный слой) | — |
| ingestion | `ingestion/verification/financial-summary.html.twig` | перенесено (глобальный слой) | — |
| ingestion | `ingestion/verification/issues.html.twig` | перенесено (глобальный слой) | — |
| ingestion | `ingestion/verification/reconciliation.html.twig` | перенесено (глобальный слой) | — |
| integrations | `integrations/marketplace.html.twig` | не перенесено | hex/rgb: 5 |
| inventory | `inventory/layout.html.twig` | перенесено (глобальный слой) | — |
| inventory | `inventory/snapshots/index.html.twig` | перенесено (глобальный слой) | — |
| inventory | `inventory/stocks/index.html.twig` | перенесено (глобальный слой) | — |
| loan | `loan/form.html.twig` | перенесено (глобальный слой) | — |
| loan | `loan/index.html.twig` | не перенесено | деньги: 3 |
| loan | `loan/report.html.twig` | не перенесено | деньги: 5 |
| loan | `loan/schedule.html.twig` | не перенесено | деньги: 4 |
| loan | `loan/schedule_upload.html.twig` | перенесено (глобальный слой) | — |
| marketplace | `marketplace/connection/edit.html.twig` | перенесено (глобальный слой) | — |
| marketplace | `marketplace/connections.html.twig` | перенесено (глобальный слой) | — |
| marketplace | `marketplace/cost_pl_mapping/index.html.twig` | перенесено (глобальный слой) | — |
| marketplace | `marketplace/costs.html.twig` | не перенесено | деньги: 2 |
| marketplace | `marketplace/index.html.twig` | перенесено (глобальный слой) | — |
| marketplace | `marketplace/inventory/history.html.twig` | не перенесено | деньги: 1 |
| marketplace | `marketplace/inventory/index.html.twig` | не перенесено | деньги: 1 |
| marketplace | `marketplace/layout.html.twig` | перенесено (глобальный слой) | — |
| marketplace | `marketplace/listings/index.html.twig` | не перенесено | ₽ в ячейках: 1 |
| marketplace | `marketplace/listings/tags.html.twig` | перенесено (глобальный слой) | — |
| marketplace | `marketplace/month_close/index.html.twig` | не перенесено | деньги: 4 |
| marketplace | `marketplace/ozon_reconciliation/index.html.twig` | не перенесено | деньги: 3 |
| marketplace | `marketplace/ozon_reconciliation/operations.html.twig` | не перенесено | деньги: 3 |
| marketplace | `marketplace/pl_mappings.html.twig` | перенесено (глобальный слой) | — |
| marketplace | `marketplace/products.html.twig` | не перенесено | деньги: 1 |
| marketplace | `marketplace/returns.html.twig` | не перенесено | деньги: 4 |
| marketplace | `marketplace/sales.html.twig` | не перенесено | деньги: 5, ₽ в ячейках: 1 |
| marketplace | `marketplace/unmapped/index.html.twig` | не перенесено | ₽ в ячейках: 1 |
| marketplace | `marketplace/wb_finance_report.html.twig` | не перенесено | деньги: 32 |
| marketplace_ads | `marketplace_ads/admin_debug.html.twig` | не перенесено | hex/rgb: 4 |
| marketplace_ads | `marketplace_ads/efficiency/index.html.twig` | перенесено (глобальный слой) | — |
| marketplace_ads | `marketplace_ads/index.html.twig` | перенесено (глобальный слой) | — |
| marketplace_analytics | `marketplace_analytics/cost_mappings/index.html.twig` | перенесено (глобальный слой) | — |
| marketplace_analytics | `marketplace_analytics/index.html.twig` | перенесено (глобальный слой) | — |
| marketplace_analytics | `marketplace_analytics/snapshots/index.html.twig` | не перенесено | деньги: 7 |
| marketplace_analytics | `marketplace_analytics/unit_extended/index.html.twig` | перенесено (глобальный слой) | — |
| moy_sklad | `moy_sklad/connections/form.html.twig` | перенесено (глобальный слой) | — |
| moy_sklad | `moy_sklad/connections/index.html.twig` | перенесено (глобальный слой) | — |
| moy_sklad | `moy_sklad/index.html.twig` | перенесено (глобальный слой) | — |
| moy_sklad | `moy_sklad/layout.html.twig` | перенесено (глобальный слой) | — |
| payment_calendar | `payment_calendar/index.html.twig` | не перенесено | деньги: 4 |
| pl_category | `pl_category/edit.html.twig` | перенесено (глобальный слой) | — |
| pl_category | `pl_category/import.html.twig` | перенесено (глобальный слой) | — |
| pl_category | `pl_category/index.html.twig` | не перенесено | hex/rgb: 3 |
| pl_category | `pl_category/new.html.twig` | перенесено (глобальный слой) | — |
| profile | `profile/money_account/edit.html.twig` | перенесено (глобальный слой) | — |
| profile | `profile/money_account/index.html.twig` | перенесено (глобальный слой) | — |
| profile | `profile/money_account/new.html.twig` | перенесено (глобальный слой) | — |
| project_direction | `project_direction/edit.html.twig` | перенесено (глобальный слой) | — |
| project_direction | `project_direction/index.html.twig` | перенесено (глобальный слой) | — |
| project_direction | `project_direction/new.html.twig` | перенесено (глобальный слой) | — |
| report | `report/account_balances.html.twig` | не перенесено | деньги: 6 |
| report | `report/account_balances_structured.html.twig` | не перенесено | деньги: 8 |
| report | `report/cashflow.html.twig` | не перенесено | деньги: 4 |
| report | `report/cashflow_ops_check.html.twig` | не перенесено | деньги: 9 |
| report | `report/debug_daily_facts.html.twig` | не перенесено | деньги: 2 |
| report | `report/debug_transactions_raw.html.twig` | не перенесено | деньги: 1 |
| report | `report/transactions_debug.html.twig` | не перенесено | деньги: 3 |
| report | `report/transactions_statement.html.twig` | не перенесено | деньги: 9 |
| security | `security/change_password.html.twig` | перенесено (глобальный слой) | — |
| settings | `settings/report_api_key.html.twig` | перенесено (глобальный слой) | — |
| telegram | `telegram/integration/index.html.twig` | перенесено (глобальный слой) | — |
| transaction | `transaction/auto_rule_apply.html.twig` | перенесено (глобальный слой) | — |
| transaction | `transaction/edit.html.twig` | перенесено (пилот) | — |
| transaction | `transaction/index.html.twig` | перенесено (пилот) | — |
| transaction | `transaction/new.html.twig` | перенесено (пилот) | — |
| transaction | `transaction/show.html.twig` | не перенесено | деньги: 3 |
| transaction | `transaction/splits.html.twig` | не перенесено | деньги: 1 |
