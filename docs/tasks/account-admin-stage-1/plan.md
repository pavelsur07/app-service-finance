# Этап 1 account/admin — технический план реализации

> Для исполнителя: использовать навык `executing-plans`; выполнять Work items
> последовательно по AGENTS.md. Отдельное ревью/коммит после каждого Work item
> не требуется; checkpoint обновляется после каждого, Stage Report — в конце Stage.

**Цель:** вход существующими пользователями, права и реальный контекст компании
в новых SPA при сохранении старого интерфейса.
**Архитектура:** существующий Symfony + Redis sessions, два SPA, same-origin API,
отдельные host-only сессии (вариант A, реализация поручена владельцем).
**Стек:** установленные Symfony 7.4, React 19, TypeScript/Vite, finance kit,
Vitest/MSW, Playwright/Chromium; PHP/Node/npm только в контейнерах.
**Спецификация:** [TASK.md](TASK.md), обязательна вместе с этим планом.

## Статус, ограничения и решения до старта

План Large-задачи находится в реализации. Состояние и фактические проверки —
в [checkpoint.md](checkpoint.md); этот документ не заменяет Stage Reports.
Документационный PR #2970 завершён отдельно; реализация начинается от `34ef67560`.

- [x] После «merge and deploy #2970 - приступай» принят рекомендованный вариант A,
  что сообщено владельцу; реализация ведётся в feat/account-admin-auth.
- [x] Поручение реализации включает названные в ТЗ API-маршруты Traefik/nginx
  и CI E2E. Ручные production-команды не выполняются, merge кода отдельно.
- [x] Начать реализацию в отдельной ветке от актуального master; перечитать
  AGENTS, CLAUDE, CLAUDE.frontend, TASK, этот plan, соответствующие PATTERNS.
  Документы сопровождения (checkpoint/stage reports/handoff) создать при старте
  реализации, не выдавать подготовку ТЗ за закрытый продуктовый Stage.

Один backend, существующие пользователи/роли/членства, без миграций и финансовой
логики. Контракт ответов snake_case, request DTO — camelCase. Имена контуров,
endpoint-ы, статусы и UX зафиксированы в TASK. Не расширять production-доступы.

## Карта файлов и контрактов

Пути с пометкой «новые» — проектируемые, сейчас не существуют. Имена можно
уточнить под существующий аналог, сохраняя ответственность и публичный контракт.

| Файлы | Ответственность |
|---|---|
| `site/config/packages/security.yaml`, `site/config/services.yaml`, test-конфигурация | Новые контексты/host matchers, provider, throttling, handlers; legacy не объединять |
| `site/src/Shared/Security/Spa/` (новые) | `SpaRequestMatcher`, `SpaLoginAuthenticator`, JSON entry/access-denied handlers; CSRF/origin/host boundary |
| `site/src/Shared/Controller/Spa/` (новые) | Одно действие на класс: `CsrfController`, `LoginController`, `LogoutController`, маршруты для обоих контуров |
| `site/src/Shared/Api/Request/SpaLoginRequest.php` (новый) | Типизированный auth input; authenticator работает до контроллера, использует Serializer/Validator явно, не MapRequestPayload на невызываемом action |
| `site/src/Shared/Infrastructure/Http/SpaExceptionListener.php` (новый) | Ошибки только SPA API; одинаковые JSON для validator/security/controller; legacy не переписывать |
| `site/src/Company/Controller/Api/Session/` (новые) | `SessionController`, `AccessibleCompaniesController`, `SelectActiveCompanyController` |
| `site/src/Company/Api/{Request,Response}/` (новые DTO) | SessionResponse, AccessibleCompaniesResponse, SelectActiveCompanyRequest; без Entity в JSON |
| `site/src/Company/Application/Query/AccountSessionQuery.php` (новый) | Формирование контекста через ActiveCompanyService и существующие права |
| `site/src/Company/Facade/CompanyFacade.php`, существующие company repositories | Переиспользование списка/access check; пагинация в БД без копии правил членства |
| `site/src/Company/Security/ModuleAccessMap.php` | Точные исключения для session/выбора компании; каждый endpoint имеет собственный auth/access gate |
| `site/src/Admin/Controller/Api/Session/SessionController.php`, `site/src/Admin/Api/Response/SessionResponse.php` (новые) | Минимальный admin-контекст, ROLE_ADMIN, без зависимости от активной компании |
| `site/config/packages/nelmio_api_doc.yaml`, `site/assets/api/schema.d.ts` | Документация account API и совместимость старой генерации |
| `site/packages/api/src/generated/account-schema.d.ts` (новый), `package.json` | Генерируемый type-only экспорт account-контракта; transport остаётся независимым от router |
| `apps/{account,admin}/src/app/SessionProvider.tsx`, `RequireSession.tsx` (новые) | Жизненный цикл, protected route, отмена/устаревшие ответы |
| `apps/{account,admin}/src/shared/session/` (новые), `src/shared/api.ts` | Типизированные endpoint adapters, CSRF, чистые переходы состояния |
| `apps/{account,admin}/src/pages/{LoginPage,ForbiddenPage}.tsx` (новые), существующие App/HomePage | Login, shell, состояния; account company picker отдельным компонентом |
| `site/packages/ui/src/` | Только необходимые переиспользуемые visual-примитивы, finance CSS и React 18/19 совместимость |
| `docker-compose.prod.yml`, `site/docker/common/nginx/conf.d/default.conf`, `docker/frontend/nginx.conf` | Host/path routing и CORS граница, запрет API→SPA fallback |
| `docker-compose.frontend.yml`, app Vite/Playwright configs, `bin/frontend*.sh`, Makefile | Локальные разные hosts, dev/preview proxy, реальный тестовый backend и nginx E2E |
| `.github/workflows/deploy.yml`, `tests/shell/frontend-release-test.py` | CI actual-backend E2E, фильтры путей, rollout backend→SPA, отсутствие production credentials |

