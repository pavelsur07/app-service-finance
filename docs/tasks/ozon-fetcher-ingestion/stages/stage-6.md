### Stage 6: получение кабинетов в сервисе — DONE

**Risk:** MEDIUM
**Stage base commit:** `2e0af24ab`
**Branch:** `feat/ozon-fetcher-client`
**Внешнее ревью:** нет (на handoff PR-группы 3)

#### Цель
Сервис всегда знает актуальный список кабинетов и их ключи, в том числе при
недоступности Symfony.

#### Результат
Пакет `internal/connections`:
- `Client.Fetch(ctx)` — `GET {SYMFONY_INTERNAL_BASE_URL}/internal/marketplace-connections?provider=ozon`
  с `Authorization: Bearer {INTERNAL_AUTH_TOKEN}`, проверка ответа по
  контракту v1 (`schema_version = "1"`, обязательные поля, `provider = ozon`)
- `Cabinet{Ref, CompanyID, UpdatedAt, Seller *ozon.Credentials,
  HasPerformance, ResourceTypes}` — секрет performance в памяти не хранится:
  сервису он не нужен
- `Cache`: `Refresh` (успех — полная замена снимка; сбой — последний снимок +
  warning), `Run(ctx, interval)`, `List`, `Get(ref)`,
  `ForceRefresh(ctx)` для `auth` — не чаще раза в `ForceRefreshMinInterval`
- до первого успешного снимка `List` пуст, `Ready()` = false: сервис ждёт, не падает
- конфиг: `SYMFONY_INTERNAL_BASE_URL`, `INTERNAL_AUTH_TOKEN`,
  `CONNECTIONS_POLL_INTERVAL` (по умолчанию 5m)

#### Решения
- Снимок — полный (контракт v1): кабинет, исчезнувший из ответа, исчезает из
  `List`; его задачи в журнале не удаляются — исполнитель их просто не
  выдаёт (этап 10).
- Битый ответ (неизвестная `schema_version`, нет обязательного поля)
  отвергается целиком, кэш не портится; один битый кабинет в валидном
  снимке — пропускается с warning (по `connection_ref`).
- Форс-обновление ограничено по частоте: 401 у нескольких кабинетов подряд не
  превращается в шторм запросов к Symfony.
- В логах — число кабинетов, длительность, статус; без ключей и тела.

#### Work items
- **6.1** — `config`: новые env + валидация (интервал > 0, URL, токен ≥ 32).
- **6.2** — `connections.Client` + разбор и проверка снимка.
- **6.3** — `Cache` + `Run` + `ForceRefresh`.
- **6.4** — тесты на `httptest`: успех, 5xx (последний кэш), 401, битая схема,
  неизвестная версия, битый кабинет, исчезнувший кабинет, форс-обновление и его
  ограничение, отмена `Run`; `-race`.
- **6.5** — гейты сервиса, коммит.

#### Definition of Done / чек-лист готовности
- [x] запрос с Bearer-токеном и `provider=ozon`
- [x] успешный снимок полностью заменяет кэш
- [x] 5xx/сеть/401 — кэш прежний, warning, `Ready()` не меняется
- [x] неизвестная `schema_version` или битый JSON — снимок отвергнут
- [x] битый кабинет пропущен, остальные приняты
- [x] исчезнувший кабинет пропадает из `List`
- [x] `ForceRefresh` не чаще `ForceRefreshMinInterval`
- [x] `Run` останавливается по отмене контекста, без утечки горутин
- [x] секрет performance в `Cabinet` не хранится; ключи не в логах
- [x] `go vet`, `golangci-lint`, `go test -race` зелёные

#### What was done
- 6.1 — `config`: `SYMFONY_INTERNAL_BASE_URL` (пусто — режим scaffold),
  `INTERNAL_AUTH_TOKEN` (≥ 32), `CONNECTIONS_POLL_INTERVAL` (> 0, по умолчанию 5m).
- 6.2 — `connections.Client.Fetch` + `parse`: снимок v1, битый кабинет и дубль
  ref отвергаются поштучно, битый снимок — целиком.
- 6.3 — `Cache`: `Refresh`, `Run`, `List` (копия), `Get`, `Ready`,
  `ForceRefresh` (не чаще 30 с).
- **Находка по ходу:** `slog.JSONHandler`/`encoding/json` сериализуют
  вложенные структуры через `json.Marshal`, минуя `LogValue` — ключ утёк бы
  при логировании `Cabinet`. Добавлен `ozon.Credentials.MarshalJSON` со
  скрытым ключом (коммит этапа 4), тест `TestCabinetCredentialsAreNotLogged`.

#### Checks
- `go vet`, `golangci-lint`, `go test -race` — OK

#### Next
- handoff PR-группы 3 (этапы 3–6): внешнее ревью, PR
