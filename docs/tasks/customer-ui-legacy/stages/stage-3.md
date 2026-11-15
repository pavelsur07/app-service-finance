### Stage 3: handoff — DONE

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

**External review:** по явному поручению владельца вместо недоступного Claude
запущен Codex в отдельной ephemeral read-only сессии. Первый Codex запуск не
завершил проверку из-за `bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted`.
Исправленный повтор сохранил sandbox read-only: полный diff и 52 исходника/правила
переданы через stdin, без вызова сломанного shell. Ограничение: проверка по
переданному dossier, без самостоятельных команд и запуска тестов ревьюером.

Команда исправленного запуска:
`site/bin/external-review.sh 0658c9fc6a3c4c3519f6e254d6669200b6a38753 --reviewer codex --effort medium --context /tmp/customer-ui-codex-review-context.md --out site/var/external-review/0658c9fc-codex-stdin`

Результат: exit 0, точный маркер **REVIEW_GREEN**, diff 1187 строк.
Сессия `01a1e076-ec7d-7741-9f9f-560653ce0ea7`, sandbox read-only, effort medium.
Отчёт: `site/var/external-review/0658c9fc-codex-stdin/review.txt`.
Завершённых раундов: 1; Codex запусков: 2 (один исправленный повтор);
отдельно прежний Claude preflight не запустился. External B0/I0, открытых нет.

**Status:** Stage завершён. PR #3021 переводится в Ready; merge/deploy ожидают
единственного owner decision. Блокировки внешнего review больше нет.