Новые Shared/Spa классы переиспользуют существующие Symfony-компоненты; не
создавать auth framework, отдельный security bundle или интерфейс на каждый класс.
Новый модуль Symfony не нужен. `ARCHITECTURE.md` обновить только для реальных
новых публичных контрактов Facade/Enum/Entity, не для каждого приватного handler.

## Phase 0 — baseline перед кодом

- [x] Записать SHA и DoD Stage 1 в checkpoint; сверить существующий login,
  ActiveCompanyService, CompanyFacade, ModuleAccessResolver и примеры
  `LoginControllerTest`, `AdminLoginThrottlingTest`, `CashExceptionListener`.
- [x] Подготовить отдельные локальные test DB/Redis без production credentials.
- [x] Выполнить `make front-typecheck front-lint front-test front-build`;
  существующий frontend — `npm run lint`, `npm run build`, `npm run test:unit`
  внутри site-frontend. Записать каждую предсуществующую ошибку.
- [x] В запущенном test PHP-контейнере выполнить `vendor/bin/phpunit` для
  `tests/Functional/Company/Controller/LoginControllerTest.php`,
  `tests/Functional/Admin/AdminLoginThrottlingTest.php`,
  `tests/Functional/Company/ModuleAccessTest.php`,
  `tests/Unit/Company/Security/ModuleAccessResolverTest.php`.
  Не запускать полный `make site-test` на каждом Work item.

## Stage 1 — backend: сессия, вход и защитная граница

Risk: HIGH-LOCAL. `stage_base_commit` записать перед Work item 1.1.
DoD: все auth endpoint-ы из ТЗ работают через тестовые hosts с реальным Symfony;
контексты изолированы, JSON и cookies проверены, legacy-вход не изменился.

### 1.1. Host/path, firewall и JSON-контракт

- [x] Добавить `site/tests/Functional/Shared/Spa/SpaBoundaryTest.php`:
  корректный host+prefix → JSON 401, чужой prefix/host → 404,
  `/internal/` не публикуется, GET вместо POST → 405; никакого HTML/302.
- [x] Прогнать этот файл и записать RED на текущем коде.
- [x] Реализовать matcher `matches(Request): bool`, границу host/path,
  JSON handlers и новые `spa_account`/`spa_admin` contexts. Запрещённый
  SPA API на app-host отклоняется **до** legacy login redirect.
