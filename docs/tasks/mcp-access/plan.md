# MCP: расширенный план реализации

> Для исполнителя: использовать `executing-plans`; делегирование — только по
> действующим инструкциям проекта. Work items не являются owner gates.
> Флажки ниже относятся к будущей реализации и пока не выполнены.

**Цель:** внешний read-only MCP и отдельный ops MCP для диагностики и
контролируемого повтора обработки, без пилота по компаниям.
**Архитектура:** два resource server в Symfony; domain Facade; существующие
компоненты политик и ServiceGateway; административные команды через durable job.
**Стек:** PHP 8.4, Symfony 7.4, PostgreSQL, Redis; официальные PHP MCP SDK/bundle
и готовый OAuth server component после проверки совместимости.
**Спецификация:** [TASK.md](TASK.md). Статус: предложение, не команда на реализацию.

## Ограничения и baseline

- Только документы в текущей задаче. Изменений продукта, схемы, зависимостей,
  конфигурации инфраструктуры и production-действий здесь нет.
- Документарный baseline: `git diff --check` на `5aff7dbfd` — exit 0;
  отдельный worktree чист. PHP/JS-тесты для подготовки плана не запускались.
- При реализации — Large, HIGH-LOCAL: до кода прогнать узкие baseline-тесты
  из раздела проверок, записать результат в checkpoint; красный baseline
  отделить от регрессий. Работать с актуальным master, повторно сверить контракты.
- Не менять финансовые формулы, валютную семантику, ключевой API и старые
  публичные отчёты. Новые методы и Entities отразить в ARCHITECTURE.md только
  вместе с реализацией, не выдавать планируемое за существующее.
- Вызовы services/ только через Symfony; пользовательские токены не передавать
  сервисам. Контракт ai-task/v1 не менять ради общего retry.
- Локальные схемы/миграции в scope будущей реализации. Production migration,
  scheduler/worker configuration и новые signing credentials — отдельные
  действия AGENTS.md §3.3, согласуемые конкретным списком при handoff.

## Review focus

1. Токен не того realm/audience/host, cookie вместо bearer, старый API-ключ на MCP.
2. Смена компании в браузере, чужие проект/ЦФО, снятие членства/роли после выдачи
   токена и после подтверждения операции.
3. Подтверждение от модели, изменённые параметры, истёкшая заявка, повтор/гонка
   подтверждения, crash между подтверждением и запуском.
4. Слишком большой период/дерево/суммарная нагрузка, межкомпанийный кеш,
   тихая выдача усечённого или финансово отличающегося от UI отчёта.
5. Утечка из логов/ошибок/диагностики; ложное заявление «процесс здоров» по
   старой витрине; попытка повторить неподдерживаемую задачу.

Каждый пункт закреплён наблюдаемыми тестами соответствующего Stage.

## Phase 0 — закрыть решения и проверить SDK до продуктового кода

- [ ] Зафиксировать D1–D6 из TASK.md. Неразрешённый вопрос о полномочиях или
  тарифе не заменять предположением. Остальную подготовку продолжать независимо.
- [ ] Проверить 2–3 существующих образца: Api stateless auth, CompanyFacade
  financialAccess, Report builders и ServiceCoverageRefreshCommand.
- [ ] Выбрать совместимые released версии `symfony/mcp-bundle` + `mcp/sdk`;
  если bundle не даёт изолировать два каталога и JSON-response transport,
  использовать SDK с тонким Symfony controller, сохраняя протокол в SDK.
- [ ] OAuth: предпочтительно готовый `league/oauth2-server-bundle` /
  `league/oauth2-server` вместо собственного token protocol или нового IdP
  сервиса. В изолированном локальном Composer dry-run проверить Symfony,
  Doctrine и security constraints. Не обновлять framework/package baseline
  только ради несовместимого latest. Проверка версии — не установка.
  Отдельно доказать resource->aud mapping, revoke endpoint, refresh-family
  rotation/replay и общий authorize entry с server-owned dispatch к consent
  нужной сессии. Это требования к выбору AS, а не обещание наличия в bundle.
  Если bundle не подходит, проверить core league/oauth2-server с тонкой
  Symfony-интеграцией; Stage 2 не начинать без подтверждённого решения.
- [ ] Зафиксировать compatibility matrix: имя и версия каждого выбранного
  клиента, MCP revision, регистрация клиента, PKCE, JSON transport, consent,
  token refresh, reconnect. Базовый кандидат — ревизия 2026-07-28;
  2025-11-25 поддерживать только при реальной необходимости выбранных клиентов.
