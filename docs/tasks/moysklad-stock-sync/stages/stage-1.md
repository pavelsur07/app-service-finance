### Stage 1: контракт, схема и парсеры — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `13f706a92e847eb21d1c82c303257b5196735b3e`
**Work items:** 1.1, 1.2, 1.3

#### What was done

- Зафиксирован проверенный контракт складов и отчёта остатков.
- Добавлены `MoySkladStore`, `MoySkladStockSnapshot` и
  `MoySkladStockSnapshotLine` с tenant isolation на уровне запросов и БД.
- Добавлены parser/DTO для страниц складов и остатков, включая точное чтение
  decimal из сырого JSON и экспоненциальной записи.
- Добавлена миграция с FK, XOR/unique/check constraints, индексами и триггерами
  неизменяемости опубликованных и failed snapshots.
- Добавлены обезличенные contract fixtures, builders, unit и integration tests.

#### Files changed

- `site/migrations/Version20260921100000.php` — schema and invariants.
- `site/src/MoySklad/Application/` — page DTO and parsers.
- `site/src/MoySklad/Domain/` — parsed snapshots.
- `site/src/MoySklad/Entity/` — stores, snapshots and lines.
- `site/src/MoySklad/Infrastructure/Repository/` — company-scoped reads.
- `site/tests/Fixtures/MoySklad/` — sanitized Store/Stock samples.
- `site/tests/Unit/MoySklad/`, `site/tests/Integration/MoySklad/` — coverage.
- `ARCHITECTURE.md`, `docs/tasks/moysklad-stock-sync/` — contracts and reports.

#### Definition of Done

- [x] Store and stock response fields and page metadata are validated.
- [x] Decimal values remain exact and fit `numeric(30,10)`.
- [x] Every repository query and every FK is tenant scoped.
- [x] A line references exactly one product or variant of the same connection.
- [x] Terminal snapshots and their lines cannot be reopened or changed.
- [x] Migration up/down, guarded rollback and Doctrine mapping are verified.
- [x] Fixtures are sanitized and contain no token or raw production payload.

#### Checks

- baseline: `make site-test-unit` — 2965 tests, 16341 assertions, PASS.
- module: MoySklad unit+integration — 201 tests, 797 assertions, PASS.
- migration: down/up — PASS; non-empty rollback guard — PASS.
- `make site-stan` — PASS.
- `make site-cs-check` — PASS.
- `make site-cs-strict-types` — PASS.
- `make site-test-unit` — 3006 tests, 16453 assertions, PASS.
- `make site-test` — 5174 tests, 29330 assertions, PASS.

#### Internal review

- iterations: 7; BLOCKER: 0; IMPORTANT: 9 found and fixed; open: none.
- safe MINOR fixes included the completed lookup index and strict page meta.
- final result: `REVIEW_GREEN`.

#### External review

- required: yes, Large handoff and HIGH-LOCAL migration.
- rounds: 1; BLOCKER: 0; IMPORTANT: 1 fixed; result: fixed without re-run.
- confirmed fixed: exact scientific notation normalization.
- safe MINOR fixed: builders are exercised; all three decimal columns are
  checked after persistence.
- rejected: stock-level `name` remains strictly validated because it is a
  required source contract field with the same 255-character bound as Store.
- documented: the non-concurrent unique index on existing variants briefly
  blocks writes during the production migration.
- reviewer limitation: read-only environment could not execute Docker; local
  gate evidence was supplied in context.

#### Risks / reviewer focus

- The migration creates a unique index on the existing `moysklad_variants`
  table without `CONCURRENTLY`; production migration can briefly block writes.
- Rollback is automatic only while the three new stock tables are empty.

#### Next

- handoff and release of Stage 1; Stages 2 and 3 continue in separate work.
