# Stage 3 — guarded writer / partial apply

Base `30a98e5a7`. Risk HIGH-LOCAL.
- Existing ChangeListingMappingAction остаётся единственным append writer; optional Guard, payload+guard replay, audit, recompute under catalog/source locks.
- Общий прежний session catalog lock извлечён и держится до commit, источник читается свежим DBAL DTO через новый Facade method. Runtime catalog writers проверены: SyncCatalogAction.
- Batch без внешнего transaction/flush; domain conflict оставляет EM открытым; technical failure сохраняет prior accepted, останавливает остаток, закрытый EM не сбрасывается.
- TDD RED12 (missing batch/Command, guard mismatch accepted) → writer regression45/194 PASS; module474/2006 PASS after review fixes (469/1988 before review).
- Реальные независимые PG процессы: actual SyncCatalogAction с local MockHttpClient, duplicate INSERT/archive оба порядка, source update оба порядка, identical POST:7/66 PASS.
- Mutation catalog guard removed → RED2; source FOR SHARE removed → RED1; production files restored; финальный модуль GREEN.
- Focused PHPStan/CS PASS. Initial fixture boolean binding and2 PHPStan shape/nullsafe findings fixed. Old no-debug container had cached5-arg Action; log proved ArgumentCountError; cache rebuilt, targeted1/5 then module GREEN.
- Internal complete Stage review: B/I0/0 open0/0; order/replay/commit boundary/phantoms/EM verified. External Claude1 round IMPORTANT3 confirmed/fixed without re-run, MINOR1 fixed; BLOCKER0. Fixes: manual snapshot removes inherited auto audit (deleted source CLEAR replay regression RED→GREEN); catalog busy throws existing temporary category so existing handler retries (real PG RED2→GREEN); source/target waits while catalog locked bounded2s; added explicit-size/tampered target/removed characteristic/foreign envelope/expiry/non-READY/Facade transaction+tenant+fresh DBAL tests. Targeted25/138 PASS. Technical log adds safe IDs and exception class, no raw exception/payload. Fixture explicitly pointed out (excluded from default diff).
- Next Stage4 legacy UI and final handoff gates/reviews. No production actions.
