# wb-fetcher-finance: загрузка финансовых отчётов WB в Go-сервисе

Статус: **план на согласование**, 02.11.2026.
Основание: `docs/tasks/marketplace-data-ingestion/spec-wb.md` (§2 группа 3, §4, §5),
образец — `services/ozon-fetcher` и задача `docs/tasks/ozon-fetcher-coverage-admin`.

## Цель

Новый сервис `services/wb-fetcher` загружает по кабинетам WB **детальный отчёт о
реализации** — ежедневный и еженедельный — в raw S3 с журналом в своей БД. Админ
видит покрытие на странице «Покрытие Сервис GO» во вкладках **Ozon | Wildberries**.

## Что есть сейчас (снято 02.11.2026)

| Что | Где | Как |
|---|---|---|
| Детальный отчёт WB, день | Symfony: `Marketplace\Wildberries\Infrastructure\Api\WbFinanceSalesReportClient`, `Ingestion\…\WbFinanceReportClient` | `POST finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed`, `{dateFrom, dateTo, limit: 100000, rrdId, period: "daily"}`; курсор `rrdId` (последней строки, строго растёт); 204 / `[]` — конец; 429 → cooldown по `Retry-After` / `X-Ratelimit-Retry` / `X-Ratelimit-Reset`, по умолчанию 70 с |
| Лимит | `site/config/packages/rate_limiter.yaml` | **1 запрос в минуту на аккаунт продавца** (у нас 1 / 70 с), бакет — продавец, не подключение |
| Расписание | `docker/cron/app.cron` | 03:10 — вчера (`wb-financial-reports:sync --mode=daily`); ежечасно :20 — `orchestrate --refresh-days-back=14`; очередь `async_wb_finance`, до 20 ретраев |
| Еженедельный отчёт | — | **не грузится нигде** (`period: "weekly"` в коде не встречается) |
| Проверка ключа | `GET finance-api.wildberries.ru/ping` | `{"Status":"OK"}` |
| Кабинеты для сервисов | `GET /internal/marketplace-connections?provider=…` | Symfony отдаёт только `provider=ozon` (иначе 422 `unsupported_provider`); схема v1 уже допускает `wildberries` |
| Покрытие | `ServiceGateway`: `OzonFetcherCoverageClient`, витрина `service_coverage_row/_snapshot`, команда `app:service-gateway:coverage:refresh` (один сервис), страница `/admin/services/ozon-fetcher/coverage` | контракт `service-coverage` v1, ячейки: день `YYYY-MM-DD`, месяц `YYYY-MM`, `ref` |

## Границы

**Входит:** вынос общего ядра (требование spec-wb §5), сервис `wb-fetcher` с двумя
ресурсами, выдача WB-кабинетов из Symfony, покрытие и вкладки в админке, прод на
одном эталонном кабинете, затем на всех.

**Не входит:** нормализация, выдача фактов (`source-fact-envelope`), выключение
загрузки в Symfony (старый путь работает параллельно, переключение — отдельная
задача после 2–4 недель сверки, spec-wb §5), остальные ресурсы WB (заказы, реклама,
остатки, карточки, хранение, приёмка).

## Ресурсы

| `resource_type` | Запрос | `period_key` | Когда готов | Расписание |
|---|---|---|---|---|
| `wb.finance.sales-report.daily` | `detailed`, `period=daily`, `dateFrom=dateTo=D` | `YYYY-MM-DD` (МСК) | D+1 | вчера — каждый проход; хвост `WB_DAILY_TAIL_DAYS` (14) — раз в сутки (правки задним числом) |
| `wb.finance.sales-report.weekly` | `detailed`, `period=weekly`, `dateFrom`=пн, `dateTo`=вс | `YYYY-Www` (ISO, МСК) | после формирования WB (обычно пн) | прошлая неделя — с пн каждый проход до `done`; две предыдущие — раз в сутки |

Пустой ответ недельного отчёта до `WB_WEEKLY_READY_DEADLINE` (вт 23:59 МСК) —
`not_ready` с повтором, после — `done` с пустой ревизией. Ключ S3:
`wb/{connection_ref}/{resource_type}/{period_key}/{fetched_at}_{attempt}_p{NNN}_{sha8}.json.gz`.

## Решения Владельца (02.11.2026)

| # | Решение |
|---|---|
| В1 | S3: **тот же бакет `vashfindir-services-raw` и тот же пользователь бакета, что у ozon-fetcher**; разделение только префиксом ключа (`ozon/…`, `wb/…`). Отменяет пункт spec-wb §4.1 «свой пользователь бакета» |
| В2 | Каждый этап начинается с подробного плана этапа и чек-листа готовности (в `stages/stage-N.md`), затем выполняется без согласования |

## Решения, принятые в плане (по умолчанию; Владелец может переиграть до старта этапа)

