# MCP-план: проверка и независимое ревью

## Подготовка документов

- База: `5aff7dbfd`, отдельная ветка `docs/mcp-access-plan`.
- Задача: только документация; код, зависимости, конфигурация и БД не меняются.
- Baseline: `git diff --check` — exit 0; worktree был чист.
- PHP/JS-тесты не запускались: поведение приложения не меняется.
- Текущие незакоммиченные файлы владельца в основном worktree не затрагивались.

## Исходное ревью Claude CLI

Раунд 1, effort medium, safe-mode, Read/Glob/Grep без записи, shell, web и MCP.
Результат `REVIEW_FINDINGS`: BLOCKER 1 / IMPORTANT 6. Проверено по коду:

| Находка | Учёт в расширенном плане |
|---|---|
| Нет полных отчётных Facade | Stage 1: read-контракты и паритет с общими UI builders |
| Сессионные права и ограниченный audit context | Stage 2: два principals, CompanyFacade financialAccess, явный audit context |
| Не определён token lifecycle | §1.2: grant status на каждый вызов, refresh rotation/replay, revoke |
| Firewall и client registration | Phase 0, §1.1, Stage 2 |
| Connected scopes ключевого API | TASK.md §4, Stage 2: отдельная доступность MCP без изменения Bearer API |
| Выпуск без пилота и пределы нагрузки | §2, Stage 3/5: общий выпуск, quotas, revoke/rollback runbook |
| Transport/errors/read audit | §1/2, Stage 2/3/5 |

Не приняты как безусловные требования: флаг каждой tool, новая таблица аудита
чтений, второй owner gate включения после штатного merge/deploy. Для них не
доказана необходимость; второй gate противоречит AGENTS.md §3.2.
Не меняем существующие финансовые расчёты или старые публичные отчёты.

## Расширение scope владельцем

Административный MCP: диагностика, статусы задач и прикладной повтор обработки.
Отдельные audience/grants/catalog; отсутствие infrastructure tools; начальный
тип `service_coverage_refresh`, human approval и durable execution.
Вопросы D1–D6 остаются решениями до реализации; предложения не выдаются за согласие.

## Повторное ревью

Раунд 2 через Claude CLI, effort medium, safe-mode, только Read/Glob/Grep.
Процесс завершился с exit 0, итоговый маркер `REVIEW_FINDINGS`:
**BLOCKER 0 / IMPORTANT 4 / MINOR 3**. Продуктовая реализация не проверялась.

Подтверждённые замечания исправлены в документах **fixed without re-run**
по AGENTS.md §7.2: новый BLOCKER не найден, повтор после IMPORTANT не нужен.
Это результат обработки замечаний, а не полученный от CLI `REVIEW_GREEN`.

| Находка раунда 2 | Минимальное исправление и проверка |
|---|---|
| Два authorize route при одном AS metadata | Один advertised authorize dispatcher, server-owned transaction, отдельные session consent routes. Проверены metadata/client/resource связи по RFC8707; feasibility AS component — явный критерий Phase 0 |
| Admin consent попадал в main | Admin consent под `/admin/oauth/authorize`, approval под `/admin/mcp/*`, context admin; user consent context main. Protocol/discovery PUBLIC_ACCESS и stateless firewall перечислены явно, cross-session негативный тест добавлен |
| Неопределённая семантика фильтров/ОПиУ | all/partial/empty/foreign явно заданы по существующим UI mappers, оракулы паритета — UI JSON exports; formats/warnings сохранены. ДДС проверяется по валютам, currency денежных строк ОПиУ — обязательный D6. Лимит ОПиУ 31 колонка до нагрузочных измерений |
| Auto retry и ручной retry противоречили друг другу | Одна попытка на подтверждение; terminal failure требует новой заявки. Активные статусы только queued/running; worker раз в минуту, занятый lock не тратит попытку |
| MINOR: LockableTrait не гарантирует общий store | Оба пути получают внедрённый Redis LockFactory с общим ключом; lease/lock и ограниченный budget описаны явно |
| MINOR: ROLE_SUPER_ADMIN наследует ROLE_ADMIN | Использовать существующий role_hierarchy, как SpaAdminUserChecker |
| MINOR: видимость list_tasks | Только MCP-created operations; stale/cron monitoring не выдают за отдельные записи задач |

