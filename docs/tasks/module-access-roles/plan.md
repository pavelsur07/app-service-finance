# Модульные роли доступа (Финансы / Маркетплейсы / Сделки / Каталог / Админ)

Источник: owner brief в чате (2026-08-05). Владелец компании управляет доступом участников
к модулям через шаблоны ролей (системные + свои копии), уровни доступа: `none | read | write`.
Per-member override НЕ делаем (решение владельца): нестандартный доступ = копия шаблона.

## Решённые вопросы (зафиксированы с owner)

- Per-member override: нет, только шаблоны (`company_role`).
- Группы модулей: `finance / marketplace / deals / catalog / admin`. Шестой группы `system`
  в `Module` enum нет: Admin, Mcp, Telegram `/admin/*` и debug-роуты попали в `EXEMPT_PREFIXES`
  и остаются под `ROLE_ADMIN`/`ROLE_SUPER_ADMIN`. Модуль, который нельзя выдать шаблоном,
  в enum не нужен — иначе «Полный доступ» формально грантил бы `system:write`, который никто не проверяет.
- «Аналитика и остатки» слиты в `marketplace` (Marketplace, MarketplaceAds, MarketplaceAnalytics,
  Inventory, Ingestion, MoySklad).
- `Ai` → `finance`. `/counterparties`, `/financial-responsibility-centers` (внутри Company) → `finance`;
  остальное в Company → `admin`. Telegram-интеграция пользователя → `admin`.

## Карта модулей (namespace → группа)

| Группа | Модули кода |
|---|---|
| `finance` | Cash, Finance, Balance, Report, Loan, Ai, Company (только counterparties и ЦФО-контроллеры) |
| `marketplace` | Marketplace, MarketplaceAds, MarketplaceAnalytics, Inventory, Ingestion, MoySklad |
| `deals` | Deals |
| `catalog` | Catalog |
| `admin` | Company (прочее), Billing, Telegram (пользовательские интеграции) |
| exempt (не модуль) | Admin, Mcp, Analytics, Notification, Telegram `/admin/*`, Marketplace `/admin/*` — свои гейты `ROLE_ADMIN`/`ROLE_SUPER_ADMIN` |
| public | логин/регистрация/инвайт, `/telegram/webhook`, `/_health`, `/api/health/*`, `/api/public/*` |

## Архитектура

- `company_role` (id, company_id null = системный, name, permissions jsonb {module: level}).
- `CompanyMember.role_id` → company_role. Бэкфилл по текущей роли (OWNER → системный «Владелец»,
  OPERATOR → системный «Полный доступ» = write везде, поведение не меняется).
- `Module` enum (string backed, label()) — единственный источник списка групп.
- `ModuleAccessMap` — карта namespace-префиксов контроллеров → Module.
- `ModuleAccessVoter` — атрибуты `module.<group>.read|write`; write ⊃ read; владелец компании всегда allow.
- `ModuleAccessSubscriber` (controller event, fail-closed): read-гейт по карте неймспейсов;
  непокрытый контроллер без `#[PublicAccess]` — 403.
- Write-гейты: `#[IsGranted('module.<group>.write')]` на мутирующих контроллерах/экшенах (Stage 3–4).
- Тест покрытия: каждый контроллер либо в карте, либо `#[PublicAccess]`.
- UI владельца: страница шаблонов + выбор шаблона участнику (Stage 2), меню по is_granted (Stage 5).

## Stage 1: Модель и ядро (backend)

Risk: HIGH-LOCAL (миграция + безопасность)
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: a5e5a4d4

Definition of Done:
- `Module` enum, `ModuleAccess` константы атрибутов, `ModuleAccessMap` покрывает все 30 модулей src/.
- Сущность `CompanyRole` + миграция (таблица `company_role`, `company_members.role_id`, FK, бэкфилл
  системных шаблонов и привязки участников; недеструктивная, expand-only).
- `ModuleAccessVoter`: read/write, write⊃read, владелец компании = полный доступ, чужая компания = deny.
- `ModuleAccessSubscriber` fail-closed + атрибут `#[PublicAccess]`; существующие public-роуты помечены.
- Тест покрытия контроллеров (все в карте или помечены) — зелёный.
- Unit/functional-тесты voter'а и subscriber'а; существующие тесты доступа (CompanyMemberAccessTest и др.) зелёны.
- Backward compat: `CompanyMember.role` (строка) сохраняется и используется как fallback до Stage 2.

