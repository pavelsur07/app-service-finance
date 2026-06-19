### Stage 3: FIX-03 System Counterparties — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required before deployment/migration

#### What was done
- Added global `SystemCounterparty` entity, repository, resolver, and not-found exception.
- Replaced tenant-scoped ingestion counterparty creation in `NormalizeRawRecordAction`.
- Removed old ingestion `Counterparty` entity and repository.
- Added migration creating/seeding `system_counterparties` and dropping `ingest_counterparties` only when empty.

#### Files changed
- `site/src/Ingestion/Entity/SystemCounterparty.php` — new
- `site/src/Ingestion/Repository/SystemCounterpartyRepository.php` — new
- `site/src/Ingestion/Application/Service/SystemCounterpartyResolver.php` — new
- `site/src/Ingestion/Application/Action/NormalizeRawRecordAction.php` — modified
- `site/src/Ingestion/Entity/Counterparty.php` — deleted
- `site/src/Ingestion/Repository/CounterpartyRepository.php` — deleted
- `site/migrations/Version20260619110000.php` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated

#### Checks
- `rg "App\\\\Ingestion\\\\.*Counterparty|ingest_counterparties" site/src site/tests ARCHITECTURE.md || true` — OK
- focused integration — OK
- `git diff --check` — OK

#### Risks / reviewer focus
- Before applying migration outside test, verify `SELECT COUNT(*) FROM ingest_counterparties`; migration aborts if count is greater than zero.

#### Open questions
- none
