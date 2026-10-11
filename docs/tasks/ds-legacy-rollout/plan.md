# ds-legacy-rollout: раскатка DS «Ваш Финдир» на оставшиеся легаси-страницы

Класс: **Large** (frontend redesign). Поручение Владельца 2026-10-11: «начинай
раскатку по модулям, раздели на этапы и выполняй автономно без задавания
подтверждений». Продолжение `ds-legacy-tabler` (#2617–#2623, на проде с `a864da04`).
Реестр — `docs/plan/ds-legacy-tabler-pages.md`: 47 страниц «не перенесено».

Один модуль — один PR. База `feat/ds-rollout-base` (этап 0) мержится первой;
модульные PR стоят на ней и друг от друга не зависят.

## Правила замены (из листов DS-Money / DS-Table, `docs/design-system/sheets/`)

- Сумма в ячейке таблицы — `|vf_amount` (два знака, копейки и ноль
  приглушены, U+2212). «₽» — в заголовке колонки, если колонка рублёвая; при
  смешанных валютах — `currency:` в строке и заголовок без «₽».
- Приход — `tone: 'income'`; `signed: true` (плюс) — только в списках операций.
  Красный для сумм не используется; красный остаётся только там, где он
  означает ошибку (расхождение сверки и т. п.).
- Сумма вне таблицы (карточки, сводки, подписи) — `|vf_money(2, '₽')` или с
  кодом/символом валюты записи. Итоги таблиц — с символом валюты.
- В таблицах, формах и документах суммы не сокращаются; `vf_money_short` —
  только KPI и графики.
- Не деньги (количества, проценты, курсы) — `|vf_number(n)`; точность прежняя.
- Хардкод-цвета в шаблонах — на токены `--vf-*`; смысл цвета не меняется.
- Не меняются: формулы, знаки, роуты, имена полей, `id`/`data-*`, экспорт XLS/CSV
  (лист: «в XLS и CSV суммы — числами без пробелов и символа»).
- Тесты, завязанные на старый формат, обновляются вместе со страницей.

## Этапы
| # | PR | Страницы |
|---|---|---|
| 0 | база | `vf_number`, план, реестр |
| 1 | Cash | `cash/*`, `transaction/show`, `transaction/splits`, `cash_transaction_auto_rule/*`, `fund/index`, `cashflow_category/index`, `payment_calendar/index` |
| 2 | Finance | `document/*`, `finance/report/raw`, `finance/reports/pl_raw`, `pl_category/index` |
| 3 | Reports | `report/*` |
| 4 | Loans | `loan/*` |
| 5 | Deals | `deals/*` |
| 6 | Catalog | `catalog/product/show` |
| 7 | Marketplace: операции | `marketplace/{sales,returns,costs,products,listings,unmapped,inventory/*}` |
| 8 | Marketplace: отчёты | `marketplace/{wb_finance_report,month_close,ozon_reconciliation/*}` |
| 9 | Marketplace: аналитика и реклама | `marketplace_analytics/snapshots`, `marketplace_ads/admin_debug`, `integrations/marketplace` |

Risk каждого этапа: MEDIUM (вывод сумм; финансовая семантика не меняется).
Checks этапа: `lint:twig`, функциональные тесты модуля, `npm run build`, smoke.
Handoff: полные гейты, внутреннее ревью в свежей сессии, внешнее ревью, один
вопрос «merge and deploy» на все PR раскатки.
