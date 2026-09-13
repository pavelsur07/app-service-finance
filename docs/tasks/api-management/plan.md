# api-management: управление ключами и подготовленными правами

Base: 22399152. Изолированный worktree: /home/deploy/projects/app-service-finance-api-management; branch feat/api-management.
Дизайн утверждён исходным планом; повторное согласование не требуется по AGENTS.md §3.
Референсы: Company/ReportApiKeyController (только legacy contract), Company role Actions (owner checks), Inventory (новый модуль), Shared AuditLog. Legacy flash выдача report secret не переиспользуется.

Baseline: Company/Security unit 29 tests / 119 assertions PASS; Shared AuditLogSubscriber 1 test / 1 assertion PASS; ModuleWriteGateCoverage 1 test / 12 assertions PASS. Architecture PHPStan pending.

## Stage 1: Backend ключей, company public ID, auth/check, аудит и OpenAPI
Risk: HIGH-LOCAL. stage_base_commit: 22399152.
DoD: все backend контракты TASK.md; старые выгрузки неизменны; tests; архитектура/README.
Work items: 1.1 company sequence+backfill+Facade+tests; 1.2 keys/owner Actions/atomic audit+tests; 1.3 stateless authentication/context/problem/rate limit/check/OpenAPI+tests.
Checks: Company/Api targeted unit, integration, functional; focused PHPStan/style; module test sets.
Review focus: IDOR, owner A/member B, секреты, atomicity, expiry, migration compatibility, stateless fail-closed.

## Stage 2: UI создания, списка, проверки, переименования, отзыва
Risk: MEDIUM. stage_base_commit:3edbb656.
DoD: owner-only Twig + Forms, no-store one-time secret, CSRF, existing UI Kit, empty/error/mobile.
Work items: 2.1 controllers/forms/pagination; 2.2 templates/navigation/copy and functional verification.
Checks: Api UI functional, Twig/YAML lint, UI Kit lint/build.
Review focus: CSRF, secret response caching/reopen, scope, failures.

## Stage 3: Каталог и подготовленные разрешения
Risk: HIGH-LOCAL. stage_base_commit:36b773b3.
DoD: explicit catalog, selected/enabled/effective, default deny policy, versioned audited owner save, 409, tests with test-only controllers.
Work items: 3.1 catalog + policy + independent permissions tests; 3.2 save action/conflict/audit; 3.3 endpoint policy enforcement tests.
Checks: Api module tests; targeted static/style; external if >500 lines.
Review focus: disconnected and newly-connected remain ineffective; unknown scopes; lost updates.

## Stage 4: Матрица прав и handoff
Risk: MEDIUM. stage_base_commit: record before work.
DoD: editable prepared scopes/status/presets, clear disconnected warning and conflict reload; docs complete.
Work items: 4.1 matrix forms/templates; 4.2 functional/UI verification; 4.3 full gates + fresh internal review + external review + Ready PR/handoff.
Checks: full gates listed in TASK.md, applicable UI and OpenAPI generated types.
Review focus: explicit owner enablement, no auto grants, conflict/error UX, complete task diff.

## Миграции
Expand-only: add sequence+generated column/default+unique and api key table. Existing rows assigned by PostgreSQL; old inserts omit column and receive default. Keep public ID immutable and sequence non-cycling. UUID references/data untouched. Snapshot local row counts/UUIDs before migration, compare after. New table indexes company and public key ID; no secret plaintext. Rollback cannot safely reuse published IDs: forward-fix after release. Production dispatch separately authorized at handoff, no production access in implementation.
