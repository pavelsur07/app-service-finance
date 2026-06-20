### Stage 3: Ingestion incremental cron — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done
- Added the daily cron entry for `app:ingestion:run-incremental` at 03:00.

#### Files changed
- `docker/cron/app.cron` — modified
- `docs/tasks/TASK-FIX-05/stages/stage-3-cron.md` — new

#### Self-review
- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated or N/A

#### Checks
- `git diff --check` — passed
- `docker compose run --rm site-php-cli php bin/console cache:clear --env=prod` — passed, with existing deprecation notices
- `docker compose run --rm site-php-cli php bin/console list app:ingestion --env=prod | rg 'run-incremental'` — passed
- `docker compose run --rm site-php-cli php bin/console app:ingestion:run-incremental --help --env=prod` — passed

#### Risks / reviewer focus
- This is a production scheduler change. Confirm the 03:00 runtime is still the desired window relative to legacy sync jobs.

#### Open questions
- none