- [ ] Для внешних клиентов: начать с pre-registration конкретных поддержанных
  OAuth clients. Если клиент требует CIMD/DCR, до реализации выбрать одну
  проверенную модель, добавить SSRF/registration abuse тесты. DCR не включать
  автоматически. Для admin — только pre-registration, отдельные client IDs.
- [ ] Проверить, что SDK выдаёт согласованные ошибки без существующих
  ApiExceptionSubscriber/общих exception listeners, превращающих JSON-RPC в REST.
- [ ] Записать stage_base_commit, DoD и focused baseline перед Stage 1.

Выход Phase 0 — конкретные версии, поддержанные клиенты, согласованная модель
прав, команды baseline и уточнённая карта файлов. Нет разработки криптографии,
публичного DCR или собственной реализации MCP в качестве обхода несовместимости.

## 1. OAuth, principal и маршрутизация

### 1.1. Разделение двух resource server

| Назначение | Host/path | Firewall / доступ |
|---|---|---|
| Пользовательский MCP | account host `/mcp` | отдельный stateless bearer, user audience |
| Административный MCP | admin host `/mcp/admin` | отдельный stateless bearer, admin audience |
| Resource metadata | `.well-known` для каждого resource | public, без сведений о подключениях |
| AS metadata | account host `.well-known/oauth-authorization-server` | один canonical issuer; public; PKCE S256 |
| OAuth authorize entry | account host `/oauth/authorize` | публичный stateless dispatcher, без login/consent и выдачи code |
| OAuth token / revocation | account host `/oauth/token`, `/oauth/revoke` | stateless protocol firewall; без form_login redirect |
| User login/consent | account host `/integrations/mcp/authorize` | существующий main context/form_login + consent POST с CSRF |
| Admin login/consent | admin host `/admin/oauth/authorize` | существующий admin context/form_login + ROLE_ADMIN; POST с CSRF |
| Подключения пользователя | account host `/integrations/mcp` | сессионный доступ к своим разрешённым grants |
| Admin подключения/операции | admin host `/admin/mcp/*` | admin session, отдельные checks read/execute |

Host'ы брать из канонической конфигурации существующих account/admin realms,
не из неконтролируемого Host/X-Forwarded-Host. Resource audience — полный
канонический HTTPS URL endpoint. Один access token имеет ровно одну аудиторию.
Admin matcher и оба MCP firewall стоят перед main; точные path boundaries,
trailing slash, encoded aliases и неправильный host закрываются, не падают
в сессионный маршрут. Metadata соответствует RFC9728 для resource path;
проверить discovery реальными выбранными клиентами.

Один AS может обслуживать два resource server: обе resource metadata ссылаются
на один issuer и один advertised authorization_endpoint `/oauth/authorize`.
Dispatcher проверяет client registration, разрешённый realm/resource, redirect
URI, PKCE и scopes; сохраняет неизменяемую короткоживущую OAuth transaction и
перенаправляет только на свой user/admin consent route с непривилегированным
ID запроса. Реальные client state/redirect берутся из этой server-owned записи.
Возврат после login восстанавливает запись; смена resource/realm/параметров
по query запрещена. Только consent в правильной сессии завершает авторизацию.
Два публичных authorization_endpoint в одном AS metadata не объявлять.

Явно добавить перед main stateless protocol firewall для metadata и точных
`/oauth/{authorize,token,revoke}`; PUBLIC_ACCESS для discovery/dispatcher и
protocol endpoints означает отсутствие session-login, а не отсутствие OAuth
валидации token/revoke. Main consent использует только main session;
admin consent/approval под `/admin/*` используют только admin session.
PUBLIC_ACCESS записи для этих protocol routes идут до `^/` ROLE_USER,
admin consent/approval остаются под `^/admin` ROLE_ADMIN. SPA account/admin
session contexts не смешивать с ними. Каждый route привязан к нужному host.
Grant, auth code и refresh family привязаны к client+realm+resource;
публичный клиент не может запросить admin resource. RS проверяет issuer,
audience и эти связи по серверному grant, а не только строку scope.

Resource server: 401 + WWW-Authenticate/resource_metadata для отсутствующего,
истёкшего или неверного токена; недостаточные OAuth scopes — корректный 403
challenge. Ошибки JSON-RPC и ошибки выполнения конкретного tool кодирует SDK
по выбранной ревизии. Для чужого/отсутствующего объекта одинаковый безопасный
результат; HTTP redirect, HTML и stack trace в MCP не допускаются.

### 1.2. Identity и жизненный цикл

