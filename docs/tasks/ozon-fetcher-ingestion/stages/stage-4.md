### Stage 4: ресурсы Ozon первой волны — DONE

**Risk:** MEDIUM
**Stage base commit:** `d05ad0ada`
**Branch:** `feat/ozon-fetcher-client`
**Внешнее ревью:** нет (на handoff PR-группы 3)

#### Цель
Сервис умеет выгрузить каждый из 4 ресурсов первой волны и отдать исходные
байты постранично — так, чтобы исполнитель мог сохранить страницу до запроса
следующей и продолжить с курсора после сбоя.

#### Результат
В пакете `internal/ozon`:
- `Page{No, Body, Cursor, ContentType}` — исходные байты ответа
- `FetchAccrualByDay(ctx, creds, ref, day, fromCursor, onPage)` — пагинация
  `last_id`; `onPage` вызывается на каждой странице до запроса следующей,
  его ошибка останавливает обход; проверка формы (`accruals` — массив),
  повтор курсора и предел 100 страниц → `invalid_response`
- `FetchAccrualTypes` — проверка `accrual_types` — массив
- `FetchRealization(year, month)` — пустой `result.rows` и 4xx → `not_ready`
- `FetchMutualSettlement(year, month)` — заказ отчёта → опрос
  `/v1/report/info` → скачивание файла (GET без ключей, только https);
  не готов к дедлайну опроса → `not_ready`; `failed` → `transient`
- точка подключения лимитера: интерфейс `Limiter` (`Wait`/`Observe`) в
  `Client.Post`, по умолчанию no-op (реализация — этап 5)

#### Решения
- Страница отдаётся **исходными байтами**: sha256 считается от них, raw в S3 —
  ровно то, что прислал Ozon.
- `not_ready` у realization по 4xx — как в `SyncOzonRealizationHandler`: Ozon
  отвечает ошибкой клиента на ещё не сформированный месяц.
- mutual-settlement: форма ответа **не подтверждена на проде** (клиент
  Symfony не работал). Разбор терпимый, как в последней версии PHP-клиента:
  код — `result.code` | `code` | `report_code`; статус — `result.status` |
  `status`. Подтверждение — на стенде (этап 13) и при выводе на прод:
  обращаться к Ozon с боевыми ключами клиентов с dev-машины нельзя без
  решения Владельца.
- Ссылка на файл — только `https://`; иначе `invalid_response`.

#### Work items
- **4.1** — `Limiter` + интеграция в `Post` (no-op по умолчанию).
- **4.2** — by-day и accrual-types + тесты (фикстуры, продолжение с курсора,
  остановка по ошибке `onPage`, повтор курсора, предел страниц, битая форма).
- **4.3** — realization + тесты (успех, пустые `rows`, 4xx → `not_ready`).
- **4.4** — mutual-settlement + тесты (полный цикл, опрос до готовности,
  `failed`, дедлайн, не-https ссылка, файл больше лимита).
- **4.5** — гейты сервиса, коммит.

#### Definition of Done / чек-лист готовности
- [x] by-day: все страницы с курсорами, `onPage` до следующего запроса
- [x] by-day: старт с переданного курсора
- [x] by-day: повтор курсора, > 100 страниц, нет `accruals` → `invalid_response`
- [x] by-day: ошибка `onPage` останавливает обход и возвращается как есть
- [x] accrual-types: нет `accrual_types` → `invalid_response`
- [x] realization: пустые `rows` и 4xx → `not_ready`; 429/5xx/401 — свои классы
- [x] mutual-settlement: заказ → опрос → файл; `failed` → `transient`; дедлайн → `not_ready`
- [x] mutual-settlement: ссылка не https → `invalid_response`; файл > лимита → `invalid_response`
- [x] эталонные sha256 страниц в тестах совпадают с байтами фикстур
- [x] `Limiter.Wait` вызывается до каждого запроса, `Observe` — после
- [ ] mutual-settlement подтверждён на реальном ответе Ozon — **перенесено** на этапы 13/14 (нет разрешённого доступа)
- [x] `go vet`, `golangci-lint`, `go test -race` зелёные

#### What was done
- 4.1 — `Limiter` (`Wait`/`Observe`) в `Client.Post`, no-op по умолчанию, `WithLimiter`.
- 4.2 — `FetchAccrualByDay` (курсор, `onPage` до следующего запроса, повтор
  курсора, предел 100 страниц, проверка формы), `FetchAccrualTypes`.
- 4.3 — `FetchRealization`: пустые/отсутствующие `rows` и 4xx → `not_ready`.
- 4.4 — `FetchMutualSettlement`: заказ → опрос `/v1/report/info` (×2 до
  `Max`, дедлайн) → GET файла без ключей, только https; `failed` → `transient`,
  истёкшая ссылка → `not_ready`.
- Тесты на обезличенных фикстурах `testdata/`, эталонные sha256.
- **Не выполнено в этапе:** подтверждение формы mutual-settlement на реальном
  ответе Ozon — нет разрешённого доступа к кабинету; перенесено на этапы 13/14.
  Пункт чек-листа отмечен как «перенесено», не как проверенный.

#### Checks
- `go vet`, `golangci-lint` (0 issues после G304 в тесте), `go test -race` — OK

#### Next
- Stage 5
