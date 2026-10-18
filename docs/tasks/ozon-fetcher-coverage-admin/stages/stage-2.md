### Stage 2: модуль `ServiceGateway` — клиент, витрина, обновление — DONE

**Risk:** HIGH-LOCAL (новая миграция, вызов сервиса из Symfony) · **Base:** `536b8541c` · **Branch:** `feat/ozon-fetcher-coverage-admin`
**Внешнее ревью:** да (Codex, по итогу этапа)

## 1. Какую задачу решаем

Страница покрытия (этап 3) по правилу шлюза (`service-gateway.md` §2.6) не
ходит в сервис из запроса пользователя — она читает витрину в БД Symfony.
Нужен минимальный шлюз: клиент к `GET /internal/coverage` ozon-fetcher с
таймаутами, своим токеном и проверкой контракта, витрина и фоновое
обновление раз в 10 минут. Сбой сервиса не должен портить или опустошать
витрину.

## 2. Что будет результатом работы

Новый модуль `site/src/ServiceGateway`:

- **Клиент** `Infrastructure/Client/OzonFetcherCoverageClient` (+ интерфейс):
  `HttpClientInterface`, `timeout` 2 с / `max_duration` 5 с, Bearer из
  `OZON_FETCHER_API_TOKEN`, заголовок `X-Trace-Id` (UUID v7), строгий разбор
  ответа по `service-coverage/v1` в DTO `ServiceCoverageReport`:
  - нарушение контракта → `ContractViolationException`;
  - транспорт, таймаут, 401, 5xx → `ServiceUnavailableException`;
  - `OZON_FETCHER_BASE_URL` или токен пусты → клиент «выключен».
- **Витрина** — две Entity и одна миграция:
  - `ServiceCoverageSnapshot` (`service_coverage_snapshot`): по одной строке на
    сервис — `generated_at`, `refreshed_at`, `period_from`, `period_to`,
    `cabinets_total`. Отдельная таблица нужна, чтобы «данные на HH:MM» и
    `cabinets_total` были и при пустом списке строк (уточнение плана: там была
    одна таблица);
  - `ServiceCoverageRow` (`service_coverage_row`): `service`, `resource_type`,
    `period_key`, `cabinets_expected`, шесть счётчиков статусов,
    `last_success_at`; уникальный индекс `(service, resource_type, period_key)`.
  - Данные не принадлежат компании (сводка без кабинетов) → методы
    Repository с `@companyScopeExempt <причина>`.
- **Action** `Application/Action/RefreshServiceCoverageAction`: в одной
  транзакции удаляет строки сервиса и пишет снимок заново (≈ 100 строк).
- **Команда** `app:service-gateway:coverage:refresh` с блокировкой от
  параллельного запуска; логи старта и финиша с числом строк:
  - выключено → INFO и exit 0;
  - сервис недоступен → WARNING, exit 0 (supercronic шлёт любой ненулевой exit в GlitchTip), витрина прежняя;
  - нарушение контракта → ERROR (это баг), витрина прежняя.
- **Query** `Infrastructure/Query/ServiceCoverageMonthQuery`: снимок плюс строки
  месяца (дни месяца, `YYYY-MM`, `ref`) одной выборкой → readonly-DTO для
  этапа 3.
- **Cron** `*/10 * * * *` в `docker/cron/app.cron`.
- **Конфигурация:** `doctrine.yaml` (mapping), env в `.env` (пусто),
  `ModuleBoundaryRules` (если новый модуль требует регистрации).
  `routes.yaml` / `twig.yaml` — на этапе 3 вместе со страницей.
- **Документы:** раздел модуля в `ARCHITECTURE.md` (+ Cron, Changelog),
  §4 `service-gateway.md`.

## 3. Что считаем выполненной работой

`bin/console app:service-gateway:coverage:refresh` против локального сервиса
наполняет витрину, и её содержимое совпадает с `/internal/coverage`. Если
сервис недоступен, отвечает 401 или нарушает контракт, витрина остаётся
прежней (не пустой), уровень лога соответствует случаю, токена в логах нет.
Тесты и гейты по изменённым файлам зелёные, baseline не растёт.

