### Stage 1: Symfony отдаёт WB-кабинеты (`provider=wildberries`) — TODO

**Risk:** HIGH-LOCAL (токены продавцов во внутреннем API) · **Branch:** `feat/wb-fetcher-connections`
**Внешнее ревью:** да (Codex, по итогу этапа)

## 1. Какую задачу решаем

`GET /internal/marketplace-connections` отвечает только на `provider=ozon`, на
`wildberries` — 422 `unsupported_provider`. wb-fetcher неоткуда взять кабинеты и токены.

## 2. Что будет результатом работы

- `MarketplaceConnectionsSnapshotBuilder` (или отдельный builder для WB — по объёму
  различий) собирает снимок для `provider=wildberries`:
  - активные подключения WB с токеном (`ActiveConnectionCredentialsQuery::fetchActive(WILDBERRIES)`);
  - `credentials.seller = {client_id: <ID бакета продавца>, api_key: <токен>}`, `performance: null`;
    ID бакета — тот же, что `WbFinanceRateLimiter::resolveSalesReportsBucket()` (решение 2 плана);
  - `resource_types`: `wb.finance.sales-report.daily`, `wb.finance.sales-report.weekly` — только
    компаниям из параметра `wb_fetcher.finance_companies` (`services.yaml`, пусто — никому).
- Контроллер принимает `ozon` и `wildberries`, остальное — 422 как сейчас.
- `services/_contracts/marketplace-connections/v1/CHANGELOG.md` и README: значения
  `resource_types` для WB, смысл `client_id` у WB. Схема не меняется.
- `ARCHITECTURE.md` (Marketplace, внутренний API), Changelog.

## 3. Что считаем выполненной работой

Запрос с `provider=wildberries` и токеном сервиса возвращает ответ, проходящий
схему v1; компании вне allowlist — без ресурсов; выдача Ozon не изменилась.

## 4. TDD — тесты пишутся первыми

- [ ] functional: `GET …?provider=wildberries` без токена → 401; с токеном → 200, ответ валиден по `schema.json` v1
- [ ] functional: WB-подключение компании из allowlist → оба `wb.finance.*` в `resource_types`; вне allowlist → `resource_types: []`
- [ ] functional: неактивное подключение и подключение без токена не выдаются
- [ ] functional: `provider=ozon` — ответ совпадает с прежним (регрессия, фикстура до правки)
- [ ] functional: `provider=yandex` → 422 `unsupported_provider`
- [ ] unit: `client_id` у двух подключений одного продавца одинаковый (общий бакет лимита)
- [ ] unit/functional: токен не попадает в лог (тестовый логгер)

## 5. Чек-лист приёмки

- [ ] тесты из §4 написаны и были красными до кода
- [ ] параметр `wb_fetcher.finance_companies` в `services.yaml`, пусто по умолчанию
- [ ] CHANGELOG/README контракта; схема v1 не изменена
- [ ] IDOR/scope: выборка не по компании — `@companyScopeExempt` с причиной (внутренний API сервиса), как у Ozon
- [ ] `ARCHITECTURE.md` — текущее состояние + Changelog
- [ ] PHPStan / CS / strict_types по изменённым файлам; baseline не растёт
- [ ] внешнее ревью (Codex), замечания закрыты
