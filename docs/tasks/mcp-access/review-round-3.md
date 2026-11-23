# Независимое ревью плана MCP (коммит 123e18134, PR #3011)

Итог: 1 BLOCKER, 3 IMPORTANT, 3 MINOR. Коррекции 4–6 из фокуса (очередь в Mcp без цикла, одна попытка на одобрение, общий lock-координатор) в целом осуществимы; главный дефект — размещение по хостам.

## BLOCKER

**B1. Таблица хостов и маршрутов §1.1 не работает на существующих account/admin-хостах.**
`docs/tasks/mcp-access/plan.md:86-97, 99-105, 117-124, 456-457`; `TASK.md:28-32`.

Наблюдаемые факты:
- `docker-compose.prod.yml:187, 205` — account/admin-хосты обслуживают SPA-nginx; в Symfony уходят только `/api/account/v1/` и `/api/admin/v1/` (`:241, :247`). Legacy Twig, `form_login` и `/admin` живут на `app.vashfindir.ru` (`:253`).
- `docker/frontend/nginx.conf:25-32` — SPA-nginx отдаёт 404 на `/.…` (то есть на `/.well-known/*`) и на `/admin`.
- `site/src/Shared/Infrastructure/Http/SpaHttpSubscriber.php:34-40` — на этих хостах любой путь вне `/api/<realm>/v1/` получает JSON 404 до routing и firewall. Это закреплено тестом `site/tests/Functional/Shared/Spa/SpaBoundaryTest.php:62`.
- `SpaHttpSubscriber.php:43-55` — внутри realm-API небезопасные методы требуют Origin, поэтому спрятать MCP под `/api/<realm>/v1/` тоже нельзя.
- `ARCHITECTURE.md:4991` — сессии host-only, без SSO. `site/config/packages/security.yaml:64-89` — `form_login` у `main`/`admin` не привязан к этим хостам.

Последствия:
- `/mcp`, `/mcp/admin`, `/oauth/*`, `/.well-known/*`, `/integrations/mcp/*`, `/admin/oauth/authorize`, `/admin/mcp/*` в production недостижимы.
- «Существующий main/admin context/form_login» на account/admin-хостах не существует.
- Исправление требует новых Traefik-маршрутов, то есть production config, а `plan.md:456-457` обещает обойтись существующим ingress.

Минимальная правка — в Phase 0 зафиксировать хосты по факту, один из двух вариантов:
- **A (рекомендую):** AS, consent, approval и оба MCP на `app.vashfindir.ru`, где реально есть `main`/`admin` firewall и Twig-shell из Stage 5.2. Тогда нужно честно записать, что разделение идёт по firewall, context, audience и path, а не по хосту. `main` и `admin` на одном хосте делят одну session-cookie с разными context-ключами, поэтому reauth-stamp привязывается к admin-context и userId. На SPA-хостах MCP-пути остаются 404.
- **B:** оставить account/admin-хосты. Тогда нужен явный список: Traefik-роутеры, исключения в `SpaContext`/`SpaHttpSubscriber`/nginx, вход только через `spa_*` context без `form_login`, consent не в Twig-shell. Всё это — отдельное named approval.

Тесты wrong-host и условие Stage 2 переписать под выбранный вариант.

## IMPORTANT

**I1. Бюджет 10 секунд и нагрузка ОПиУ не обеспечены названным механизмом.**
`plan.md:187, 191, 193, 197-199`.

Наблюдаемые факты:
- `site/src/Finance/Report/PlReportGridBuilder.php:43-47` — отдельный расчёт на каждый период.
- `site/src/Finance/Report/PlReportCalculator.php:46, 136-138` — на каждый период загрузка категорий и вызов `facts->value` на каждый лист.
- `site/src/Finance/Facts/PLDailyTotalFactsProvider.php:49-52, 60-70` — на каждый вызов `findOneBy` плюс `SUM`.
- В `site/config` и `site/src` нет `statement_timeout`; `fastcgi_read_timeout` равен 300 (`site/docker/common/nginx/conf.d/default.conf:49`).

Последствия:
- Число запросов растёт как периоды × статьи × 2. При 31 колонке и 150 статьях (оценочный пример) это около 9 тысяч коротких запросов.
- `statement_timeout` ограничивает один запрос, а не их сумму.
- Лимит «2 параллельно на компанию» при общем выпуске не ограничивает суммарную нагрузку на общий с UI FPM-пул.

