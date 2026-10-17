### Stage 3: транспорт Ozon и классы ошибок — DONE

**Risk:** MEDIUM
**Stage base commit:** `945fd80e1`
**Branch:** `feat/ozon-fetcher-client` (PR-группа 3, поверх группы 2)
**Внешнее ревью:** нет (на handoff PR-группы 3)

#### Цель
Любой ответ Ozon однозначно превращается в «успех» или один из классов
ошибок; ключи кабинета передаются в вызов и нигде не оседают.

#### Результат
Пакет `services/ozon-fetcher/internal/ozon`:
- `Credentials` (`ClientID`, `APIKey`; `String()`/`LogValue()` скрывают ключ)
- `Client.Post(ctx, creds, endpoint, body) (Response, error)`: POST JSON на
  `https://api-seller.ozon.ru`, таймаут, лимит размера тела, исходные байты
  ответа + статус + длительность
- `*APIError{Class, Status, Code, RetryAfter}` + `errors.As`; классы:
  `rate_limit`, `transient`, `auth`, `obsolete_endpoint`, `invalid_request`
  (+ `invalid_response`, `not_ready` — константы для этапа 4)
- `Classify(status, header, body)` — чистая функция по таблице
  `docs/ozon-api.md`
- лог запроса (slog): `endpoint`, `status`, `duration_ms`, `outcome`,
  `connection_ref` — без тела, ключей и сумм

#### Решения
- Отмена родительского контекста — не ошибка Ozon: возвращается `ctx.Err()`
  без класса, чтобы graceful shutdown не считался сбоем кабинета.
- Сетевые ошибки и таймаут запроса — `transient`.
- `obsolete_endpoint` — 400 с `code = 9` **или** сообщением
  `obsolete method` (так Ozon ответил 09.09.2026).
- `Retry-After` — секунды или HTTP-date; отрицательное/битое — 0.
- Тело ошибки Ozon в `APIError` не хранится целиком: только `code` и
  обрезанное до 200 символов `message` для диагностики, в логи не пишется.
- Лимит тела ответа — 64 МиБ (страница by-day — килобайты; файл
  mutual-settlement скачивается отдельно на этапе 4).

#### Work items
- **3.1** — `Credentials`, `APIError`, `Classify` + table-driven тесты.
- **3.2** — `Client.Post` + тесты на `httptest.Server` (успех, все классы,
  таймаут, отмена контекста, лимит тела, заголовки, ключ не в логе).
- **3.3** — гейты сервиса (vet, lint, test -race), самопроверка, коммит.

#### Definition of Done / чек-лист готовности
- [x] 200 → успех с исходными байтами тела
- [x] 429 → `rate_limit` + `RetryAfter` (секунды и HTTP-date)
- [x] 5xx, сетевой сбой, таймаут → `transient`
- [x] 401, 403 → `auth`
- [x] 400 `code 9` / `obsolete method` → `obsolete_endpoint`
- [x] прочий 4xx → `invalid_request`
- [x] отмена родительского контекста → `context.Canceled`, не `APIError`
- [x] заголовки `Client-Id`, `Api-Key`, `Content-Type` из `Credentials`
- [x] ключ не попадает в лог и `fmt` (`%v`, `%+v`) `Credentials`
- [x] тело больше лимита → ошибка, а не обрезанные данные
- [x] `make ozon-fetcher-vet`, `-lint`, `-test` (`-race`) зелёные

#### What was done
- 3.1 — `Credentials` (`String`/`GoString`/`LogValue` скрывают ключ), `APIError`,
  `Classify` (секунды и HTTP-date в `Retry-After`, `code 9` и `obsolete method`).
- 3.2 — `Client.Post`: исходные байты, лимит 64 МиБ (больше — `invalid_response`
  + `ErrBodyTooLarge`), отмена ctx → `ctx.Err()`, лог без тела и ключей.
- Тесты: 17 веток `Classify`, обрезка сообщения, 5 классов через HTTP, таймаут,
  отмена, лимит тела, ключ не печатается ни в `fmt`, ни в slog.
- **Находка по ходу:** `make ozon-fetcher-test` (`-race`) не работал в
  `golang:1.27-alpine` — нет cgo. Dev-тулчейн переведён на `golang:1.27` +
  `CGO_ENABLED=1` (`docker-compose.yml`, только профиль `services`, не прод);
  правило записано в `services/go.md`.
- Зависимость: `github.com/stretchr/testify v1.12.1` (в проверенном стеке
  `go.md`; версия — минимальная, которую требует goose 3.29.0).

#### Checks
- `go vet ./...` — OK; `golangci-lint fmt` + `run` — 0 issues;
  `go test -race ./...` — OK

#### Internal review
- iterations: 1; MINOR fixed: gofmt, `S1025`, G101 на фиктивном ключе теста (nolint с причиной)

#### Next
- Stage 4 автоматически
