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
| [source-fact-envelope](source-fact-envelope/v1/) | v1 | producer: `services/*`; consumer: Symfony (pull + ack) | **проект**, не утверждённый API |
| [marketplace-connections](marketplace-connections/v1/) | v1 | producer: Symfony; consumer: `services/*` (поллинг конфигурации) | **проект**, не утверждённый API |
| [service-coverage](service-coverage/v1/) | v1 | producer: `services/*` (`GET /internal/coverage`); consumer: шлюз Symfony | действует |

## Статус «проект»

`source-fact-envelope` v1 — проект контракта (по терминологии аудита
`05-target-architecture.md`): до реализации Symfony inbox endpoint его нужно
согласовать с `docs/financial-core/05-financial-events.md`,
`07-idempotency.md`, ADR-006/008 и natural keys Ingestion. До согласования
изменения в v1 допустимы; после — только через v2.