Work items:
- 1.1 — Module enum + ModuleAccess + ModuleAccessMap + атрибут PublicAccess
- 1.2 — CompanyRole entity + repository + миграция с бэкфиллом
- 1.3 — CompanyMember.role_id + ActiveCompanyService::getActiveMembership()
- 1.4 — ModuleAccessVoter
- 1.5 — ModuleAccessSubscriber (fail-closed) + разметка public-роутов
- 1.6 — Тест покрытия контроллеров
- 1.7 — Unit/functional-тесты, прогон, self-review

Stage checks:
- `make site-test-unit`
- функциональные тесты Company (доступ/инвайты)
- `make site-test-migrations` (миграция применяется на чистой тестовой БД)

Reviewer focus: корректность бэкфилла (никого не отрезать), fail-closed не ломает public-роуты,
company isolation в voter'е, отсутствие N+1 (мемоизация на запрос).

## Stage 2: UI владельца — шаблоны ролей и назначение

Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no

Definition of Done:
- Страница «Шаблоны ролей»: список системных (read-only) и своих; создание копии с чекбоксами
  «нет/чтение/запись» по 5 группам; редактирование/удаление своих (нельзя удалить назначенный).
- Карточка участника: выбор шаблона (селект), просмотр «что входит».
- Инвайт: выбор шаблона при приглашении (CompanyInvite получает role_id).
- Защита «последний admin:write»: нельзя понизить/отключить последнего участника с admin:write.
- Аудит: лог изменений шаблона/назначения (через logger, контекст company/user/role).
- Только для владельца компании (`assertOwner`). **Исправлено 2026-08-11:** исходный план обещал
  заменить `assertOwner` на `module.admin.write` в Stage 3 — это дало бы self-escalation. Участник
  с `admin:write` отредактировал бы собственный шаблон и выдал себе `finance:write`, то есть поднял
  бы себя до полного доступа. Управление шаблонами и назначение ролей остаются owner-only;
  `module.admin.write` для этих экранов не применяется ни в Stage 3, ни позже.
- Functional-тесты UI-флоу.

Work items:
- 2.1 — CompanyRole CRUD (контроллер + формы + шаблоны)
- 2.2 — Назначение шаблона участнику + выбор в инвайте (CompanyInviteManager, форма, приём инвайта)
- 2.3 — Защита последнего admin + аудит-лог
- 2.4 — Тесты, прогон, self-review