| # | Решение | Почему |
|---|---|---|
| 1 | Сначала вынос ядра в `services/shared/fetchkit` (этап 0), потом `wb-fetcher` | spec-wb §5: «копирование ядра без выноса — нельзя»; каталог — по `services/go.md` «Монорепо» (`services/shared/`, свой `go.mod`, `replace`) |
| 2 | Контракт `marketplace-connections` остаётся v1: для WB `credentials.seller = {client_id: <ID бакета продавца>, api_key: <токен>}`, `performance: null` | схема v1 уже знает `wildberries`; `client_id` — идентификатор аккаунта продавца, как у Ozon, и он же ключ лимита WB (1/мин на продавца). Если ревью сочтёт это сменой семантики — v2 только для WB, этап 1 растёт на день |
| 3 | Лимит сервиса — 1 запрос / 70 с на продавца, общий для обоих ресурсов; минуты :10 и :20 часа сервис пропускает | на время параллельного прогона токен делят Symfony и сервис; не съесть бюджет `async_wb_finance` |
| 4 | Включение: allowlist `wb_fetcher.finance_companies` — сначала один эталонный кабинет, затем все | как у ozon-fetcher; эталон — крупный кабинет, не тестовый (память «тестовый кабинет нерепрезентативен») |
| 5 | Вкладки — одна страница `/admin/services/coverage?service=ozon-fetcher|wb-fetcher&month=`, старый URL — redirect | одна тепловая карта, без копии контроллера |

## Этапы

| # | Этап | Сторона | Риск | Внешнее ревью | Файл |
|---|---|---|---|---|---|
| 0 | Вынос ядра в `services/shared/fetchkit`, ozon-fetcher на нём без изменения поведения | Go | HIGH-LOCAL | **да** | `stages/stage-0.md` |
| 1 | Symfony отдаёт WB-кабинеты (`provider=wildberries`) | Symfony | HIGH-LOCAL (секреты во внутреннем API) | **да** | `stages/stage-1.md` |
| 2 | `wb-fetcher`: каркас и ежедневный отчёт | Go | MEDIUM | нет (на handoff) | `stages/stage-2.md` |
| 3 | Еженедельный отчёт | Go | MEDIUM | нет (на handoff) | `stages/stage-3.md` |
| 4 | Покрытие нескольких сервисов, вкладки Ozon \| Wildberries | Symfony / Twig | MEDIUM | нет (на handoff) | `stages/stage-4.md` |
| 5 | Прод: эталонный кабинет → все, приёмка | infra | HIGH-EXTERNAL | — (merge and deploy) | `stages/stage-5.md` |
| — | Handoff: полные гейты, внешнее ревью задачи, PR | | | **да** | `handoff.md` |

Каждый этап начинается с красных тестов из раздела «TDD» своего файла; код пишется
до зелёного; рефакторинг — при зелёных тестах.

## Готово, когда

- `ozon-fetcher` работает на `fetchkit`, прод-поведение не изменилось (ночь без новых ошибок, покрытие то же).
- `wb-fetcher` на проде по всем активным кабинетам WB: дневной отчёт — вчера и хвост 14 дней, недельный — прошлая и две предыдущие недели.
- 14 дней подряд дневное покрытие 100 % (или объяснённые исключения), недельное — 2 недели подряд.
- Сверка с Symfony: по эталонному кабинету за 14 дней число строк и множество `rrd_id` в raw сервиса совпадают с загрузкой Symfony за те же дни.
- Symfony-путь не стал получать больше 429, чем до включения (замер «до» — на этапе 5).
- Админ видит вкладки Ozon | Wildberries с тепловой картой.
- `services/wb-fetcher/docs/wb-api.md`, `docs/maintenance/wb-fetcher-prod.md`, `ARCHITECTURE.md`, `service-gateway.md` §4 — текущее состояние.

## Риски

| Риск | Что делаем |
|---|---|
| Вынос ядра меняет поведение ozon-fetcher | этап 0 — только перенос, golden-тесты плана и покрытия до/после, весь существующий набор тестов зелёный без правки ожиданий |
| `period=weekly` в `finance-api` ведёт себя иначе, чем ожидаем | первый шаг этапа 3 — сверка с документацией WB и пробный запрос на эталонном кабинете через wrapper; не подтвердилось — этап 3 стоп, вопрос Владельцу |
| Общий лимит 1/мин с Symfony | решение 3; на проде замер 429 в Symfony до/после; при росте — `rps_override` кабинету |
| Большой кабинет: >100 000 строк за неделю | пагинация `rrdId` с проверкой роста курсора и пределом страниц; тест «полная страница → обязан новый курсор» |
| Пустой ответ: «нет продаж» против «отчёт ещё не сформирован» | дедлайн готовности (решение в «Ресурсах»), `not_ready` до него |

## Связанные документы

- `docs/tasks/marketplace-data-ingestion/spec-wb.md` — ТЗ загрузки WB
- `services/ozon-fetcher/README.md`, `services/AGENTS.md`, `services/go.md`
- `docs/architecture/service-gateway.md` — правило шлюза и чек-лист нового сервиса (§3)
- `docs/maintenance/ozon-fetcher-prod.md` — образец прод-документа
- `services/_contracts/marketplace-connections/v1`, `services/_contracts/service-coverage/v1`