Часть рекомендации №1 о **необходимости двух issuer** отклонена: один AS
может защищать несколько resource server (RFC8707 §2), если client/resource/
grant bindings и audience проверены. Исправлен реальный дефект discovery —
два независимых публичных authorize entry при одном metadata. Это сохраняет
два изолированных MCP-доступа без требования второго OAuth-сервиса.

Ограничения ревью: отсутствуют vendor, production ingress и выбранные реальные
клиенты; AS/SDK runtime совместимость не доказана. Финансовые расчёты проверены
по существующим контрактам и источникам, не запуском отчётов. Все эти проверки
явно входят в Phase 0/будущую приёмку; тесты и deploy не заявляются выполненными.

## Итог проверки документов после раунда 2

Внутренняя сверка по коду: BLOCKER 0 / IMPORTANT 1, исправлен. Durable заявки
и worker перенесены в проектируемый Mcp module, который владеет OAuth grants;
ServiceGateway оставлен узким исполнителем refresh. Это исключает зависимость
ServiceGateway от Mcp для проверки отзыва и цикл между модулями.
Открытых BLOCKER/IMPORTANT в обработанном плане нет. D1–D6 — явно обозначенные
решения до реализации; документация не означает согласия с рекомендациями.
Проверены локальные ссылки, code fences, Stage 1–5 и tests-first Work items;
`git diff --cached --check` — без ошибок. Изменены только эти три Markdown-файла.

## Раунд 3 — повторный запрос владельца

Проверен точный опубликованный commit `123e18134921dc17e20d15521cd331399f0bfc65`
PR #3011. Claude CLI 2.1.323, effort medium, safe-mode, только Read/Glob/Grep;
предыдущий review.md не передавался до формирования выводов.
Процесс exit 0, итоговый маркер **REVIEW_FINDINGS**:
**BLOCKER 1 / IMPORTANT 3 / MINOR 3**. [Полный отчёт](review-round-3.md).

| Находка | Проверка и поправка плана после ревью |
|---|---|
| B1: SPA-хосты не пропускают MCP/OAuth/Twig | Подтверждено compose routers, frontend nginx и SpaHttpSubscriber. Оба MCP/AS/consent/approval перенесены в плане на существующий legacy app.vashfindir.ru; изоляция по path/firewall/context/audience. SPA boundary не меняется. Reauth-stamp связан с admin context и userId, а не просто cookie |
| I1: statement_timeout не ограничивает весь отчёт | Подтверждены per-period/per-category запросы. Добавлены global report concurrency, monotonic execution budget с checkpoints внутри расчёта, SQL timeout по остатку времени и нагрузочная приёмка Stage 1/3 |
| I2: один expiresAt неоднозначен после approve | approvalExpiresAt=preparedAt+5min, отдельно executeBy=approvedAt+5min; queued expiry и Busy-requeue имеют явные тесты и события |
| I3: не задан durable audit/actor в worker | Shared AuditLog требует companyId и не подходит global ops. Запроектирован append-only AdminOperationEvent в транзакции со сменой состояния; worker явно передаёт initiatedBy/approvedBy/connection и system executor. Добавлены approvedAt/reauthAt/startedAt/finishedAt |
| M1: list/get tasks раньше Entity | Перенесены в Stage 4 после AdminOperation |
| M2: dispatcher создаёт неограниченные записи | Добавлены IP/client quotas, предел активных OAuth transactions, TTL и очистка |
| M3: не описаны потомки проекта | В schema/description и parity cases закреплены descendants и отличие всех корней от полного набора UI choices |

Внутренне проверены поправки по исходникам и согласованность документов;
код приложения и production не менялись, runtime-тесты не запускались.
Повторного независимого ревью исправленного B1 нет: бюджет трёх раундов
AGENTS.md §7.2 исчерпан, четвёртый раунд автоматически не запускался.
**REVIEW_GREEN не получен; результат внешнего ревью не объявляется зелёным.**
PR возвращается в Draft. Решения D1–D6 и Phase 0 проверки по-прежнему открыты
до зависимой реализации; документ не является разрешением на разработку/деплой.
