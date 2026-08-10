# Stage 1 Report: инварианты дерева категорий

## Result

- Обычные категории могут находиться в root.
- Обычные категории могут быть дочерними только у `CF_OP`, `CF_FIN`, `CF_INV` или обычной нетехнической категории.
- `TECHNICAL` зарезервирован для системных категорий.
- Self-parent, циклы, cross-company parent и итоговая глубина более пяти уровней запрещены.
- Detach/reparent больше не планирует Doctrine orphan deletion.
- Удаление категории с дочерними статьями явно запрещено вместо скрытого cascade-delete или FK 500.

## Checks

- Targeted unit: 23 tests, 60 assertions — green.
- Full unit: 1835 tests, 10583 assertions — green.
- Real EntityManager integration: 2 tests, 6 assertions — green.
- Doctrine mapping validation (`--skip-sync`) — green.
- Scoped PHP CS Fixer (6 Stage files) — green.
- `git diff --check` — green.
- Full repository `composer cs:check` remains red on 572 pre-existing unrelated files; no Stage file is reported by the scoped check.

## Reviews

- Internal review: green after moving ancestor-cycle validation before inherited-flow inspection and defining explicit delete semantics.
- External Claude review cycle 1: BLOCKER confirmed — `orphanRemoval: true` could delete a detached/reparented row.
- Fix: removed orphan removal and added a persisted-detach real-DB regression test.
- External review cycle 2: IMPORTANT confirmed — removing orphan removal changed parent delete behavior.
- Fix: non-leaf deletion is rejected by the entity, controller displays the domain error, `PreRemove` enforces the invariant, and integration coverage verifies rows remain.
- External review final: `REVIEW_GREEN`; no unresolved BLOCKER or IMPORTANT findings.

## Scope

- No DB schema or migration changes.
- No transaction, Money, currency, sign, or report changes.
- No production action performed.
