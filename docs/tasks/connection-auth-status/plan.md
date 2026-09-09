# connection-auth-status: уведомление об устаревшем ключе подключения

Задача выросла из разбора failed-очереди 2026-09-09: 51 сообщение за сутки от
одного подключения Ozon, чей ключ маркетплейс перестал принимать 8 сентября.
Крон ставил два задания в час, каждое падало в failed, а в кабинете не
показывалось ничего — страница подключений выводит ошибку только при
`is_active = false`, тогда как сломанное подключение остаётся активным.

Baseline (2026-09-09, коммит `a1330be1`):
- `make site-cs-check` — `Found 0 of 2489`, зелёный
- `make site-cs-strict-types` — зелёный
- `make site-stan` — `No errors`, зелёный
- `make site-test-unit` — 2349 тестов, 12055 утверждений, 4 deprecation
- предсуществующее расхождение схемы: `doctrine:schema:update --dump-sql` даёт
  255 операторов, в том числе `ALTER TABLE marketplace_connections ALTER
  connection_type DROP DEFAULT`. Проверено откатом: расхождение есть и без
  изменений этой задачи.

## Что уже было в коде

| Готовое | Где |
|---|---|
| Форма обновления ключа с валидацией у маркетплейса и сбросом ошибки | `UpdateMarketplaceConnectionApiKeyController` |
| Перехват `ConnectorAuthException` и пометка задания причиной `auth` | `RunSyncChunkHandler:153` |
| Выборка подключений для крона | `ActiveSellerConnectionsQuery` |
| Страница подключений и дашборд | `templates/marketplace/connections.html.twig`, `templates/home/dashboard.html.twig` |

## Stage 1: состояние аутентификации на подключении
Risk: HIGH-LOCAL (миграция)
stage_base_commit: `a1330be1`
Definition of Done:
- у подключения есть состояние аутентификации, которым управляет конвейер;
- три отказа подряд переводят подключение в `FAILED`, успех обнуляет серию;
- модуль Ingestion может записать исход только через фасад;
- есть выборка сломанных подключений компании для кабинета;
- миграция обратима, новых расхождений схемы не создаёт;
- `ARCHITECTURE.md` обновлён;
- исключено: правка обработчика, планировщика и шаблонов — это Stage 2 и 3.

Work items:
- 1.1 — enum `MarketplaceConnectionAuthStatus`
- 1.2 — поля и переходы на `MarketplaceConnection`
- 1.3 — миграция
- 1.4 — `RecordConnectionAuthResultAction`, методы фасада, `BrokenConnectionsQuery`
- 1.5 — тесты

Stage checks: `make site-cs-check`, `make site-cs-strict-types`, `make site-stan`,
`make site-test-unit`, интеграционный набор, применение и откат миграции,
`doctrine:schema:validate`.

Reviewer focus: обратимость миграции, изоляция по компании в новых методах,
поведение порога и сброса, отсутствие роста PHPStan-baseline.

## Stage 2: автостоп и автоснятие
Risk: HIGH-LOCAL (поведение планировщика)

- 2.1 — запись исхода из `RunSyncChunkHandler` через фасад
- 2.2 — `executeSyncable()` в `ActiveSellerConnectionsQuery`, переключение
  только `RunIncrementalCommand`; остальные потребители не трогаются
- 2.3 — снятие состояния в `UpdateMarketplaceConnectionApiKeyController`
- 2.4 — `warning` на отказ, один агрегированный `error` на переходе
- 2.5 — тесты обработчика, выборки и обновления ключа

## Stage 3: видимость в кабинете
Risk: MEDIUM

- 3.1 — пилюля и кнопка «Обновить ключ» в строке подключения
- 3.2 — блок «Интеграции» на дашборде
- 3.3 — разметка Tabler, как на соседних страницах
- 3.4 — тексты без HTTP-кодов и фрагментов ключа

## Не входит

Письмо и Telegram; предупреждение об истечении токена МоегоСклада; перезапуск
накопленных сообщений в failed (отдельное одобрение); пустая таблица
`ingestion_credentials`.