Предположение, не проверено: `max_execution_time` в PHP на Linux не учитывает ожидание БД.

Правка:
- Добавить глобальный лимит одновременных дорогих отчётов на существующем Redis lock/limiter.
- Назвать реальный механизм дедлайна: проверка wall-clock в Finance-адаптере, без изменения формул.
- Лимит колонок ОПиУ выводить из измерения.
- Нагрузочную проверку сделать условием Stage 1/3, а не только Stage 5.3.

**I2. Один `expiresAt` на заявку не определяет срок жизни после одобрения.**
`plan.md:162, 365-367, 392-393, 401-403`; `TASK.md:150`.

- Подтверждение живёт 5 минут, worker работает раз в минуту, до 2 задач, а при занятом lock возвращает заявку в `queued`.
- Если `expiresAt` — срок подтверждения, то заявка, одобренная на 4:30, истекает до первого claim и отменяется после человеческого одобрения.
- Если срок на `queued` не действует, заявка после простоя scheduler исполнится сколь угодно поздно по устаревшему одобрению.

Правка: два поля — `approvalExpiresAt` для `prepared` и `executeBy = approvedAt + фиксированное окно` для `queued`. Просрочка `executeBy` переводит заявку в `expired` с аудитом. Добавить тесты на обе границы и на цикл Busy-requeue.

**I3. Не назван носитель durable audit, а в worker не определён actor.**
`plan.md:227, 316-319, 365-367, 380-382, 411-413, 440`.

Наблюдаемые факты:
- `site/src/Shared/Audit/AuditContextProvider.php:26-27, 44-46` — без Request (консоль) возвращается `null`.
- `AuditContextProvider.php:33-35, 55-57` — для не-`User` возвращается `null`.
- Существующие потребители провайдера — компанийные слушатели Cash, например `site/src/Cash/EventListener/AuditLogListener.php:23`.

Последствия:
- Аудит исполнения в `ProcessAdminOperationsCommand` через провайдер останется без actor.
- «Аудит в той же транзакции» не привязан к таблице.
- В полях `AdminOperation` нет `approvedBy`, `approvedAt`, `reauthAt`, `startedAt`, `finishedAt`. При решении D4 «второй оператор» подтверждающий не сохранится.

Правка:
- Назвать носитель: append-only события операций в Mcp, записываемые в одной транзакции с переходом статуса.
- Добавить поля подтверждающего и времён.
- Worker передаёт actor из строки заявки явно, а не через провайдер.
- Изменение `AuditContextProvider` ограничить HTTP-запросами MCP.

## MINOR

- **M1.** `plan.md:339-343` — `list_tasks`/`get_task` в Stage 3 читают сущность, которая появляется только в Stage 4.2, так что fixtures не на чем строить. Перенести эти tools в Stage 4 либо поднять 4.2 перед 3.3.
- **M2.** `plan.md:92, 109-112, 190` — анонимный dispatcher пишет OAuth transaction на каждый запрос, а лимит задан только для failed auth. Добавить лимит по IP/client и TTL-очистку для `/oauth/authorize`.
- **M3.** `plan.md:265-268`; `TASK.md:86-89` — в контракте не сказано, что выбранный проект включает потомков (`PLDailyTotalFactsProvider.php:73-79`; `site/src/Report/Cashflow/CashflowReportBuilder.php:241-249`). Набор «все корневые проекты» не равен «без фильтра»: он исключает нераспределённые факты. Записать это в schema/description и добавить случай в parity-тест.

## Ограничения

- Работа только чтением (Read/Glob/Grep). Тесты, Composer и сеть не запускались; реализация не проверялась, продуктового кода MCP нет.
- `site/vendor` отсутствует, поэтому поведение `league/oauth2-server(-bundle)`, MCP SDK/bundle (resource→aud, revoke, ротация refresh, изоляция каталогов), реальных MCP-клиентов и default store у `LockableTrait` не проверено. Требование плана не полагаться на него (`plan.md:398`) корректно.
- Production-ingress оценён только по `docker-compose.prod.yml` в репозитории, фактическое состояние сервера не проверялось.
- `review.md`, `.env`, ключи и production-данные не читались.
- D1–D6 дефектами не считались.

REVIEW_FINDINGS