Проектируемые `McpUserPrincipal(userId, companyId, connectionId, scopes)` и
`McpAdminPrincipal(userId, connectionId, scopes)` не являются `User` или
`ApiPrincipal`. Не читать ActiveCompanyService/ModuleAccessResolver из MCP.
User principal проверяет CompanyFacade::financialAccess на каждый tool;
admin principal перечитывает роль через новый минимальный Company Facade
DTO-метод, не импортирует Company Repository в Mcp.
Проверка platform roles применяет существующий role_hierarchy, как
SpaAdminUserChecker: SUPER_ADMIN наследует ADMIN, без дублирования этих ролей
в записи пользователя.

Grant хранит realm, clientId, userId, companyId только для user realm,
конкретные scopes, expiry/revokedAt и версию. Refresh family привязана к тому
же realm/client/company/grant; refresh не расширяет права и не меняет компанию.
SDK/AS проверяет подпись JWT, issuer, audience/resource, expiry, algorithm;
приложение дополнительно перечитывает grant и текущие права. Короткий JWT сам
по себе не обеспечивает немедленный отзыв. Company API key не является OAuth.

Рекомендуемые начальные значения:

| Параметр | User | Admin |
|---|---|---|
| Access token TTL | 10 минут | 5 минут |
| Refresh family absolute lifetime | 30 дней; idle 7 дней | 1 час; без бессрочного offline access |
| Authorization code | одноразовый, 60 секунд | одноразовый, 60 секунд |
| Reauthentication для изменяющей admin-операции | — | не старше 5 минут |
| Подтверждение заявки | — | 5 минут, одна версия и одна операция |

Обязательны PKCE S256, точный redirect URI, client/resource binding,
server-side OAuth consent и state handling со стороны клиента. Refresh tokens
хранятся как hash, ротируются атомарно; повтор использованного токена отзывает
семейство. Одновременный refresh тестируется. Эндпоинты выдачи no-store.
Потеря роли/прав, удаление аккаунта/компании, отзыв grant/client и expiry
закрывают последующие вызовы. Если приложение не имеет блокировки аккаунта,
не придумывать её статус; проверять реально существующие признаки доступа.
User grant отзывает его пользователь; company-owner control зависит от D1.
Admin grant отзывает сам оператор либо действующий SUPER_ADMIN с аудитом.

## 2. Tools, DTO и ограничение нагрузки

Все handlers имеют явные JSON Schema input/output, запрет неизвестных полей,
строгие даты/enum/ID и типизированные DTO. Tool registry каждого realm явный;
автосканирование всех атрибутов приложения не смешивает каталоги. Тест
перебирает зарегистрированные tools: у каждого есть realm, policy и schema.
Tool metadata/annotations описывают поведение, но не служат авторизацией.

| Параметр | Начальное ограничение, подлежит проверке нагрузкой |
|---|---|
| Вход MCP | 64 KiB; batch не принимать, если его не поддерживает выбранная ревизия/SDK |
| `list_accounts` / `list_tasks` | page/limit, 50 по умолчанию, максимум 200; Pagerfanta |
| Период отчёта | до 366 календарных дней включительно; строгий day/week/month |
| Колонки | ДДС максимум 366; ОПиУ начально максимум 31 (до измерений); превышение — сузить период/группировку |
| Фильтры отчёта | до 20 project IDs и 20 ЦФО; принадлежность каждого ID проверяется |
| Ответ | максимум 1 MiB; превышение — явная ошибка с предложением сузить период/разрез |
| Общий rate limit | 60/min/connection и 60/min/user; failed auth 20/min/IP |
| Дорогие отчёты | 10/min/company, не более 2 параллельно на компанию |
| Admin prepare | 5/min/operator; один активный refresh на сервис |
| Report request budget | 10 секунд; валидировать не только HTTP, но и DB statement timeout |
| Внутренний GET coverage | connect <=2s, total <=10s, без бесконечных повторов |
| Transport | request/response JSON; без долгоживущего server GET SSE stream |

Числа — предложенные лимиты, не измеренная производительность. До общего
выпуска подтвердить нагрузкой на обезличенных локальных данных. Ограничения
применяются до дорогой работы; concurrency lease освобождается при ошибке и
протухает при crash. Не обрезать финансовый отчёт молча. На первом выпуске
не добавлять новый report cache; при появлении ключ обязательно включает
company/права/фильтры и формат. Существующие кеши builders проверить на scope.

При выборе старой ревизии совместимость её handshake/session lifecycle
реализует SDK; session ID никогда не является credential или company context.
Права проверяются в каждом запросе, независимо от session ID.

## 3. Карта новых/изменяемых файлов

Пути `новый` — предложенные; уточнить только организацию SDK files в Phase 0.