- [x] Повторить целевой тест до GREEN; добавить malformed JSON 400,
  unsupported Content-Type 415, validation 422 и отсутствие деталей в 500.

### 1.2. CSRF, credentials и logout

- [x] Добавить `SpaAuthenticationTest.php` в тот же каталог: анонимный csrf,
  login → 204 → авторизованный запрос, id rotation, reload, logout → 204,
  дальнейший session → 401, независимость трёх hosts, старый main/admin context.
  Logout проверять с пустым body без Content-Type, но с CSRF и Origin.
- [x] Добавить `SpaCsrfTest.php` и `SpaLoginThrottlingTest.php`: отсутствие/чужой
  токен, другой Origin/Referer, оба отсутствуют, sibling-origin, токен другой
  сессии/операции, 5 ошибок и блокировка шестой попытки с правильным паролем.
- [x] Записать RED; реализовать Passport + UserBadge + PasswordCredentials +
  CsrfTokenBadge, JSON success/failure и Symfony logout. CSRF bootstrap доступен
  до login, но не выдаёт сведения о пользователе. Auth input не логируется.
- [x] Проверить роль до успешной admin-сессии, ROLE_SUPER_ADMIN через hierarchy,
  обычного пользователя с корректным паролем → 403 без рабочей admin-сессии.
- [x] Довести тесты до GREEN; повторить старые login/throttling тесты.

Stage checks: `docker compose exec -T -e APP_ENV=test site-php-cli vendor/bin/phpunit
tests/Functional/Shared/Spa` в изолированном test-окружении; focused PHPStan/CS
затронутых файлов. Если имя сервиса отличается — записать фактическую команду.
Internal review полного diff; внешний review при HIGH-LOCAL diff >500 строк
по `docs/workflow/external-review.md`; Stage Report, commit, push, обновить Draft PR.

## Stage 2 — backend: пользователь, компании и права

Risk: HIGH-LOCAL. `stage_base_commit` записать перед 2.1.
DoD: account/admin session, пагинированный выбор компании, актуальные права;
IDOR и роли проверены сервером, нулевая компания — штатный результат.

### 2.1. Контексты и доступные компании

- [x] `site/tests/Functional/Company/Api/AccountSessionTest.php`: владелец,
  активный участник, без компаний, удаляемая активная компания, нет шаблона прав,
  чужой шаблон, изменения членства/прав между запросами; никаких лишних полей.
  Owner/ROLE_OWNER сохраняют write без шаблона; обычный member получает none.
- [x] `AccessibleCompaniesTest.php`: owned + active membership без дублей,
  сортировка, page/perPage, максимум 200, invalid/out-of-range 422.
- [x] Записать RED; реализовать `AccountSessionQuery::__invoke(): SessionResponse`,
  DTO и контроллеры. Список компаний использует общую access policy и Pagerfanta;
  запросы discovery ограничены текущим userId. Если PHPStan требует companyId
  до выбора компании, оформить точечное `@companyScopeExempt` с причиной, не
  ослаблять общий гейт и не принимать произвольный userId из запроса.
- [x] Права вычислять existing resolver/voter, при необходимости через новый
  метод существующего CompanyFacade, возвращающий scalar/DTO-проекцию;
  не импортировать Company Security Service в чужой модуль и не дублировать RBAC.
- [x] `site/tests/Functional/Admin/Api/AdminSessionTest.php`: ROLE_ADMIN/super,
  запрет обычному пользователю, снятие роли в уже открытой сессии,
  admin без компании. Реализовать минимальный ответ, довести тесты до GREEN.

### 2.2. Переключение и контрактные типы

- [x] `SelectActiveCompanyTest.php`: owner/member success; неактивный member,
  чужой/удаляемый/несуществующий UUID → 404 с неизменной сессией; invalid UUID →
  422; CSRF → 403; валидный выбор меняет permissions и следующий session.
- [x] RED → `SelectActiveCompanyRequest(targetCompanyId: string)` с Assert;
  контроллер использует `CompanyFacade::userHasAccess()` до изменения сессии,
  затем читает обновлённый контекст; сервисам не передаёт Request/Session.
