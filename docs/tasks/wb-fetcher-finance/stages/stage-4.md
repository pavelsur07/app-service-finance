### Stage 4: покрытие нескольких сервисов, вкладки Ozon | Wildberries — DONE

**Risk:** MEDIUM (cron обновления витрины, страница админки) · **Ветка:** `feat/wb-fetcher-finance` (одна на задачу, PR на handoff)
**Внешнее ревью:** нет (на handoff)

## 1. Какую задачу решаем

`ServiceGateway` знает один сервис: `OzonFetcherCoverageClient` (адрес и токен
ozon-fetcher зашиты в класс), команда `app:service-gateway:coverage:refresh` берёт
единственный клиент, страница — `/admin/services/ozon-fetcher/coverage`, список отчётов
тепловой карты — только Ozon. wb-fetcher (этапы 2–3) отдаёт тот же контракт
`service-coverage` v1.1, но показать его негде.

## 2. Факты из кода (02.11.2026)

- Витрина уже многосервисная: `service_coverage_snapshot`/`service_coverage_row` по ключу
  `service`, `RefreshServiceCoverageAction` заменяет строки одного сервиса
  (`deleteByService`), `ServiceCoverageMonthQuery::fetch($service, $month)`.
- Клиент: проверка `$.service` == имя сервиса, 2 с idle / 5 с всего, токен направления
  «Symfony → сервис», лог без тела. Отличие WB только в имени сервиса и env.
- Команда: `LockableTrait` (один lock на команду), ServiceUnavailable → WARNING и exit 0,
  ContractViolation → ERROR и exit 1, выключен → INFO.
- `CoverageHeatmapBuilder::RESOURCES` — обязательные строки Ozon показываются даже без
  ячеек: на вкладке WB появились бы пустые строки Ozon. Нужен список по сервису.
- Тепловая карта — CSS-сетка дней (`--cov-days`); месячная ячейка растягивается на все дни
  (`sgw-cov-span`). Недельной ячейке нужен свой размах — число дней недели в месяце.
- `ServiceCoverageMonthQuery` выбирает дни и ключ месяца; ключи `YYYY-Www` не попадают.
- Меню: `templates/admin/partials/_sidebar.html.twig` — ссылка и подсветка по имени маршрута.

## 3. Что будет результатом работы

| Что | Где |
|---|---|
| `ServiceCoverageHttpClient` (`final readonly`) — бывший `OzonFetcherCoverageClient` с параметрами `service`, `baseUrl`, `token`; два сервиса в `services.yaml`: `ozon-fetcher` (`OZON_FETCHER_BASE_URL/_API_TOKEN`), `wb-fetcher` (`WB_FETCHER_BASE_URL/_API_TOKEN`), тег `app.service_coverage_client` | `ServiceGateway/Infrastructure/Client`, `config/services.yaml` |
| `CoverageServices` — реестр сервисов покрытия: имя → подпись вкладки (`ozon-fetcher` → Ozon, `wb-fetcher` → Wildberries), порядок вкладок | `ServiceGateway/Application/Service` |
| Команда обходит все клиенты (`AutowireIterator`): lock на сервис, сбой одного не мешает другим, exit 1 если хоть у одного нарушение контракта | `ServiceCoverageRefreshCommand` |
| Query: недели `YYYY-Www`, пересекающие месяц (ключи считаются в PHP, `IN`) | `ServiceCoverageMonthQuery` |
| Builder: шаг `week` — ячейка на каждую неделю, пересекающую месяц, с размахом = дней недели в месяце; неделя «будущая», пока не закончилась; обязательные строки — по сервису (WB: «Отчёт о реализации — день», «— неделя») | `CoverageHeatmapBuilder`, `CoverageHeatmapCell::$span` |
| Страница `/admin/services/coverage?service=…&month=…`: вкладки сервисов (Ozon \| Wildberries, `_tabs`), месяц сохраняется при переключении; неизвестный `service` → 404; месяц вне окна → redirect с flash на тот же сервис | `ServiceCoverageController`, шаблон `admin/service_gateway/coverage.html.twig` |
| Старый URL `/admin/services/ozon-fetcher/coverage` → 301 на `?service=ozon-fetcher` (с `month`) | `OzonFetcherCoverageController` → редирект |
| Меню «Покрытие Сервис GO» → новая страница | `_sidebar.html.twig` |
| `.env` — `WB_FETCHER_BASE_URL=`, `WB_FETCHER_API_TOKEN=` (пусто — выключено) | |
| `ARCHITECTURE.md` (ServiceGateway) + Changelog; `docs/architecture/service-gateway.md` | |

