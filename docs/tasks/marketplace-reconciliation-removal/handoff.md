# Handoff — удаление отчёта `/marketplace/reconciliation`

## Что сделано

Отчёт «Сверка» удалён целиком: страница, 8 API-эндпоинтов, React-остров,
`ReconciliationSession` + репозиторий + enum, `RunUserReconciliationAction`,
`SalesReturnsTotalQuery`, vite-entry `reconciliation_page`, пункт меню.
Миграция `Version20260912120000` дропает `marketplace_reconciliation_sessions`.

33 файла, −2735/+115.

## Границы: что намеренно оставлено

`src/Marketplace/Application/Reconciliation/*` (парсер Ozon xlsx),
`CostReconciliationQuery`, `ReconciliationFileReadException`, `ReconcileCostsAction`,
`CostReconciliationController` — обслуживают `POST /marketplace/month-close/reconcile`.
Однофамильцы из Ingestion, MarketplaceAds, WB-финотчётов и cashflow не тронуты.

## Гейты

| Проверка | Результат |
|---|---|
| PHPStan level 8 | ✅ No errors |
| `make site-cs-check` | ✅ 0 of 2506 |
| `make site-cs-strict-types` | ✅ 0 of 2506 |
| `make site-test` | ✅ 4452 теста, 26208 ассертов |
| `yarn build` | ✅ |
| `debug:router` | ✅ `marketplace_reconciliation*` отсутствуют |

`make site-stan` упал OOM (exit 137) на этой машине — параллелизм PHPStan при
2.2 ГБ свободной памяти. Анализ прогнан напрямую через `vendor/bin/phpstan`
с тем же конфигом и лимитом: зелёный. К изменению отношения не имеет.

`doctrine:schema:validate` показывает «база не в синхроне» — унаследованный дрейф
(257 операций по чужим таблицам, из reconciliation-related только мёртвая
`marketplace_reconciliation_log`). Удалённой таблицы в diff нет.

## Внешнее ревью (1 раунд, Codex)

- **BLOCKER — исправлен.** Процедура удаления файлов предписывала снести префикс
  `marketplace/reconciliation/` целиком. В тот же префикс пишет сохранённый
  `ReconcileCostsAction` («Закрытие месяца»), форматы путей совпадают посимвольно —
  снос префикса уничтожил бы вложения закрытия месяца. Процедура переписана на
  поштучное удаление по списку `stored_file_path` со сверкой против
  `MarketplaceMonthClose.settings`. Кода находка не касалась, только инструкции.
- **IMPORTANT / MINOR — отклонены с обоснованием.** Ревьюер указал на
  `site/bin/capture-wb-inventory.sh` и `docs/plan/my_paln_admin.md`. Обоих файлов в
  коммите нет: это незакоммиченные правки владельца в рабочем дереве, оставленные
  нетронутыми по AGENTS.md §8. Ревьюер читал рабочее дерево, а не diff коммита.

## Требуется от владельца

PR несёт миграцию, поэтому обычный «merge and deploy» его не задеплоит.

Дополнительно — удаление файлов сверки в объектном хранилище (§3.3). Порядок
строгий: выгрузить `stored_file_path` → сверить их против
`marketplace_month_closes.settings -> 'costs_reconciliation' ->> 'file_path'`
(готовый SQL — в `stages/stage-2.md`) → удалить объекты поштучно → только потом
дроп таблицы. После дропа список взять негде.

## FOLLOW-UP (вне scope, по решению владельца)

Мёртвый код рядом, не связанный с отчётом: `ReconciliationLog` +
`ReconciliationLogRepository`, `ProcessingBatch`, `MarketplaceStaging`,
`CostsVerifyQuery` и таблицы из `Version20260322120000`.
