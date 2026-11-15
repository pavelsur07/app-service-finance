### Stage 3: handoff — external review blocked

**Risk:** LOW. **Stage base:** `f230a0670`. **Work items:** 3.1, 3.2.

**Implemented:** повторный аудит — 0 пользовательских наследников app-shell;
API, бюджет и Unit через base → legacy. Admin/apps и общие ресурсы админки
отсутствуют в diff. Новых migrations/dependencies/CI/production изменений нет.

**Full gates:**
- make site-stan — no errors, 4274 files.
- make site-cs-check — 0/4310 files to fix.
- make site-cs-strict-types — 0/4310 files to fix.
- make site-test-unit — 4822 tests / 26764 assertions, OK.
- make site-cs-twig — no violations, 399 templates valid.
- make site-test — initial preparation blocked by vendor write permission.
  With preinstalled locked deps, existing test DB lacks seed tariffs; UID 1000
  cannot write existing root-owned var/storage. Fresh DB diagnosis reproduced
  StorageService write failure in CashFileImportHandlerFlowTest (1 test,
  1 error). Affected review fixes after fresh DB migrations: 17 tests /
  207 assertions, OK. Corrected full run on isolated DB as local container
  user 0: **OK, 9271 tests / 52215 assertions, 07:45.022, exit 0**.

**CI source commit 6f380a39e:** PHPStan, style, unit, all 3 functional shards,
integration (5m6s) — pass. SPA real-auth job — pass (7m47s). Все checks pass/skipped.
First source push exposed outdated API-script/billing assertions and removed
flash-shell reference; fixed in 6f380a39e and verified in CI.

**Independent internal review:** fresh subagent, spec + full diff + checklist,
no implementation history. BLOCKER 0 / IMPORTANT 1 fixed (FlashShellsTest);
delta review confirmed fix and later validation fixes. Stage 2 self-review
had IMPORTANT 1 fixed (scope labels). Aggregate review metrics: B0/I2 fixed;
open B0/I0. No additional external reviewer was delegated.

**External review:** 1 command attempt, 0 completed rounds. Command:
`site/bin/external-review.sh 0658c9fc6a3c4c3519f6e254d6669200b6a38753 --effort medium --context docs/tasks/customer-ui-legacy/review-context.md`
— exit 2, `ревьюер 'claude' не установлен`. Standard binary paths were checked;
no existing local Claude authorization was available. There is no corrected
retry without provisioning the missing tool/auth; identical failed command
was not repeated. REVIEW_GREEN was not claimed.

**Status:** Stage not closed; PR #3021 stays Draft until mandatory external
review is completed. No Ready/merge/deploy. Remaining environment blocker
falls under AGENTS.md §3.4 item 5.