- [x] Добавить точные ModuleAccessMap exceptions для bootstrap/выбора без
  привязки к праву company ADMIN; anonymous endpoints отдельно и минимально.
  Тест `ControllerAccessCoverageTest` обязан остаться зелёным.
- [x] Описать account endpoint-ы в Nelmio, с отдельными servers/security для
  SPA area. Сгенерировать account TS schema в shared API и прежнюю site schema;
  добавить команды генерации/check через контейнер и документировать их.
- [x] Admin DTO сопоставить локальным TS-типам через контрактные fixtures и
  assertions; не менять общую политику документирования legacy admin API.

Stage checks: новые Company/Admin API suites + CompanyMemberAccess/ModuleAccess,
ControllerAccessCoverage, генерация/check OpenAPI, focused lint/static analysis.
Review и закрытие Stage по тому же правилу; внешний review при diff >500 строк.

## Stage 3 — frontend: вход и рабочая оболочка

Risk: MEDIUM. `stage_base_commit` записать перед 3.1.
DoD: обе SPA работают с контрактом Stage 1/2; все обязательные состояния и
переключение компании проверены; бизнес-экраны и зависимости не добавлены.

### 3.1. Session adapters и состояние

- [x] Для каждого app добавить `src/shared/session/session.test.ts` (Vitest/MSW):
  csrf→login→session, initial 401, login 401 без global redirect, 403/429/network,
  logout, abort, старый ответ после logout/смены компании, ошибка восстановления.
- [x] RED → реализовать endpoint-функции `getSession(signal?)`,
  `login({email,password})`, `logout()`; в account `getCompanies(page,signal?)`,
  `selectActiveCompany(targetCompanyId)`. Все через @vashfindir/api; типы из DTO.
- [x] Реализовать SessionProvider/RequireSession и отмену с generation guard;
  на 401 очищать состояние, на network/5xx сохранять возможность повторить,
  пароли не сохранять. После неоднозначного POST перечитать session.
- [x] GREEN, MSW unhandled requests = error; старые API contract tests без
  изменений поведения. Не добавлять Query/Zod/state library для этой оболочки.

### 3.2. Страницы и выбор компании

- [x] Добавить Playwright `auth.spec.ts`/`company-selection.spec.ts` с local
  request fixtures для UI-ошибок: labels, keyboard, submit pending, no-company,
  forbidden, local return URL, login loop, reload protected route, public about.
- [x] RED → LoginPage, ForbiddenPage, shell с email/logout и account picker;
  HomePage сохраняет текст заглушки. Пикер поддерживает пагинацию, pending,
  отказ и перепроверку на focus; admin не показывает picker компаний.
- [x] Использовать существующие finance primitives; при добавлении общего
  visual-компонента сохранить React18/19 и старые reexports.
- [x] GREEN: `make front-typecheck front-lint front-test front-build`,
  `make front-e2e MODE=dev` и `MODE=preview` для обеих SPA. Эти UI-тесты с mocks
  не заменяют actual-backend E2E следующего Stage.

Stage review: responsive/empty/loading/error, race/logout/IDOR UI leakage,
доступность; обновить checkpoint, Stage Report и Draft PR.

## Stage 4 — интеграция, публикация и handoff

Risk: HIGH-LOCAL; production/CI edits — только в явно разрешённом объёме §3.3.
`stage_base_commit` записать перед 4.1.
DoD: same-origin API и host isolation доказаны реальным nginx/Symfony;
полные gates/ревью завершены, rollout и rollback документированы.

### 4.1. Реальный HTTP-контур и CI

- [ ] Добавить `tests/shell/frontend-auth-routing-test.py` и E2E
  `session-isolation.spec.ts`: production nginx images + Traefik/test proxy +
  реальный test Symfony, PostgreSQL/Redis и заранее созданные fixtures.
  Никаких mocked auth responses, production env или реальных данных.
- [ ] Записать RED на существующем SPA fallback/API routing. Добавить точные
  Traefik API routers с explicit priority и host-preserving site service;
  ограничить nginx host/path и CORS только новыми контурами.
- [ ] В dev/preview настроить proxy для своего API и разные hostnames; Docker
  browser должен резолвить их внутри сети, локальный браузер — в loopback.
  В production-like тесте использовать HTTPS с тестовым доверенным CA, чтобы
  проверить Secure/cookie isolation без `ignoreHTTPSErrors`.