| Файл / группа | Ответственность |
|---|---|
| `site/src/Mcp/Security/{McpUserPrincipal,McpAdminPrincipal,McpAuthenticator,McpRequestMatcher}.php` (новые) | OAuth identity, audience и host/path routing |
| `site/src/Mcp/Domain/{McpToolPolicy,McpScopeAvailability}.php` (новые) | Явные realm/tool/scope policy и доступность канала |
| `site/src/Mcp/Entity/McpConnection.php`, `Repository/McpConnectionRepository.php` (новые) | Grant и свежая проверка отзыва; OAuth persistence не дублирует таблицы выбранного bundle |
| `site/src/Mcp/Tool/{Account,Admin}/*` (новые) | Тонкие SDK handlers с разделённой регистрацией |
| `site/src/Report/Facade/{ReportFacadeInterface,ReportFacade}.php`, `Application/DTO/*` (новые) | Полные read-контракты ДДС/ОПиУ без сессии, общий расчёт |
| `site/src/Finance/Facade/FinanceFacade.php` (изменение) | Узкий read-метод готового ОПиУ через существующий builder |
| `site/src/Cash/Facade/CashFacade.php` (изменение) | Пагинированная безопасная проекция счетов; не возвращать остатки по умолчанию |
| `site/src/ServiceGateway/Facade/{ServiceOperationsFacadeInterface,ServiceOperationsFacade}.php` (новые) | Общий доступ к витрине и единственному поддержанному типу операций |
| `site/src/ServiceGateway/Application/Service/CoverageRefreshCoordinator.php` (новый) | Одна общая блокировка и вызов существующего refresh Action для cron и Facade |
| `site/src/Mcp/Entity/AdminOperation.php`, `Repository/AdminOperationRepository.php` (новые) | Durable состояния, attempts/lease/idempotency административных заявок |
| `site/src/Mcp/Application/Action/{PrepareCoverageRefreshAction,PrepareTaskRetryAction,ApproveAdminOperationAction}.php`, `Command/ProcessAdminOperationsCommand.php` (новые) | prepare/approve/claim/execute; без Request/Security в worker |
| `site/src/ServiceGateway/Command/ServiceCoverageRefreshCommand.php` (изменение) | Общая с новым worker per-service блокировка без изменения cron-семантики |
| `site/src/Company/Facade/CompanyFacade.php`, `Application/DTO/*` (изменение/новый DTO) | Бессессионное чтение реальных platform roles по userId |
| `site/src/Shared/Audit/AuditContextProvider.php` (изменение) | Чтение доверенного MCP identity без сессии |
| `site/src/Mcp/Controller/*`, `Form/*`, `site/templates/mcp/*` (новые) | Consent, подключения, безопасное подтверждение |
| `site/config/packages/{security,doctrine,twig}.yaml`, `site/config/routes.yaml`, `services.yaml`, `rate_limiter.yaml` (изменения) | Явный модуль, firewall, route/DI/rate-limit интеграция |
| `site/config/packages/mcp.yaml`, OAuth-конфигурация (новые) | Два сервера и выбранный AS component |
| `site/migrations/Version*.php` (новые при реализации) | Только additive OAuth/grant/operation schema, без копирования данных |
| `site/tests/{Unit,Functional,Integration}/Mcp/*`, `Integration/ServiceGateway/*`, `Architecture/*` (новые/расширение) | Security/contract/concurrency tests |

ReportFacade вызывает FinanceFacade за ОПиУ; не импортирует Finance/Report
напрямую. ДДС builder уже принадлежит Report. Не заставлять MCP вызывать
controller или чужую Infrastructure. Новые read interfaces — публичные
Facade-контракты по правилам проекта, не дополнительные абстракции SDK.
Company global role lookup — явно документированное identity bootstrap
исключение; company repositories во всех последующих запросах scoped.
Жизненный цикл OAuth-заявки и worker принадлежат Mcp, который владеет grant
и может перечитать его перед исполнением. ServiceGateway выполняет только
свою refresh-операцию и не импортирует Mcp/Repository или OAuth-контекст.
Иначе возник бы запрещённый цикл Mcp -> ServiceGateway -> Mcp.

## Stage 1 — общие read-контракты отчётов и диагностики

**Risk:** HIGH-LOCAL. **stage_base_commit:** записать перед 1.1.
**DoD:** данные UI и MCP доступны через типизированные Facade без сессии;
чужие фильтры отклоняются, формулы/старые endpoint'ы не меняются.

