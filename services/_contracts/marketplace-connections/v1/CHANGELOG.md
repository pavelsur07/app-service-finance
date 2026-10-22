# marketplace-connections — CHANGELOG

## Правка (2026-10-22) — остатки

- Новые значения `resource_types` у seller: `ozon.stocks` (снимок 05:00) и
  `ozon.stocks.week-close` (отсечка недели, воскресенье 23:30), оба — `/v4/product/info/stocks`.
  Выдаются по списку `ozon_fetcher.stocks_companies`. Схема не менялась.

## Правка (2026-10-21) — каталог товаров

- Новые значения `resource_types` у seller: `ozon.products.list`, `ozon.products.info`
  (`/v3/product/list`, `/v3/product/info/list`). Выдаются по списку
  `ozon_fetcher.products_companies`. Схема не менялась.

## Правка (2026-10-21) — начисления по отправлениям

- Новое значение `resource_types` у seller: `ozon.finance.accrual-postings`
  (`/v1/finance/accrual/postings`). Выдаётся только кабинетам с ресурсами
  отправлений и по списку `ozon_fetcher.accrual_postings_companies`. Схема не менялась.

## Правка (2026-10-21) — ресурсы отправлений

- Новые значения `resource_types` у seller: `ozon.orders.fbo`, `ozon.orders.fbs`
  (`/v3/posting/fbo/list`, `/v4/posting/fbs/list`). Выдаются не всем кабинетам с
  seller, а по списку компаний в Symfony (`ozon_fetcher.orders_companies`) —
  поле по-прежнему выводимое, сервис грузит только объявленные ресурсы.
  Схема не менялась (аддитивно).

## Правка проекта (2026-10-17, до утверждения)

- Пример `resource_types` — `ozon.finance.accrual-by-day` вместо
  `ozon.finance.transaction-list`: Ozon снял `/v3/finance/transaction/list`
  09.09.2026. Перечислены значения seller первой волны. Структура схемы не
  менялась.

## v1.0.0-draft (2026-10-13) — первый проект

Решения:

- credentials сгруппированы по типу ключа (`seller` / `performance`), не по
  ресурсу: у кабинета может быть любое подмножество типов, включая пустое.
- `resource_types` — выводимое поле (из наличия ключей), не источник истины.
- Ответ — полный снапшот активных подключений, не дельты: проще кэш и
  восстановление после сбоев.

Статус: **проект** — согласовать с реализацией в Symfony
(`MarketplaceConnection`, `Active*ConnectionsQuery`, `ConnectionApiKeyCodec`)
до утверждения; после утверждения изменения только через v2.