- [ ] Реальные login→reload→company→logout и независимые app/account/admin
  проверить в одном browser context; cookies inspect без печати значений.
  Отдельно hostile sibling Origin, wrong host prefix и expired session.
- [ ] Сохранить статический smoke `front-prod-test` (about/assets/health/404);
  protected home тесты перенести в actual-backend suite, не ожидать старую
  анонимную главную. Добавить `front-auth-e2e MODE=dev|preview|production`
  с APP=account|admin или обоими по умолчанию и описать в apps/README.
- [ ] В CI добавить actual-backend suite как обязательную зависимость deploy;
  path filters учитывать auth/backend/shared/frontend/nginx изменения.
  Сохранить проверку реальных image artifacts перед публикацией и порядок
  backend→SPA; регрессионные release-contract тесты RED→GREEN.

### 4.2. Финальные проверки и выпуск

- [ ] Один раз на handoff: `make site-stan site-cs-check site-cs-strict-types
  site-test-unit`, затем `make site-test` (затронута auth integration).
- [ ] Новые SPA typecheck/lint/Vitest/build; dev/preview/production auth E2E;
  production image smoke; старый frontend lint/build/test:unit и API types check;
  `make test-frontend-release`, `make test-deploy-workflow`, actionlint.
- [ ] Fresh internal review всего diff по backend/frontend checklist. External
  review другой моделью через `site/bin/external-review.sh <base> --effort medium`
  с полным apps/infra diff в context при необходимости; правила и лимит раундов
  — docs/workflow/external-review.md. Устранить BLOCKER/IMPORTANT и безопасные MINOR.
- [ ] Stage Report и handoff: результаты, baseline delta, ревью/метрики,
  ограничения, точные команды локального запуска, API-схемы, release acceptance.
- [ ] Ready → один запрос «merge and deploy #N». Предыдущие одобрения #2965/#2967
  не распространяются на новый auth PR. После одобрения пройти pipeline до конца.
- [ ] После deploy: read-only wrappers, SHA/health, HTTPS обоих domains,
  GET csrf/session (без вывода токенов), wrong-host/path запреты, публичный about,
  отсутствие регрессии старого login. Authenticated сценарии уже доказаны на CI;
  production login/mutations не выполнять без отдельного разрешения.

Rollout: существующий backend получает совместимые новые API первым, затем SPA.
На несовместимом/отсутствующем backend SPA показывает контролируемую ошибку,
не успешный вход. Rollback — revert через стандартный pipeline, сначала версия
SPA, совместимая с возвращаемым backend; миграций и преобразования данных нет.
Отдельные SPA-сессии после отката могут потребовать повторного входа; legacy
sessions не инвалидируются глобально. Не очищать Redis для отката.

Примечание к выполнению: имена тестовых файлов консолидированы (CSRF/throttle
в SpaAuthenticationTest), а реальные cookie/host проверки вынесены в Stage4.
SessionStatus реализует forbidden/error без отдельной страницы маршрутизации.
Фактические команды и результаты — checkpoint и Stage Reports.

## Review focus и трассировка

| Риск | Где фиксируется тестом |
|---|---|
| Cookies разделены портами вместо hosts, общий logout | 1.2 и 4.1: один браузер, три origin, cookie attributes/identity |
| Company ADMIN принимается за системного администратора | 1.2/2.1: owner без ROLE_ADMIN, ROLE_SUPER_ADMIN, снятие роли |
| Участник/удаляемая компания или устаревший ответ раскрывает контекст | 2.1/2.2/3.1: revoke/delete/foreign IDs, response generation |
| API получает HTML или nginx CORS обходится internal redirect | 1.1/4.1: полный HTTP-ответ и host/path/Origin matrix |
| Зелёные mocks скрывают сломанный production auth | 4.1: реальный Symfony/DB/Redis/nginx + HTTPS; CI gate |

Каждый Work item заканчивается узкими checks, self-review и checkpoint; каждый
Stage — полным diff-review и одним Stage Report. Начинать следующий Stage
автоматически в согласованном объёме; отдельного согласования Work item нет.