- [ ] **1.1, tests first:** добавить `ReportFacadeParityTest`: ДДС и ОПиУ
  для двух компаний, отрицательные/нулевые значения, разные валюты, границы
  месяца/года и округление. Разные валюты проверять в ДДС; в ОПиУ —
  MONEY/PERCENT/RATIO/QTY, warnings и валютную семантику D6.
  Оракулы — существующие JSON-экспорты `report_cashflow_export_json` и
  `finance_report_preview_json`, включая фильтры all/partial/unassigned;
  одного сравнения нового Facade с напрямую вызванным builder недостаточно.
  Сначала доказать отсутствие нового контракта
  красным тестом; существующие builder tests должны оставаться зелёными.
- [ ] **1.2:** добавить проектируемые методы
  `ReportFacadeInterface::cashflow(string $companyId, ReportReadRequest $request): CashflowReportView`
  и `profitLoss(string $companyId, ReportReadRequest $request): ProfitLossReportView`.
  `ReportReadRequest` содержит даты, day/week/month и списки project/ЦФО IDs;
  снаружи не содержит Entity. DTO размещать в Report/Application/DTO.
  Отсутствующий фильтр = null, пустой список = invalid filter; после проверки
  всех ID полный набор доступных UI choices = null, частичный — явный набор.
  null включает нераспределённые факты, как UI; чужие ID не отбрасывать молча.
  Дедупликация/порядок ID не меняют семантику; from>to — явная ошибка схемы.
  FinanceFacade добавляет `readProfitLoss(string $companyId, ProfitLossReadRequest $request): ProfitLossReportData`
  с DTO в Finance/Application/DTO; адаптер вызывает PlReportGridBuilder внутри
  Finance и возвращает строки/периоды/formats/warnings без Entity. Для MONEY
  единицу фиксирует D6; процент/ratio/quantity не маркировать ISO-валютой.
  Не обещать currency breakdown, которого нет в текущем builder. Зависимости Report ->
  Finance/Cash, без обратного импорта в FinanceFacade.
- [ ] **1.3:** добавить `CashFacade::pageAccountReferences(string $companyId, int $page, int $limit): MoneyAccountReferencePage`
  (новые DTO в Cash/Application/DTO), используя существующие scoped Query/
  Repository и Pagerfanta. Доказать, что ответ не содержит currentBalance,
  банковских реквизитов и записей другой компании; не загрузить весь список
  `listMoneyAccounts()` ради одной страницы.
- [ ] **1.4:** добавить
  `ServiceOperationsFacadeInterface::coverage(string $service, string $month): ServiceCoverageMonth`
  и `status(string $service): ServiceStatusView`; переиспользовать
  ServiceCoverageMonthQuery/DTO. `ServiceStatusView` — новый DTO ServiceGateway:
  service, snapshot state, generatedAt/refreshedAt/stale; без предположения
  о liveness. Неверный service/месяц — domain error до запроса к сервису.
- [ ] **1.5:** обновить ARCHITECTURE.md; модульные проверки, полное внутреннее
  ревью Stage diff, исправления, Stage Report и commit/push в task Draft PR.

**Проверки:** Report/Cash/Finance focused tests, архитектурные границы и
ServiceCoverageMonthQueryTest. **Reviewer focus:** финансовый паритет,
проверка ID перед фильтрацией, отсутствие Entity и private-module imports.

## Stage 2 — два OAuth resource server и отзыв доступа

**Risk:** HIGH-LOCAL. **stage_base_commit:** записать перед 2.1.
**DoD:** оба realm изолированы; consent, refresh/revoke и per-tool policy
работают без browser session на MCP-запросе; существующие API-ключи неизменны.

- [ ] **2.1, tests first:** `McpRealmIsolationTest`, `McpCompanyIsolationTest`,
  `McpOAuthFlowTest`, `McpRevocationTest`. Проверить wrong host/audience,
  cookie-only, `vfd_` на MCP, OAuth на ключевом API, forged scopes/company,
  redirect/PKCE/code replay и отсутствие чтения/создания сессии.
  Main/spa_account session не проходит admin consent/approval; user client
  не получает admin grant через общий AS. Wrong issuer с правильным audience
  также отклоняется; параметры OAuth transaction нельзя заменить на возврате.
- [ ] **2.2:** внедрить выбранные SDK/AS версии и минимальный Mcp module wiring.
  Роуты и firewall по §1; consent UI закончится в Stage 5, backend POST
  проверяется functional-тестом. Не исключать весь модуль из ModuleAccessMap
  без равносильного fail-closed Mcp guard и architecture coverage test.
- [ ] **2.3:** реализовать grants/refresh family на persistence выбранного AS
  и два principals. JWT и серверный статус проверять вместе; scopes из token
  не заменяют свежие права. CompanyFacade financialAccess переиспользовать.
