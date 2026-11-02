### Stage 2: `wb-fetcher` — каркас и ежедневный отчёт — TODO

**Risk:** MEDIUM · **Branch:** `feat/wb-fetcher-daily`
**Внешнее ревью:** нет (на handoff)

## 1. Какую задачу решаем

Нужен сервис, который по WB-кабинетам из Symfony грузит дневной детальный отчёт о
реализации в raw S3 с журналом и отдаёт покрытие.

## 2. Что будет результатом работы

`services/wb-fetcher` по `services/README.md` «Как добавить новый сервис»:

- `cmd/wb-fetcher/main.go` — wiring `fetchkit` + адаптер WB, graceful shutdown;
- `internal/config` — env: `DB_DSN`, `S3_*`, `SYMFONY_INTERNAL_BASE_URL`, `INTERNAL_AUTH_TOKEN`,
  `SERVICE_API_TOKEN`, `SENTRY_*`, `CONNECTION_ALLOWLIST`, `MAX_PARALLEL_CONNECTIONS`,
  `WB_FINANCE_BASE_URL` (по умолчанию `https://finance-api.wildberries.ru`), `WB_DAILY_TAIL_DAYS` (14, 1…30),
  `WB_FINANCE_MIN_INTERVAL` (70s), `PLAN_EVERY` (1h);
- `internal/wb` — клиент `finance-api`: `POST /api/finance/v1/sales-reports/detailed`,
  `Authorization: <токен>`, тело `{dateFrom, dateTo, limit: 100000, rrdId, period}`, пагинация по `rrdId`,
  классификация ответов (200/204/400/401/403/404/410/429/5xx), разбор `Retry-After` / `X-Ratelimit-Retry` / `X-Ratelimit-Reset`;
- `internal/resources` — описание `wb.finance.sales-report.daily` и расписание: вчера каждый проход,
  хвост 14 дней раз в сутки, пропуск минут :10 и :20 (решение 3 плана);
- `internal/store/migrations` — схема, совместимая с `fetchkit` (одна начальная миграция);
- `docs/wb-api.md` — хост, категория токена «Финансы», метод, запрос, пагинация, лимит, тип, расписание;
- `AGENTS.md`, `README.md`, `docker/Dockerfile`, `.env.example`, `.golangci.yml`;
- dev: `services-wb-fetcher`, `-dev`, `-lint` в `docker-compose.yml` (профиль `services`),
  БД и роль в `services/docker/postgres/init/`, таргеты `make wb-fetcher-*`;
- мок `finance-api` для стенда (`test/stand/mock`).

## 3. Что считаем выполненной работой

На стенде с моком сервис забирает кабинеты из локального Symfony, планирует дни,
грузит страницы в S3 (`wb/…`), пишет журнал; `/internal/coverage` показывает день
как `done`; 429 переводит задачу в повтор с паузой из заголовка; рестарт посреди
пагинации продолжает с сохранённого `rrdId`.

## 4. TDD — тесты пишутся первыми

Клиент (`internal/wb`, `httptest.Server`):
- [ ] запрос: метод, путь, заголовок `Authorization`, тело с `period=daily`, `dateFrom=dateTo`
- [ ] 200 со списком → строки и `nextRrdId` = `rrd_id` последней строки
- [ ] страница ровно `limit` строк → `hasMore=true`; меньше — `false`
- [ ] 204 и `[]` → пустая страница, конец
- [ ] `rrd_id` последней строки ≤ текущего курсора → ошибка формы (не тихая обрезка)
- [ ] 429 с `X-Ratelimit-Retry: 30` → rate_limited, пауза 30 с; с `Retry-After`; без заголовков → 70 с
- [ ] 401/403 → auth (пауза ресурса кабинета); 400 → permanent; 5xx и таймаут → retryable
- [ ] 404/410 или текст о выводе метода → `obsolete_endpoint`
- [ ] не-JSON и не-список → ошибка формы, raw всё равно сохраняется
- [ ] токен отсутствует в логах и в тексте ошибок (тестовый `slog` handler)

Расписание (`internal/resources`, фиксированные часы):
- [ ] 03:00 МСК → задача на вчера; хвост 14 дней — один раз за сутки
- [ ] второй проход в те же сутки хвост не ставит, вчера — ставит, если не `done`
- [ ] граница суток по МСК, не UTC (проход 00:30 МСК = 21:30 UTC)
- [ ] кабинет без ресурса в `resource_types` → задач нет
- [ ] слот :10 / :20 → диспетчер не берёт задачи WB finance

Исполнитель (интеграция, `storetest` + мок S3):
- [ ] «первая страница и тишина»: 1 полная страница, затем 204 → 2 запроса, 1 raw, `done`
- [ ] 3 страницы → 3 raw, курсор после каждой в одной транзакции с raw
- [ ] падение после страницы 2, рестарт → запрос с курсором страницы 2, дублей raw нет
- [ ] перезагрузка дня с тем же содержимым → `unchanged`, ревизия не растёт; с другим → ревизия +1
- [ ] предел страниц превышен → `failed_permanent`, ERROR
- [ ] два кабинета одного продавца → не чаще 1 запроса / `WB_FINANCE_MIN_INTERVAL`

HTTP API:
- [ ] `/internal/coverage` с днём `done` → строка `wb.finance.sales-report.daily`, `cabinets_expected` из снимка; без токена → 401

## 5. Чек-лист приёмки

- [ ] все тесты §4 были красными до реализации
- [ ] раскладка и файлы по `services/go.md`, чек-лист `services/README.md`
- [ ] `wb-fetcher` использует `fetchkit`, ядро не скопировано
- [ ] `docs/wb-api.md` заполнен; версия метода сверена с документацией WB на дату этапа
- [ ] стенд: мок + локальный Symfony → raw в seaweedfs (`wb/…`), журнал, покрытие (память «Локальный S3»)
- [ ] гейты `go vet`, `golangci-lint`, `go test -race` (по пакету, память OOM)
- [ ] строка в таблице «Сервисы» `services/README.md`
