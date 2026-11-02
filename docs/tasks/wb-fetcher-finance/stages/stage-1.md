### Stage 1: Symfony отдаёт WB-кабинеты (`provider=wildberries`) — IN PROGRESS

**Risk:** HIGH-LOCAL (токены продавцов во внутреннем API) · **Ветка:** `feat/wb-fetcher-finance` (одна на задачу, PR на handoff)
**Внешнее ревью:** да (Codex, по итогу этапа)

## 1. Какую задачу решаем

`GET /internal/marketplace-connections` отвечает только на `provider=ozon`, на
`wildberries` — 422 `unsupported_provider`. wb-fetcher неоткуда взять кабинеты и токены.

## 2. Факты из кода (02.11.2026)

- `ActiveConnectionCredentialsQuery::fetchActive(MarketplaceType)` уже принимает провайдера,
  расшифровывает ключ, сбойный ключ → `secret = null` + один агрегированный ERROR. Для WB
  переиспользуется без изменений.
- У подключения WB `client_id` пуст (`MarketplaceConnection` WB хранит только токен);
  `connection_type` — `seller`. Уникальный индекс `(company_id, marketplace, connection_type)`
  (Version20260415000000) — не больше одного WB-подключения на компанию.
- Symfony держит лимит отчёта WB на бакете `connection:<id подключения>`
  (`WbFinanceRateLimiter::resolveSalesReportsBucket`): продавца по токену не различает.
- Схема v1: `seller.client_id` — непустая строка; `provider` уже допускает `wildberries`.

**Вывод (уточняет решение 2 плана):** `credentials.seller.client_id` у WB = id подключения
(та же единица лимита, что в Symfony). Пункт исходного плана «одинаковый `client_id` у двух
подключений одного продавца» недостижим — Symfony продавца не знает; если два кабинета с
токенами одного продавца появятся, WB вернёт 429 и сервис отработает паузу (как сейчас Symfony).

## 3. Что будет результатом работы

| Что | Где |
|---|---|
| `WbConnectionsSnapshotBuilder` (`final readonly`): строки `fetchActive(WILDBERRIES)` → кабинеты; `connection_ref` = id подключения; `seller = {client_id: id, api_key: токен}`, `performance: null`; `resource_types` — `wb.finance.sales-report.daily`, `wb.finance.sales-report.weekly` компаниям из allowlist, иначе `[]`; кабинет без пригодного токена не выдаётся; сортировка по ref | `src/Marketplace/Application/Service/` |
| Параметр `wb_fetcher.finance_companies: []` (`['*']` — всем) | `config/services.yaml` |
| Контроллер: `ozon` → прежний builder, `wildberries` → новый, иное → 422 `unsupported_provider` (сообщение перечисляет оба) | `MarketplaceConnectionsController` |
| CHANGELOG/README контракта: ресурсы WB, смысл `client_id` у WB; схема не меняется | `services/_contracts/marketplace-connections/v1/` |
| `ARCHITECTURE.md` — внутренний API Marketplace + Changelog | |

Отдельный builder, а не ветка в Ozon-builder: у Ozon склейка seller+performance по компании и
десяток allowlist, у WB — один ключ и один список; общего кода — `usable()` и сортировка.

## 4. TDD — тесты пишутся первыми (красные до кода)

Unit `WbConnectionsSnapshotBuilderTest`:
- [ ] подключение с токеном → кабинет: ref = id, `provider = wildberries`, `client_id = id`, `api_key = токен`, `performance = null`
- [ ] компания из allowlist → оба `wb.finance.*`; вне списка → `[]`; `['*']` — всем
- [ ] токен null/пустой/пробелы → кабинет не выдаётся
- [ ] performance-строка WB (теоретически) игнорируется
- [ ] кабинеты отсортированы по ref; `toArray()` по контракту

Functional `MarketplaceConnectionsControllerTest`:
- [ ] `provider=wildberries` с токеном → 200, ключи ответа по схеме v1, `no-store`; Ozon-кабинеты не попадают; неактивное WB-подключение не выдаётся
- [ ] нерасшифровываемый токен WB → только этот кабинет выпадает, остальные выдаются
- [ ] `provider=yandex` → 422 `unsupported_provider` (бывший тест «wildberries → 422» переписан)
- [ ] без токена сервиса `provider=wildberries` → 401
- [ ] регрессия: `testServesActiveOzonCabinetsByContract` без изменений ожиданий
- [ ] лог `Internal connections snapshot served` — без токенов (проверка записей тестового логгера)

## 5. Чек-лист приёмки

- [ ] тесты §4 написаны и были красными до кода
- [ ] `wb_fetcher.finance_companies` в `services.yaml`, `[]` по умолчанию (прод не получает ресурсов до этапа 5)
- [ ] CHANGELOG/README контракта; `schema.json` не изменён
- [ ] IDOR: новый запрос не добавлен — используется `ActiveConnectionCredentialsQuery` (`@companyScopeExempt` с причиной есть); builder не обращается к данным
- [ ] токены не логируются; ответ `Cache-Control: no-store`
- [ ] `ARCHITECTURE.md` — текущее состояние + строка Changelog
- [ ] `make site-stan` (baseline не растёт), `site-cs-check`, `site-cs-strict-types`, unit + functional тесты Marketplace зелёные
- [ ] внешнее ревью (Codex), BLOCKER/IMPORTANT закрыты