- [ ] **2.4:** явные tool policies из TASK.md. Новый scope catalog не копирует
  существующие accounts/report имена; отдельна только доступность MCP-канала.
  Тест запрещает случайно включить connected-ресурсы Bearer API.
- [ ] **2.5:** расширить AuditContextProvider и privacy/redaction для обоих
  principals. До получения результата записать admission audit metadata:
  realm, user, company nullable для global admin, client/connection, tool,
  request ID. После — outcome/duration/result-size без тела/аргументов.
- [ ] **2.6:** граничные expiry, concurrent refresh, удаление компании/участника,
  снятие platform role, revocation mid-request; Stage checks/review/report/commit.

**Проверки:** новые OAuth/unit/functional tests + существующие Api auth/permissions
и company role tests. **Reviewer focus:** identity provenance, route fallthrough,
AS vs resource server ошибки, отказ при невозможности проверить grant/права.

## Stage 3 — пользовательские tools и административная диагностика

**Risk:** HIGH-LOCAL. **stage_base_commit:** записать перед 3.1.
**DoD:** все чтения из TASK.md имеют схемы, policy и ограниченные ответы;
проверенный клиент получает совпадающие с UI отчёты, ops не раскрывает payload.

- [ ] **3.1, tests first:** `McpToolPolicyCoverageTest`, `McpReportToolsTest`,
  `McpDiagnosticsTest`, `McpLimitsTest`: прямой запрещённый call, запрет admin
  tools в user realm, invalid schemas, пагинация, deadline/concurrency, stale.
- [ ] **3.2:** зарегистрировать четыре пользовательских tools; компания из
  McpUserPrincipal, данные через Stage 1 Facade. Нельзя использовать
  ReportApiKey, `/api/public/reports/*` или browser active_company_id.
- [ ] **3.3:** диагностические tools admin; get_service_status/coverage через
  ServiceOperationsFacade. List/get tasks пока читают только operations,
  определённые в Stage 4 и созданные через MCP; cron-попытки не изобретать
  задним числом. Тесты задают явные fixtures этого контракта.
  Не заявлять готовность всего admin-каталога до интеграции Stage 4.
- [ ] **3.4:** ограничения §2, SDK error mapping, Origin/Content-Type, no-store,
  ограниченный JSON response. Тестировать очистку quota lease после исключения.
- [ ] **3.5:** проверить реальные выбранные клиенты на local/test с фиктивными
  компаниями; это compatibility acceptance, а не production company pilot.
  Данные на внешнюю LLM не отправлять без отдельной авторизации таких вызовов.
  Stage checks/review/report/commit.

**Проверки:** Mcp tools/limits functional set + report parity.
**Reviewer focus:** payload/schema, selective scope grants, переполнение
ответа, ошибка вместо тихого truncation, одинаковый deny для чужого ID.

## Stage 4 — административные операции, подтверждение и повтор

**Risk:** HIGH-LOCAL. **stage_base_commit:** записать перед 4.1.
**DoD:** оператор может подготовить/подтвердить coverage refresh и повторить
его после retryable failure; crash/retry не теряют заявку и не дублируют эффект.

- [ ] **4.1, tests first:** `AdminOperationLifecycleTest`, `AdminOperationConcurrencyTest`,
  `McpAdminApprovalTest`, `ServiceCoverageLockTest`: подмена параметров/actor,
  approve чужим соединением, просроченное подтверждение, двойной POST,
  loss of role перед worker, crash после commit, cron vs worker race.
- [ ] **4.2:** Mcp/AdminOperation и миграция: id, kind, service, immutable arguments,
  actor/connection, parentOperationId, version, status, expiresAt, timestamps,
  idempotency hash, leaseUntil, attempt/error code. Уникальность по
  connection+kind+idempotency key; индекс status/leaseUntil/createdAt;
  partial unique на service для status IN (queued,running), prepared не
  занимает слот. Для global admin не создавать
  фиктивную company ID. Business/company repositories не теряют scope.
- [ ] **4.3:** Actions внутри Mcp имеют `__invoke`:
  `PrepareCoverageRefreshAction(AdminOperationActor $actor, string $service, string $reason, string $idempotencyKey): AdminOperationView`,
  `PrepareTaskRetryAction(AdminOperationActor $actor, string $taskId, int $expectedVersion, string $reason, string $idempotencyKey): AdminOperationView`,
  `ApproveAdminOperationAction(AdminApprovalContext $actor, string $operationId, int $expectedVersion): AdminOperationView`.
  Mcp query `AdminOperationQuery` предоставляет
  `page(AdminOperationActor $actor, int $page, int $limit): AdminOperationPage`
  и `get(AdminOperationActor $actor, string $taskId): AdminOperationView`.
  Новые DTO — Mcp/Application/DTO. Контекст создаётся сервером,
  approval context — только из admin session, с connection/reauth proof.
  Prepare и approve Actions владеют транзакциями: параметры/аудит/состояние
  сохраняются атомарно, ключи не генерируются заново при retry.