Прод-значения env и деплой wb-fetcher — этап 5. До него WB-клиент выключен (пустые env),
вкладка Wildberries показывает пустое состояние с подсказкой про `WB_FETCHER_API_TOKEN`.

## 4. TDD — тесты пишутся первыми

Unit клиента (`ServiceCoverageHttpClientTest`, бывший `OzonFetcherCoverageClientTest`):
- [x] прежние случаи (успех, не-200, транспорт, таймаут, не JSON, `rows` объектом, чужой `service`, выключен) — на клиенте `ozon-fetcher`
- [x] клиент `wb-fetcher`: запрос на свой адрес и токен; ответ с `service: wb-fetcher` и `period_key` `2026-W44` принимается; `service: ozon-fetcher` → нарушение контракта

Integration команды:
- [x] два клиента → витрина обоих сервисов, вывод по строке на сервис
- [x] WB 503 → строки WB прежние, Ozon обновлён, WARNING, exit 0
- [x] WB нарушение контракта → Ozon обновлён, exit 1
- [x] WB выключен → INFO по WB, Ozon обновлён

Integration Query:
- [x] ноябрь 2026: строки `2026-W44` (26.10–01.11) и `2026-W49` (30.11–06.12) попадают, `2026-W43` и `2026-W50` — нет

Unit builder:
- [x] WB, ноябрь 2026, сейчас 02.11: строки «Отчёт о реализации — день» и «— неделя», строк Ozon нет
- [x] недельная строка ноября: 6 ячеек W44…W49, размахи 1,7,7,7,7,1 (сумма = 30 дней); W44 — `ok` по данным, W45…W49 — `future`
- [x] вкладка Ozon: обязательные строки Ozon как раньше, строк WB нет
- [x] процент покрытия учитывает наступившие недели

Functional страницы:
- [x] `/admin/services/coverage?service=wb-fetcher` — 200 админу, данные витрины WB (ячейка `1/1` недели), активная вкладка Wildberries; 403 пользователю
- [x] без `service` — Ozon; `service=foo` → 404
- [x] ссылки вкладок сохраняют `month`; месяц вне окна → redirect на тот же сервис с flash
- [x] прежние проверки страницы Ozon (устаревание, пустое состояние, месяцы) — на новом URL
- [x] старый URL → 301 на `/admin/services/coverage?service=ozon-fetcher` (и с `month`)

## 5. Чек-лист приёмки

- [x] тесты §4 написаны до кода (красные: нет `ServiceCoverageHttpClient`, `IsoWeeks`, `CoverageServices`, маршрута; регрессия сущности — красная отдельно: `ServiceCoverageRow` отвергал `2026-W44`)
- [x] `.env` — `WB_FETCHER_*` пустые; клиент без значений выключен
- [x] Query — один запрос на сервис-месяц, без N+1
- [x] визуальная проверка обеих вкладок на локальном стенде (fpm+nginx в сети `asfwb-net`, досев витрины SQL): Ozon — как раньше; WB октябрь — недели полосами по своим дням; WB ноябрь — W44 1/2, будущие «ещё нет»; старый URL → вкладка Ozon. Найдено и исправлено: недельная ячейка обрезалась до 32 px (`max-width` кита)
- [x] дизайн-система: вкладки `_tabs`, цвета ячеек — классы кита `coverage.css`, без хардкода (`CLAUDE.frontend.md`)
- [x] `ARCHITECTURE.md` + Changelog, `service-gateway.md`
- [x] PHPStan / CS / strict_types по изменённым файлам; baseline не растёт

## 6. Итог (02.11.2026)

- Найден дефект этапа 3: `ServiceCoverageRow` (Entity) проверяла `period_key` старым шаблоном —
  снимок wb-fetcher с неделями падал бы в `RefreshServiceCoverageAction`. Исправлено, регрессия
  `RefreshServiceCoverageActionTest::testStoresWeeklyRowsOfWbFetcher`.
- Прод: `WB_FETCHER_BASE_URL/_API_TOKEN` — этап 5; до него вкладка WB пустая с подсказкой.
