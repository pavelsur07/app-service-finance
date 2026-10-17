### Stage 7: миграция и очередь задач — DONE

**Risk:** HIGH-LOCAL (миграция, конкурентный захват)
**Stage base commit:** `980b27186`
**Branch:** `feat/ozon-fetcher-store` (PR-группа 4, поверх группы 3)
**Внешнее ревью:** да — в конце этапа

#### Цель
Надёжная очередь задач загрузки: задача ставится идемпотентно, один кабинет
никогда не обрабатывается двумя воркерами одновременно, упавший воркер не
держит задачу вечно.

#### Результат
Миграция `00002_ingestion.sql` (forward-only, без сумм и ПДн):

| Таблица | Назначение |
|---|---|
| `fetch_task` | что должно быть загружено: `(connection_ref, resource_type, period_key)`, статус, приоритет, попытки, `next_run_at`, lease, курсор и номер следующей страницы, `content_hash`, `revision` |
| `fetch_attempt` | append-only журнал попыток: время, исход, класс ошибки, HTTP-статус, страницы, байты |
| `raw_object` | объект S3 на страницу попытки: `storage_uri`, sha256, размер |
| `connection_state` | по кабинету: `last_served_at` (честная очередь), пауза `suspended_until` + причина |
| `connection_limit` | по кабинету и endpoint'у: ручное `rps_override`, адаптированная `rps_current` |
| `resource_stop` | ресурс, остановленный для всех кабинетов (`obsolete_endpoint`) |

Пакет `internal/store` — репозиторий: `UpsertTask`, `ClaimNext`,
`ExtendLease`, `RecordPage`, `FinishTask`, `ReleaseExpired`, лимиты и
пауза кабинета, стоп ресурса.

#### Решения
- **Один кабинет — один воркер** — гарантирует БД: частичный уникальный
  индекс `fetch_task (connection_ref) WHERE status = 'running'`. Гонка двух
  захватов кончается unique violation у второго → «задачи нет», без двойной
  работы.
- Захват — `FOR UPDATE SKIP LOCKED` по готовым задачам: статус
  `pending | not_ready | failed_retryable`, `next_run_at <= now()`, кабинет
  в активном снимке, без паузы, ресурс не остановлен. Порядок:
  `priority DESC`, `connection_state.last_served_at ASC NULLS FIRST`,
  `next_run_at ASC`.
- Lease — `lease_owner` + `lease_until`; просроченный `running` возвращается в
  `pending` (`ReleaseExpired`) с сохранённым курсором.
- `UpsertTask` не трогает `running`; для `done` переводит в `pending` только
  при запросе перезагрузки (`refresh = true`); приоритет — максимум из
  старого и нового.
- Страница и курсор — одна транзакция (`RecordPage`): `raw_object` + курсор
  + номер следующей страницы.
- Время — `timestamptz`, расчёты «сейчас» — параметром (тесты).

#### Work items
- **7.1** — миграция `00002_ingestion.sql`.
- **7.2** — репозиторий: постановка, захват, lease, страница, финал.
- **7.3** — лимиты, пауза кабинета, стоп ресурса.
- **7.4** — интеграционные тесты на postgres: конкурентный захват (N
  горутин, один кабинет), просроченный lease, идемпотентный upsert,
  порядок выдачи, пауза и стоп ресурса, курсор после перехвата.
- **7.5** — гейты, внутреннее и внешнее ревью, коммит.

#### Definition of Done / чек-лист готовности
- [x] миграция накатывается на чистую БД; повторный накат — no-op
- [x] в схеме нет сумм, ПДн, ключей
- [x] N параллельных захватов: у одного кабинета не больше одной `running`
- [x] просроченный lease перехватывается, курсор и номер страницы сохраняются
- [x] повторный upsert не создаёт дублей и не сбрасывает `running`
- [x] порядок: приоритет → давно не обслуживавшийся кабинет → время
- [x] кабинет на паузе и остановленный ресурс не выдаются
- [x] страница и курсор — одна транзакция
- [x] `go vet`, `golangci-lint`, `go test -race` зелёные
- [x] README: схема БД
- [x] внутреннее ревью: BLOCKER/IMPORTANT нет; внешнее ревью — green

#### What was done
- 7.1 — `00002_ingestion.sql`: 6 таблиц; частичный уникальный индекс
  «одна `running` на кабинет», индекс выдачи, индекс lease;
  `CHECK (status = 'running') = (lease_until IS NOT NULL)`.
- 7.2–7.3 — `store.Tasks`: `Upsert` (идемпотентно; `Refresh` поднимает `done`
  и `failed_permanent`), `ClaimNext` (`SKIP LOCKED`, unique violation →
  `ErrNoTask`), `ExtendLease`, `RecordPage` (страница + курсор одной
  транзакцией), `FinishTask` (ревизия по `content_hash`, `KeepCursor`),
  `ReleaseExpired`; лимиты, пауза кабинета, стоп ресурса.
- 7.4 — 11 интеграционных тестов на PostgreSQL 15 (`TEST_DB_DSN`), `-race`.

#### Checks
- `go vet`, `golangci-lint`, `go test -race` (с `TEST_DB_DSN`) — OK

#### Internal review
- MINOR fixed: `Refresh` не поднимал `failed_permanent` — плановая
  перезагрузка не повторяла задачи, упавшие на ответе Ozon

#### External review
- round 1 (Codex, medium): 2 IMPORTANT в `ReleaseExpired` — обратный порядок
  блокировок (deadlock с воркером) и две отдельные выборки (гонка с
  `ExtendLease`) → один запрос: задачи `FOR UPDATE SKIP LOCKED` → освобождение →
  закрытие ровно этих попыток. Регрессионный тест гонки: на старом коде 188
  нарушений за 5 прогонов, на новом — 0. **Fixed without re-run** (§7.2)

#### Next
- Stage 8
