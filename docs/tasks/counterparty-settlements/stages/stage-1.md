### Stage 1: ядро взаиморасчётов — DONE

Risk: HIGH-LOCAL. Stage base: 4fdad15c974830d13ff9342d1f40a526f3b5bd33.

Реализованы схема и сущности, Money, ручные основания и начальные остатки,
проведение, корректировки, датированные отмены, повтор запросов, company scope,
актуальные права и финансовая граница. Порядок истории — дата и номер события,
выданный под блокировкой книги компании.

Checks:
- Settlement tests PASS 27 / 103.
- Focused PHPStan PASS.
- Doctrine mapping PASS; only existing fixture test_money_holder differs from migrated schema.
- Fresh local DB all307 migrations PASS; schema Money/company FK test PASS 1/4.
- Internal review: 4 unique IMPORTANT found (reversed-item correction, stale closure, event ordering, acceptance gaps), all fixed. Fresh rereview: B0/I0 remaining.
- External review: 1 round, IMPORTANT 1 (dependency reversal coverage), MINOR 3; all fixed and verified. Result: fixed without re-run (no BLOCKER).
- Added active dependency rejection, later dependency reversal rejection, successful same-day reversal to zero; exact rejection messages and foreign counterparty case.
- Removed unused book version increment per external MINOR.

Следующее: Stage2 автоматически; handoff gates ещё не запускались.
