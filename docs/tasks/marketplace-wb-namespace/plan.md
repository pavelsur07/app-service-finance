# marketplace-wb-namespace: перенос кода Wildberries в `src/Marketplace/Wildberries/`

Этап 5 разделения модуля по провайдерам. Механический перенос, поведение не меняется.

Baseline (на `f4550ada`): baseline 2410 записей; debug:router — 416 маршрутов; снимки
debug:messenger, bin/console list, debug:container --tag=app.marketplace.cost_calculator;
unit 2978, полный набор 5189. Открытых PR по переносимым файлам нет.

## Stage 1: перенос
Risk: HIGH-LOCAL (широкая работа по модулю, FQCN обработчика Messenger)
stage_base_commit: f4550ada
Definition of Done:
- 59 классов и 38 тестов в `Wildberries/…`, namespace и ссылки переписаны
- остаются: `SyncWbFinancialReportDayMessage`, `WbFinanceThrottleFacade`, `WbFinanceThrottleDTO`
- routes.yaml: ресурс контроллеров WB
- снимки до/после совпадают (маршруты, команды, порядок калькуляторов; в Messenger — только FQCN обработчика)
- phpstan OK, baseline 2410; тесты 2978/5189
- ARCHITECTURE.md: раздел о раскладке по провайдерам
Work items:
- 1.1 — скрипт переноса (scratchpad), git mv, переписывание ссылок, cs-fix, baseline
- 1.2 — routes.yaml, снимки, гейты
- 1.3 — документация
Reviewer focus: неявные ссылки внутри namespace, сериализуемые FQCN, маршруты, baseline