- [ ] **4.4:** конечный lifecycle:
  `prepared -> queued -> running -> succeeded|failed_retryable|failed_permanent`;
  до running возможны `expired|cancelled`. Approve переводит prepared в queued
  в той же транзакции, что аудит. Одна операция — одна попытка; succeeded,
  failed_retryable и failed_permanent терминальные. Автоматических retry и
  backoff нет. Истёкший running lease переводится в failed_retryable после
  проверки отсутствия живого исполнителя под общей service-блокировкой.
  `prepare_task_retry` создаёт новую prepared-заявку с parent ID после свежей
  проверки исходной failed_retryable версии. History сохраняется.
- [ ] **4.5:** Mcp/`ProcessAdminOperationsCommand` подхватывает очередь раз в минуту,
  до 2 задач за запуск. Claim через `FOR UPDATE SKIP LOCKED`, lease 120 секунд,
  проверка credentials/role в доверенном worker-context; HTTP вне DB transaction.
  Общая блокировка принадлежит CoverageRefreshCoordinator с внедрённым
  LockFactory текущего RedisStore и ключом `service_coverage_refresh:<service>`;
  и cron, и ServiceOperationsFacade вызывают его ровно один раз, без второго
  захвата в Mcp-worker. Не полагаться на default store LockableTrait.
  Lock TTL 120 секунд покрывает ограниченный
  HTTP/DB budget; при потере lock/lease финализация запрещена и пишется ошибка.
  При занятом service lock coordinator бросает CoverageRefreshBusyException:
  worker возвращает заявку в queued, очищает lease и не расходует попытку;
  expiry проверяется до следующего claim. Не держать DB row transaction в HTTP.
  ServiceOperationsFacade добавляет
  `refreshCoverage(string $service): RefreshServiceCoverageResult` и вызывает
  существующий RefreshServiceCoverageAction через coordinator; cron
  переиспользует тот же coordinator. OAuth/grant проверяет Mcp-worker перед
  этим вызовом, не ServiceGateway. Новый Facade не дублирует Action.
  Scheduler wiring — отдельный разрешаемый пункт релиза.
- [ ] **4.6:** classify failures: transport/timeout retryable, schema violation
  permanent; неизвестные виды запрещены. Admission и mutation audit обязателен,
  ошибка сохранения approval audit откатывает queued transition. Метрики и
  denial events без сырого error текста/аргументов. Stage checks/review/report/commit.

**Проверки:** Mcp integration concurrency + ServiceGateway shared lock test + Mcp admin functional set,
не вызывать настоящие services/provider API. **Reviewer focus:** lease/crash,
durable enqueue, shared cron lock, approval state и потеря роли/connection.

## Stage 5 — UI, сквозная приёмка и общий выпуск

**Risk:** HIGH-LOCAL. **stage_base_commit:** записать перед 5.1.
**DoD:** пользователь/оператор видит и отзывает подключения, человек
подтверждает только конкретную admin-операцию; выпуск доступен всем компаниям
по единым правилам без allowlist/pilot.

- [ ] **5.1, tests first:** functional-тесты consent/connections/admin approval:
  доступ, CSRF, селект компании, списки scopes, безопасные ссылки, denial,
  expired/conflict/revoked состояния; корневые селекторы и отсутствие секретов.
- [ ] **5.2:** Twig-страницы в существующих account/admin shell, DS-компоненты.
  Без нового React app и UI-зависимостей. Шаблоны: `site/templates/mcp/{consent,connections}.html.twig`
  и `site/templates/mcp/admin/{connections,operation}.html.twig`; guards/Forms
  реализованы в Stage 2/4. GET страницы не вызывает approve. Reauth использует
  существующий PasswordHasher, отдельный CSRF POST, stamp только в admin session.
- [ ] **5.3:** локальная нагрузка по §2 и compatibility matrix, end-to-end
  revoke/refresh/user->admin denial/конкурентный retry, недоступный service,
  свежий и stale snapshot. Проверить existing UI reports и API keys regression.
- [ ] **5.4:** эксплуатационный runbook: журнал audit в отдельном Monolog channel,
  retention user 30 дней/admin 90 дней (рекомендация, согласовать с политикой
  хранения); доступ журналов только администратору через существующие средства.
  Для команд durable audit в PostgreSQL остаётся обязательным. Monitoring:
  auth failures, per-tool latency/error/429, queued age, stuck lease, revoke.