## 4. Чек-лист приёмки

- [x] модуль: `doctrine.yaml`, границы модулей (`ModuleBoundaryRules`) зелёные
- [x] env `OZON_FETCHER_BASE_URL`, `OZON_FETCHER_API_TOKEN` в `.env` (пусто), в `.env.test` — значения для тестов
- [x] клиент: таймауты, Bearer, `X-Trace-Id`, строгий разбор v1; unit-тесты на `MockHttpClient`: успех, 401, 5xx, таймаут/транспорт, нарушение контракта (лишний ключ, неверный `schema_version`, кривой `period_key`, отрицательный счётчик), выключен
- [x] фикстура ответа в тестах совпадает с примером контракта v1
- [x] миграция: обе таблицы, PK UUID, уникальный индекс; `down()` есть
- [x] Entity с инвариантами в конструкторе (`Assert`), Builder'ы в `tests/Builders/ServiceGateway/`
- [x] Action: замена снимка одной транзакцией; integration-тест — замена, пустой список строк, сбой посередине не меняет витрину
- [x] команда: выключено → exit 0; недоступен → WARNING, витрина прежняя; контракт → ERROR; lock; integration-тест команды
- [x] Query: одна выборка строк месяца, `ref` и `YYYY-MM` попадают; тест
- [x] cron-строка `*/10`
- [x] токен не попадает в лог (тест на логгер клиента/команды)
- [x] ручная проверка: команда против локального сервиса со стендовыми данными
- [x] `ARCHITECTURE.md`, `service-gateway.md` §4
- [x] PHPStan / CS / strict_types по изменённым файлам; baseline не растёт
- [x] внешнее ревью (Codex) этапа, замечания закрыты

## Итог

- Модуль `site/src/ServiceGateway`: клиент `OzonFetcherCoverageClient`, парсер `ServiceCoverageV1Parser`,
  Entity `ServiceCoverageSnapshot` / `ServiceCoverageRow`, миграция `Version20261018100000`,
  `RefreshServiceCoverageAction`, команда `app:service-gateway:coverage:refresh`, `ServiceCoverageMonthQuery`,
  cron `*/10`, env `OZON_FETCHER_BASE_URL` / `OZON_FETCHER_API_TOKEN` (пусто).
- Тесты: 43 (unit парсера и клиента, integration Action, Query, команды); PHPStan 0 и CS 0 по файлам модуля;
  `doctrine:schema:validate` — расхождений по новым таблицам нет (общий дрейф схемы — существующий, не наш).
- Ручная проверка на стенде (сервис + мок Ozon, 3 кабинета): витрина 83 строки, с ответом сервиса
  совпадает построчно (0 расхождений); неверный токен и остановленный сервис — витрина прежняя, токена в логах нет.
- Отклонение от плана: недоступный сервис — exit 0, а не 1 (supercronic шлёт любой ненулевой exit в GlitchTip).
- Внешнее ревью Codex, итерация 1 — три IMPORTANT, все исправлены:
  1. сервис до первого снимка кабинетов отдавал 200 с пустыми строками и мог опустошить витрину → 503
     `cabinets_not_ready` (Go, тест `TestCoverageWaitsForCabinetSnapshot`);
  2. `"rows": {}` после ассоциативного декодирования проходил как `[]` → клиент декодирует объектами,
     парсер требует `\stdClass` для объектов (тесты через парсер и клиент);
  3. `2026-02-30` и `25:61` нормализовались `createFromFormat` → проверка предупреждений разбора (тесты).
- Итерация 2 — одно IMPORTANT: снимок и строки месяца читались двумя SELECT и могли смешать старый снимок
  с новыми строками при параллельной замене → один запрос `snapshot LEFT JOIN row`.
- Итерация 3 — `REVIEW_GREEN`.
