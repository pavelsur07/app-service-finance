# marketplace-ozon-namespace: перенос кода Ozon в `src/Marketplace/Ozon/`

Этап 6 (последний) разделения модуля по провайдерам. Механический перенос, поведение не меняется.

Baseline (на `93121786`): baseline 2410; debug:router 416; снимки debug:messenger и
bin/console list; unit 2978, полный 5189. Открытых PR по переносимым файлам нет.

## Stage 1: перенос
Risk: HIGH-LOCAL
stage_base_commit: 93121786
Definition of Done:
- 61 класс и 36 тестов в `Ozon/…`; остаются `SyncOzon*Message`, Entity, Repository, Enum, `OzonTotalsCheckDTO`
- routes.yaml: ресурс контроллеров Ozon
- снимки до/после совпадают; phpstan OK; baseline 2410, эквивалентен master по карте переименований
- тесты 2978/5189; ARCHITECTURE.md обновлён
Reviewer focus: относительные пути в тестах, сериализуемые FQCN, маршруты, baseline