- [ ] **5.5:** аварийные меры — revoke конкретного client/grant или всех grants
  данного realm по named approval, прекращение обработки pending ops и обычный
  проверенный rollback. Не добавлять флаг каждой tool и allowlist компаний.
  Новые административные команды массового отзыва не публиковать как MCP tools.
- [ ] **5.6:** один раз full handoff gates; свежий независимый internal review
  всей задачи, Claude external review по политике, исправления и handoff.md.
  Mark Ready и единый запрос merge+deploy; если есть миграции — одновременно
  отдельный migration dispatch approval по release.md. Production config,
  signing credentials и scheduler перечислить отдельными именованными действиями.
  Нельзя объявить готовность async ops, пока production executor не развёрнут.

Выпуск без company pilot: тестовая приёмка -> общий production release.
Пользователь сам явно подключается; никто не получает токен автоматически.
Никакого второго owner gate «включить MCP после merge» в обычный релиз не добавлять.
Нет нового публичного доступа к services/ или нового домена; использовать
существующие ingress account/admin hosts, проверить их маршрутизацию.

## Команды проверок будущей реализации

Подготовка локальной/test среды — по Makefile. Не использовать production
credentials. Для повторных тестов — запущенный `site-php-cli` container,
изолированный Compose project, а не `run --rm` на каждую итерацию.

```bash
# Baseline: сначала узкий существующий набор, до продуктового кода
docker compose exec -T site-php-cli php bin/phpunit tests/Unit/Api
docker compose exec -T site-php-cli php bin/phpunit tests/Unit/Company/Security
docker compose exec -T site-php-cli php bin/phpunit tests/Unit/Report/Cashflow
docker compose exec -T site-php-cli php bin/phpunit tests/Unit/Finance/Report

# После появления новых tests/: targeted на каждый Work item
docker compose exec -T site-php-cli php bin/phpunit tests/Unit/Mcp
docker compose exec -T site-php-cli php bin/phpunit tests/Functional/Mcp
docker compose exec -T site-php-cli php bin/phpunit tests/Integration/Mcp
docker compose exec -T site-php-cli php bin/phpunit tests/Integration/ServiceGateway

# Stage 5 / handoff один раз, не после каждого Work item
make site-stan
make site-cs-check
make site-cs-strict-types
make site-test-unit
make site-test
make site-cs-twig
```

Если Composer service не запущен, подготовить его один раз; не заявлять, что
exec-команда прошла без контейнера. Узкий анализ изменённых PHP — теми же
проектными PHPStan/CS configs. Если меняются frontend assets, добавить реальные
npm lint/build/test:unit и api:types:check по CLAUDE.frontend.md в контейнере;
для чистых Twig-страниц не придумывать React component tests.

## Итоговый чек-лист приёмки

- [ ] User/admin имеют разные подключения, audience, токены, consent и tools.
- [ ] Cookie, другой host/realm, API-ключ и неверные issuer/audience/expiry не дают MCP-доступ.
- [ ] User company привязана к grant, чужие IDs не игнорируются и не раскрываются.
- [ ] Отзыв и потеря прав действуют до следующего исполнения; нет session fallback.
- [ ] Connected-права старых API-ключей и старые report endpoint'ы неизменны.
- [ ] Отчёты совпадают с UI; деньги/валюты/периоды корректны, усечения нет.
- [ ] Все tools имеют явные schema/policy; прямой запрещённый call отклоняется.
- [ ] Admin diagnostics не выдаёт raw payload, secrets, ПДн или ложную liveness.
- [ ] Coverage refresh/retry требует недавнего человеческого подтверждения;
  replays, подмена, double-click, потеря роли и cron race покрыты тестами.
- [ ] Только поддержанный тип; нет shell/SQL/general HTTP/retry-all/infra tools.
- [ ] Заявки/attempts переживают crash, повтор не создаёт новый эффект и не теряет аудит.
- [ ] Нагрузка ограничена до запроса; сохранены rate limits services/.
- [ ] Общий выпуск без пилота/allowlist; миграции/config approvals названы явно.
- [ ] Наблюдаемость, совместимость клиентов и аварийный runbook проверены.

## Связь с исходным ревью

BLOCKER об отсутствии read Facade закрывается Stage 1; IMPORTANT об identity,
token lifecycle, firewall/client registration и scopes — Phase 0 + Stage 2;
нагрузка/аварийные меры — Stage 3/5; transport/audit/errors — Stage 2/3/5.
Новый admin scope раскрыт TASK.md §5 и Stage 4. Итоги проверки документов и
повторного независимого ревью фиксируются в [review.md](review.md).
