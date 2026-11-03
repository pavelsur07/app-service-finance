# wb-fetcher — правила сервиса

Загрузка финансовых отчётов Wildberries (finance-api) по всем активным
кабинетам: raw в S3, журнал загрузок в своей БД. План работ —
`docs/tasks/wb-fetcher-finance/plan.md`, этапы — `stages/`.

Общие правила — `services/AGENTS.md` и `services/go.md`; этот файл специфичнее
для каталога.

## Стек и раскладка

- Go 1.27; ядро — `services/shared/fetchkit` (`replace` в `go.mod`): очередь
  задач, исполнитель, диспетчер, цикл планировщика, лимитер, raw S3, HTTP API.
  Ядро не копировать и не править под WB: провайдерская специфика — здесь.
- `cmd/wb-fetcher/` — только wiring. `internal/wb` — клиент finance-api;
  `internal/connections` — кабинеты из Symfony; `internal/planner` — что ставить;
  `internal/executor` — адаптер `fetch.Adapter` (пагинация `rrdId`, общая квота Symfony);
  `internal/quota` — клиент квоты `wb-finance-quota` v1;
  `internal/app` — сборка цепочки, лимиты, миграции; `internal/httpapi` — имя
  сервиса и ожидания покрытия.
- Гейты: `make wb-fetcher-lint wb-fetcher-vet wb-fetcher-test`; при правке
  ядра — также `fetchkit-*` и `ozon-fetcher-*`.

## Границы ответственности

Делает: кабинеты и токены из Symfony (`marketplace-connections` v1,
`provider=wildberries`), запросы детального отчёта о реализации с лимитом на
кабинет, курсор `rrdId`, повтор после 429 с паузой из заголовков, raw в S3 без
перезаписи (`wb/…`), журнал загрузок и покрытие.

Не делает: не пишет в БД Symfony, не разбирает строки отчёта, не решает об
идемпотентности по состоянию Symfony, не отключает загрузку WB в Symfony —
старый путь работает параллельно до отдельного решения.

## Инварианты

- Токен WB — только в памяти (`connections.Token` печатается скрытым); в тексте
  ошибок и логах его нет (тесты `wb`, `connections`, `executor`).
- Не чаще одного запроса отчёта на кабинет за `WB_FINANCE_MIN_INTERVAL`
  (≥ 60 с — лимит WB 1/мин на продавца) и только со слотом общей квоты Symfony
  (`wb-finance-quota` v1, бакет `connection:<id>` импорта Symfony); квота не ответила —
  запроса нет, повтор через минуту; 429 от WB передаётся в Symfony общим cooldown.
- Миграции — миграции ядра (`store.CoreMigrations()`); своя схема БД не
  заводится. Изменение схемы — в ядре новой миграцией и в ozon-fetcher.
- Ключ S3: `wb/{connection_ref}/{resource_type}/{period_key}/…` — бакет и
  пользователь общие с ozon-fetcher, разделение только префиксом.
