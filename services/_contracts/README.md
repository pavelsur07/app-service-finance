# _contracts — межсервисные контракты

Единственный источник правды для форматов данных между сервисами `services/`
(producer) и Symfony (consumer). Правила потребления — `services/AGENTS.md` §2.

## Версионирование

- Контракт — каталог `<contract>/v<N>/` со `schema.json` и `CHANGELOG.md`.
- Опубликованная версия неизменна: правка поля/семантики = новая версия;
  producer и consumer мигрируют независимо.
- Consumer обязан принимать все заявленные версии; producer шлёт одну версию.
- Неизвестная версия на приёме → карантин, не skip.

## Индекс

| Контракт | Версии | Стороны | Статус |
|---|---|---|---|
| [source-fact-envelope](source-fact-envelope/v1/) | v1 | producer: `services/*` (`GET /internal/source-facts`, `/page`, `POST …/ack`); consumer: Symfony (pull + ack) | действует, `ozon.finance.accrual-by-day` |
| [marketplace-connections](marketplace-connections/v1/) | v1 | producer: Symfony; consumer: `services/*` (поллинг конфигурации) | **проект**, не утверждённый API |
| [service-coverage](service-coverage/v1/) | v1 | producer: `services/*` (`GET /internal/coverage`); consumer: шлюз Symfony | действует |
| [service-snapshot](service-snapshot/v1/) | v1 | producer: `services/*` (`GET /internal/snapshots/latest`); consumer: Symfony (pull, без ack) | действует, `ozon.supplies` |
| [wb-finance-quota](wb-finance-quota/v1/) | v1 | producer: Symfony (слот и 429 лимитера WB finance); consumer: `services/wb-fetcher` | действует |
| [ai-task](ai-task/v1/) | v1 | producer: `services/ai-service` (API задач); consumer: шлюз Symfony | действует, задача `echo` |

## Статус «проект»

Контракт в статусе «проект» можно менять в его `v1` до утверждения; после —
только через `v2`. `source-fact-envelope` v1 утверждён 13.11.2026 (см. его CHANGELOG).
