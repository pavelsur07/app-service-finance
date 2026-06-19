### Stage 2: FIX-02 Stale Pending Normalization Recovery — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required before deployment/migration

#### What was done
- Added repository scan for stale `PENDING` raw records.
- Added `app:ingestion:normalize-pending` to re-dispatch `NormalizeRawRecordMessage`.
- Added 10-minute cron entry.
- Added migration index on `(normalization_status, fetched_at)`.

#### Files changed
- `site/src/Ingestion/Repository/IngestRawRecordRepository.php` — modified
- `site/src/Ingestion/Command/NormalizePendingRawRecordsCommand.php` — new
- `site/migrations/Version20260619100000.php` — new
- `docker/cron/app.cron` — modified

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated

#### Checks
- `lint:container --env=test` — OK
- `doctrine:schema:validate --skip-sync --env=test` — OK
- focused integration — OK
- `git diff --check` — OK

#### Risks / reviewer focus
- Cron service is not present in the current local compose file, so local supercronic syntax check could not be run.

#### Open questions
- none
