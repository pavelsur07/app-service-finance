# source-fact-envelope v1

Семантика полей контракта. Schema: [schema.json](schema.json). История решений:
[CHANGELOG.md](CHANGELOG.md). Статус: **проект** (см. `_contracts/README.md`).

## Доставка: pull-модель (решение владельца, 2026-10-13)

Envelope передаётся от сервиса к Symfony **по запросу Symfony** (схема
envelope не меняется, меняется семантика доставки):

1. Сервис копит готовые батчи (outbox, `status=new`).
2. Symfony: `GET /internal/source-facts?connection_ref=…&resource_type=…&
   business_period=…&cursor=…` — постранично, только `status=new`.
3. Symfony импортирует идемпотентно (inbox и natural keys — на его стороне)
   и подтверждает: `POST /internal/source-facts/ack` с `batch_ids`.
4. Сервис переводит батчи в `status=acked` и обнуляет payload (retention,
   `services/AGENTS.md` §9). До ack повторные pull'и возвращают те же батчи
   (re-pull окно для сверок/паритета).

Endpoint'ы — только внутри docker-сети / за IP-allowlist + internal token
(`services/AGENTS.md` §3). Push-вариант (`POST` в inbox Symfony) отменён до
реализации; версия схемы от этого не изменилась.

## Деньги

`amount` — точная десятичная строка (до 4 знаков), `currency` — ISO 4217.
Внутри сервисов расчёты и хранение промежуточных значений — только в формах,
точно воспроизводящих контракт; float/double запрещены.

## Идемпотентность строк (`source_key`)

Ключ стабилен между повторными выгрузками одного периода. Семантика за
провайдером, версионируется вместе с контрактом:

| provider | resource_type | Семантика `source_key` |
|---|---|---|
| ozon | `ozon.finance.transaction-list` | проект: `ozon-accrual-{operation_id}-product-{i}` / `ozon-accrual-{operation_id}-return-product-{i}`; суффикс `-acc{accrual_id}` при наличии accrual (паритет с `docs/architecture/marketplace-audit/08-normalization-boundary.md` §7) |

Ключи остальных провайдеров/ресурсов фиксируются здесь до реализации
соответствующего producer.

## Коррекции

Новая `source_revision` + причинная ссылка (следующая ревизия ссылается на
вышеотменённую вне контракта — через инструментарий Symfony). Overwrite
строки с прежней ревизией запрещён.

## Неизвестное на приёме

Неизвестная `schema_version`, `operation_type`, `category_code` или
`currency` → карантин в Symfony, не skip (аудит 05/08).
