# source-fact-envelope v1

Семантика полей контракта. Schema: [schema.json](schema.json). История решений:
[CHANGELOG.md](CHANGELOG.md). Статус: **проект** (см. `_contracts/README.md`).

## Доставка

`POST /internal/source-facts`, заголовок `Idempotency-Key: <batch.batch_id>`.

- Symfony отвечает ACK только после долговечной записи inbox; повтор того же
  `batch_id` возвращает прежний результат.
- `429`/`503` от Symfony → producer повторяет с экспоненциальным backoff +
  jitter, сохраняя курсор.
- Endpoint — только внутри docker-сети / за IP-allowlist.

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