Дополнительные требования из внешней ревизии Stage 1:
- F1: acceptInvite проставляет SystemCompanyRoles::FULL_ACCESS_ID (или выбранный в инвайте шаблон);
  нельзя удалить назначенный шаблон (проверка использования); явно «очищенный» шаблон = нет доступа,
  а не legacy-fallback; в UI скрывать выбор шаблона у OWNER-участников; setAccessRole валидирует
  company-принадлежность шаблона при назначении (не только в resolver'е).
- F2: /api/dashboard/v1/snapshot (App\Analytics) разделить по правам НА Stage 2, не дожидаясь Stage 5 —
  как только шаблоны становятся назначаемыми, агрегаты не должны утекать read-only ролям.

Stage checks: `make site-test-unit`, функциональные тесты Company.

## Stage 3: Write-гейты — finance, deals, catalog, admin

Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no

Definition of Done:
- На всех мутирующих контроллерах/экшенах Cash, Finance, Balance, Report, Loan, Ai, Deals, Catalog,
  Company(admin), Billing, Telegram стоит `module.*.write` (class-level для write-контроллеров,
  method-level для смешанных).
- Существующие owner-гейты **сохраняются, а не заменяются**. `module.admin.write` слабее owner-only,
  поэтому подмена расширила бы доступ. Явные случаи, которые нельзя ослабить:
  - `CompanyRoleController` и назначение шаблонов в `CompanyMemberController` — owner-only
    (иначе self-escalation, см. Stage 2);
  - `ReportApiKeyController` — master ограничил generate/revoke владельцем компании в `c45a9f74`
    (CRITICAL); гейт сохранить как есть.
- Миграции ветки перенумерованы выше последней задеплоенной на прод (`Version20260809120000`),
  чтобы merge не приводил к out-of-order прогону.
- `company_role` получает unique index по `(company_id, name)` — follow-up, дважды перенесённый
  из Stage 1 и Stage 2.
- `flush()` вынесен из `CompanyRoleRepository` и `CompanyMemberRepository` в Action-слой
  (`src/Company/Application/` уже существует, так что основания для отсрочки нет —
  «Глобальные запреты» CLAUDE.md).
- Functional-тесты: read=200, write без права=403, none=403 — по репрезентативному эндпоинту на группу.

Work items:
- 3.1 — merge master в ветку, резолюция коллизии роутов (сделано: `bc030ed4`)
- 3.2 — правки плана по итогам ревизии (этот коммит)
- 3.3 — перенумерация миграций выше задеплоенной
- 3.4 — инвентаризация контроллеров групп (классификация read/write)
- 3.5 — расстановка write-гейтов
- 3.6 — unique index `company_role(company_id, name)`
- 3.7 — `flush()` из репозиториев в Action
- 3.8 — тесты, прогон, self-review

## Stage 4: Write-гейты — marketplace

Risk: MEDIUM
owner_gate: no
release_candidate: no
independently_deployable: no

Definition of Done: аналогично Stage 3 для Marketplace, MarketplaceAds, MarketplaceAnalytics,
Inventory, Ingestion, MoySklad (включая `Api/` подпапки и `Marketplace/Controller/Inventory`).

Work items:
- 4.1 — инвентаризация
- 4.2 — расстановка гейтов
- 4.3 — тесты, прогон, self-review
- ~~4.4 — оценить Debug*-контроллеры marketplace~~ — **снят 2026-08-11**: master удалил
  `DebugWipeCompanyDataController` коммитом `c45a9f74` как CRITICAL (GET, удаляющий данные компании
  под ROLE_COMPANY_USER). Делать нечего, файл ушёл при мерже.

## Stage 5: Меню и дашборд

Risk: LOW
owner_gate: no
release_candidate: no
independently_deployable: no

Definition of Done:
- `_sidebar.html.twig` (и `_sidebar_marketplace.html.twig`, `app/_shell/sidebar.html.twig`) скрывают
  разделы по `is_granted('module.<group>.read')`.
- Визуальная проверка + функциональный тест меню (пункты скрыты у read-only пользователя).
- **Примечание:** гейтинг дашборд-снапшота (`/api/dashboard/v1/snapshot`, `App\Analytics`) вынесен
  в Stage 2 (F2 внешней ревизии Stage 1) — он должен быть закрыт до того, как ограниченные шаблоны
  станут назначаемыми.

Work items:
- 5.1 — сайдбары
- 5.2 — дашборд
- 5.3 — тесты, прогон, self-review

## Решение по роутам лендинга (принято при мерже master, `bc030ed4`)

Stage 1 забрал `/dashboard` под легаси финансовый дашборд и удалил `home/dashboard.html.twig`.
Пока ветка лежала в стороне, master развивал именно этот шаблон: React DashboardGrid на snapshot API
(`d58acc31`) и UI мультивалютных переводов (`0e646cb4`). Прямой мерж уничтожил бы работу master.

Итоговое распределение:

| Роут | Имя | Что отдаёт |
|---|---|---|
| `/` | `app_home_index` | `HomeRedirectController` — редирект на первый доступный модуль |
| `/finance` | `app_finance_index` | легаси финансовый дашборд (`HomeController::index`) |
| `/dashboard` | `app_dashboard_index` | React-пилот (`HomeController::dashboard`), сохранён от master |

`app_home_index` намеренно оставлен за `/`: на него ссылается десяток шаблонов, и переименование
сломало бы их без нужды. Легаси-дашборд переехал на новый роут, потому что ветка уже согласилась
увести его с `/`, а `/dashboard` занят пилотом.

## Чего НЕ меняем

- Глобальные `User.roles` и role_hierarchy (ROLE_ADMIN/SUPER_ADMIN остаются как есть).
- `access_control` в security.yaml (грубый гейт не трогаем).
- Финансовые формулы, маппинги категорий, бизнес-логику модулей.
- Per-member override permissions (отклонено владельцем).

## Финальный Release Gate

Полный прогон тестов, обе ревизии по всему diff от a5e5a4d4, handoff, Draft PR (draft).
