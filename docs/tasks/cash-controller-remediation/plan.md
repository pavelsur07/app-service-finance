# Cash controller remediation — implementation plan

Source of intent: owner brief in chat, 2026-11-10. Five stages below implement that brief.

Task base: `22b07f6bab584d8c4917d82d721e0a3a482af059`
Baseline: isolated container `vf-cash-remediation-php`, PHP 8.4.27; targeted CreateDocumentFromTransactionActionTest + SaveCashTransactionSplitsActionTest: **25 tests, 74 assertions, PASS**. Router JSON captured before changes.

Constraints: no migrations/outbox/new dependencies; existing IDs/data/formulas/routes/HTTP/CSRF/access retained; new IDs UUIDv7; Money half-up, empty fund amount=0. No historical repair, production commands or production scheduling. One branch/Draft PR, Ready after all stages; merge/deploy requires owner approval. Production recovery scheduling separately approved.

Patterns examined: source lock in CashSettlementSourceLifecycle and CreateDocumentFromTransactionAction; balance transaction in AccountBalanceService; split action tests. Import exploration delegated read-only per AGENTS.
## Stage 1: Финансовые инварианты и точность сумм
Risk: HIGH-LOCAL
stage_base_commit: 22b07f6bab584d8c4917d82d721e0a3a482af059
Definition of Done: Редактирование не нарушает сумму разбивки; ОПиУ из мультиразбивки отвергается; RUB/JPY/BHD, пустые/отрицательные/ошибочные/переполняющие суммы проверены.
Work items: 1.1 Защита суммы/валюты/категории мультиразбивки; 1.2 единая блокировка источника и атомарное сохранение разбивки/аудита/матча; 1.3 отказ обеих точек создания ОПиУ; 1.4 строковые суммы фондов через Money half-up.
Checks: RED→GREEN regressions per bug; Cash module integration/functional/unit tests and focused PHP lint/PHPStan. External review at HIGH-LOCAL Stage if diff >500 lines.
Reviewer focus: company isolation, current data under source lock, atomicity, financial invariants, unchanged contracts, failure handling, bounded queue scans.

## Stage 2: Атомарность счетов и восстановление транзакций
Risk: HIGH-LOCAL
stage_base_commit: 97c0d7495
Definition of Done: Сбой пересчёта откатывает источник и остатки; ORM не затирает DBAL; восстановление инвалидирует dashboard после commit; маршруты/доступ/CSRF сохранены.
Work items: 2.1 SaveMoneyAccountAction владеет сохранением и пересчётом под общей блокировкой; 2.2 событие после успешного восстановления; 2.3 разделение контроллеров счетов/фондов/переводов/календаря и CompanyFacade.
Checks: RED→GREEN regressions per bug; Cash module integration/functional/unit tests and focused PHP lint/PHPStan. External review at HIGH-LOCAL Stage if diff >500 lines.
Reviewer focus: company isolation, current data under source lock, atomicity, financial invariants, unchanged contracts, failure handling, bounded queue scans.

## Stage 3: Изоляция и валидация импорта
Risk: HIGH-LOCAL
stage_base_commit: 32ba6693e
Definition of Done: Сессия другой/неизвестной компании сброшена до чтения; чужие IDs недоступны; ожидаемые ошибки ввода отображаются без 500; финансовые правила импорта сохранены.
Work items: 3.1 company_id и scoped guard до storage; 3.2 общий структурный mapping validator, черновики допустимы; 3.3 CSV/XLS/XLSX ≤32MiB и строгие даты журнала; 3.4 одноэкшеновые контроллеры и Actions подготовки/подтверждения.
Checks: RED→GREEN regressions per bug; Cash module integration/functional/unit tests and focused PHP lint/PHPStan. External review at HIGH-LOCAL Stage if diff >500 lines.
Reviewer focus: company isolation, current data under source lock, atomicity, financial invariants, unchanged contracts, failure handling, bounded queue scans.

## Stage 4: Восстановление доставки и backend-контракты
Risk: HIGH-LOCAL
stage_base_commit: 967ac022d
Definition of Done: Stream/delayed/failed не переотправляются; Redis outage fail-closed; cursor проходит пропущенные; idempotent handler; контроллеры без flush/SQL/entities; прогноз не зависит от страницы.
Work items: 4.1 журнал/job атомарны, retry возвращает job; 4.2 dry-run recovery queued >120s, cursor 100, один пакетный paged queue scan и dispatcher company lock; 4.3 Transaction controllers и новые UUIDv7 в Actions; 4.4 JSON error listener 404/403/422; 4.5 Pagerfanta page=1 limit=50 max=200, неверные/недоступные страницы 422.
Checks: RED→GREEN regressions per bug; Cash module integration/functional/unit tests and focused PHP lint/PHPStan. External review at HIGH-LOCAL Stage if diff >500 lines.
Reviewer focus: company isolation, current data under source lock, atomicity, financial invariants, unchanged contracts, failure handling, bounded queue scans.

## Stage 5: Шаблоны и интерфейсная приёмка
Risk: MEDIUM
stage_base_commit: record before implementation
Definition of Done: Все страницы доступны без пропусков; фильтры сохранены; Money format/layout/access прежние; Twig lint и функциональные проверки проходят.
Work items: 5.1 существующий pagination partial для счетов/фондов/профилей/календаря; 5.2 фильтры/limit/empty states; 5.3 ошибки и подсказка мультиразбивки; 5.4 READ-only/responsive smoke.
Checks: RED→GREEN regressions per bug; Cash module integration/functional/unit tests and focused PHP lint/PHPStan. External review at HIGH-LOCAL Stage if diff >500 lines.
Reviewer focus: company isolation, current data under source lock, atomicity, financial invariants, unchanged contracts, failure handling, bounded queue scans.

## Handoff
Full gates once: make site-stan, site-cs-check, site-cs-strict-types, site-test-unit, site-test, site-cs-twig. Fresh-context internal review + external reviewer per repository policy; architecture contracts, stage reports, handoff and review metrics. Commit/push single Draft PR, Ready only after Stage 5.

## Explicit follow-ups
Recurring materialization on GET; lost processing message recovery; historical discrepancy diagnosis.

## Pre-flight interfaces
Stage 1→2: common source lock retained for update/restore. Stage 3→4: company-scoped session guard and structural mapping validator feed preparation/confirmation Actions; completeness belongs to normalizer. Stage 4→5: Pagerfanta objects retain full-period forecast and unbounded form choices.

## Stage 4 implementation notes
- Dispatcher takes company session advisory locks before the shared queue snapshot; DB candidates keyset pages of 100, advancing by last examined ID even when messages are skipped. One queue probe across all locked companies/candidates; existing two transition-safe passes remain constant, delayed/stream reads paginated 500.
- Recheck queued/age/failed membership before each send; no row locks/DB transaction across network. Busy companies skipped safely. Default dry-run, execute only by flag; terminal/lost-closed statuses excluded. Existing Handler owns duplicate/idempotent execution.
- Test atomic no-confirmation log/job rollback, dispatch-failure committed state, 201+ skipped-head candidates, multiple companies/one probe, failed/stream/delayed exclusions, unavailable Redis, lock exclusion/status races, bounded large-queue scan, existing duplicate Handler behavior.
- Backend page helper validates page/limit and wraps existing Pagerfanta adapters; calendar forecast/form choices remain unpaginated. Templates only Stage 5.
- Existing owner design/plan and autonomy contract supply implementation authorization; brainstorming does not reopen already decided business semantics or add intermediate approval gates.
