# Stage 3 — разделение файлового импорта

**Status:** DONE. **Risk:** HIGH-LOCAL.
**Stage base:** `e4bcf1b05`.

## Internal review
Свежая независимая сессия review_file_extraction: compliance/quality PASS; BLOCKER 0 / IMPORTANT 0 / MINOR 1. Устаревшая ссылка на planExistingMatches в комментарии заменена CashFileDuplicatePlanner::plan. Поведение CSV, дедупликации, approved plans, callbacks, транзакций, cleanup и scope сопоставлено с базой. Protected diff независимо проверен чистым.

Lifecycle tests проверяют lifetime/cleanup временного файла; вызов close reader непосредственно не наблюдают. Сохранённый finally подтверждён сравнением кода; новый интерфейс ради мокирования OpenSpout не вводился.

## External review
Раунд 1: site/bin/external-review.sh e4bcf1b05 --effort medium --context /tmp/cash-stage3-external-context.md. Результат REVIEW_GREEN, 0 BLOCKER/IMPORTANT, 4 MINOR исправлены без повторного внешнего запуска (политика §7.2). Восстановлены docblocks, plan зависит прямо от NormalizedRows, hash test вызывает публичный метод, storage tests вынесены в CashFileStorageTest. После исправлений B2 254/986 PASS, focused6 PHPStan/CS/lint PASS. Baseline3260→3258. Parent scoped review fixes: PASS. Лог /tmp/cash-stage3-external-run.log; оригинал review.txt в site/var/external-review/e4bcf1b0/.

## Implementation evidence
# Stage 3 implementation report

Stage base: e4bcf1b052574a6feb17a2368a47f450058c9554.
Commits: 1e9ae4694 characterization; 844321847 mechanical moves. Extraction intentionally uncommitted for parent review/report/commit. No push, PR update, external review, or full gates performed here.

## Implementation

Moved `CashFileImportService`, `CashFileRowNormalizer`, `CashFileDescriptionMatcher`, `CashFileDescriptionPlan`, `HeaderAutoMapper` from `Cash/Service/Import/File` to `Cash/Application/Service/Import/File`; moved `FileTabularReader` to `Cash/Infrastructure/Import/File`. Updated live PHP imports/static references/PHPDoc baseline references and existing tests only, without relocating test namespaces.

Extracted exact storage-key fallback/conversion/temp-file ownership into `CashFileStorage`; normalized iteration into `CashFileNormalizedRows`; dedupe hash/count/allocation into `CashFileDuplicatePlanner`. Coordinator retains writes, per-batch quota consumption, detach/cache ownership, callback commits and balance recovery. Public import/normalizedRows/planInput/recalculateCommittedRows signatures retained. Removed old storage wrappers after migrating all active callers. DescriptionPlan obtains conversion from storage; ApplyCashFileDescriptionsAction obtains job file access directly from storage. No DI aliases or new interfaces.

Explicit allowedRows travels through import/importRows/readAndPersist/iterate; no mutable allowedRows property survives, preventing leaked filter state. Snapshot hash verification still reads original stored bytes before XLS conversion. All lazy generators are consumed within owning storage callbacks. Existing CSV header/sample delimiter detector retained; import detector remains its independent 5-nonempty-line raw-count algorithm. Private bool selects the existing internal read mode, with no configurable parser strategy.

LOC after extraction: coordinator407 (was711); DuplicatePlanner114; NormalizedRows34; Storage67; FileTabularReader271; DescriptionPlan189; unchanged-logic DescriptionMatcher288/RowNormalizer296/HeaderAutoMapper81. Coordinator exceeds 250–300 guideline because atomic persistence, responsibility-pair assignment, counterparties, log accounting, batch callbacks and balance recovery deliberately remain together per task. Reader exceeds250 because preserving both legacy CSV dialect algorithms is required; parser convergence remains FOLLOW-UP. No artificial extra components introduced.

## Verification evidence

Before extraction on old code:
- 5 new characterization cases: `docker exec -w /app cash-refactor-cli php bin/phpunit -c phpunit.xml tests/Unit/Cash/Service/Import/File/CashFileReadingCharacterizationTest.php`: 5 tests/19 assertions green. CSV ;/,/TAB BOM/quotes/blank-header golden plus explicit CSV-mode divergence and quota golden. (Quota golden later migrated from private reflection into new public planner test.)
- B2 `--filter 'CashFile|AccountStrictMatchTest|CashCompanyIsolationTest'`: 247 tests/922 assertions green, including newly added 199/200 boundary data alongside201/401. Log `/tmp/cash-stage3-before.log`. The parallel narrow unit run included quota case added after B2 discovery, accounting for later one-test count difference.

After extraction:
- Container lint green: `docker exec -w /app cash-refactor-cli php bin/console lint:container`.
- B2 `--filter 'CashFile|FileTabularReader|AccountStrictMatchTest|CashCompanyIsolationTest'`: 248 tests/924 assertions green; log `/tmp/cash-stage3-after.log` (before final additional isolated tests).
- Final targeted file application/infrastructure/legacy-unit plus handler suite: 68 tests/268 assertions green. Command `docker exec -w /app cash-refactor-cli php bin/phpunit -c phpunit.xml tests/Unit/Cash/Application/Service/Import/File tests/Unit/Cash/Infrastructure/Import/File tests/Unit/Cash/Service/Import/File tests/Unit/Cash/MessageHandler/Import/CashFileImportHandlerTest.php`.
- B3 unchanged full import directory: 58 tests/342 assertions green; log `/tmp/cash-stage3-b3-import.log`.
- B3 shared `--filter 'AccountStrictMatchTest|CashCompanyIsolationTest|SettlementSourceMutationGuardTest'`:77 tests/199 assertions green; log `/tmp/cash-stage3-b3-shared.log`.
- Final focused PHPStan30 files with project config + reportUnmatchedIgnoredErrors:false: no errors; log `/tmp/cash-stage3-stan.log`; command `docker exec -w /app cash-refactor-cli sh -c 'xargs php vendor/bin/phpstan analyse -c /tmp/cash-stage3-stan.neon --memory-limit=1G --no-progress < /tmp/cash-stage3-paths.txt'`. Scoped override only suppresses unrelated unmatched baseline entries, not diagnostics.
- PHP CS Fixer focused30 files: final run Fixed0 of30; log `/tmp/cash-stage3-cs.log`. Full path list `/tmp/cash-stage3-paths.txt`. New strict_types declarations included.
- PHP syntax lint focused30: all green; log `/tmp/cash-stage3-lint.log`.
- `CASH_REFACTOR_BASE=1f874580f python3 /tmp/cash-protected.py`: Protected paths unchanged.
- `git diff --check`: green.

Baseline suppression total3260 before mechanical move,3260 after move,3259 after extraction: obsolete coordinator reader-generic suppression removed; the2 existing nullable-ID argument suppressions now reference extracted CashFileDuplicatePlanner::hash, same coordinator paths/counts. No suppression growth, rule weakening, casts or financial behavior changes.

Additional tests cover public quota plan in both numbered/blank input orders with fixed hash golden, normalized-purpose bytes, exactly1 repository call for1 distinct hash; explicit allowedRows filtering then empty and unfiltered reuse; XLSX first sheet/raw serial date/blank header/bool values; storage fallback order and cleanup after normal, early-generator-release, and exception consumers. Existing comprehensive description apply/retry/locks/audit/manual-description/1C interaction checks remain unchanged in expectations.

Test-writing corrections: initial new planner expected local date rather than existing UTC prior date; initial unstyled XLSX DateTime expectation was formatted rather than existing numeric serial; corrected expected fixtures to observed unchanged semantics, with no source behavior fix. A DateTime equality assertion used identity first and was changed to structural equality. Static first13 errors were new test annotations/resource assertions plus2 existing hash diagnostics after method extraction; final remaining annotation moved before attribute to be recognized. All corrected and rerun green. No product defect found or fixed.

Focused self-review: no BLOCKER/IMPORTANT identified. Parent owns fresh complete-stage internal/external review. No production/1C modifications. No full-gate claims.

## Exact Stage 3 changed tracked paths (including moves)

```text
M	site/phpstan-baseline.neon
M	site/src/Cash/Application/Import/File/ApplyCashFileDescriptionsAction.php
M	site/src/Cash/Application/Import/File/ConfirmCashFileImportAction.php
M	site/src/Cash/Application/Import/File/PrepareCashFileImportAction.php
M	site/src/Cash/Application/Import/File/UploadCashFileImportAction.php
R097	site/src/Cash/Service/Import/File/CashFileDescriptionMatcher.php	site/src/Cash/Application/Service/Import/File/CashFileDescriptionMatcher.php
R096	site/src/Cash/Service/Import/File/CashFileDescriptionPlan.php	site/src/Cash/Application/Service/Import/File/CashFileDescriptionPlan.php
R055	site/src/Cash/Service/Import/File/CashFileImportService.php	site/src/Cash/Application/Service/Import/File/CashFileImportService.php
R099	site/src/Cash/Service/Import/File/CashFileRowNormalizer.php	site/src/Cash/Application/Service/Import/File/CashFileRowNormalizer.php
R097	site/src/Cash/Service/Import/File/HeaderAutoMapper.php	site/src/Cash/Application/Service/Import/File/HeaderAutoMapper.php
M	site/src/Cash/Controller/Import/CashFileImportMappingController.php
M	site/src/Cash/Controller/Import/CashFileImportPreviewController.php
M	site/src/Cash/Controller/Import/CashFileImportResultController.php
R061	site/src/Cash/Service/Import/File/FileTabularReader.php	site/src/Cash/Infrastructure/Import/File/FileTabularReader.php
M	site/src/Cash/MessageHandler/Import/CashFileImportHandler.php
M	site/tests/Functional/Cash/Controller/CashFileImportTemplateControllerTest.php
M	site/tests/Functional/Cash/Controller/Import/CashFileDescriptionPreviewTest.php
M	site/tests/Integration/Cash/Service/Import/File/CashFileDescriptionApplyTest.php
M	site/tests/Integration/Cash/Service/Import/File/CashFileImportBatchBoundaryTest.php
M	site/tests/Integration/Cash/Service/Import/File/CashFileImportCounterpartyMatchingTest.php
M	site/tests/Integration/Cash/Service/Import/File/CashFileImportWorkerStorageTest.php
M	site/tests/Unit/Cash/MessageHandler/Import/CashFileImportHandlerTest.php
M	site/tests/Unit/Cash/Service/Import/File/CashFileDescriptionMatcherTest.php
M	site/tests/Unit/Cash/Service/Import/File/CashFileImportServiceTest.php
A	site/tests/Unit/Cash/Service/Import/File/CashFileReadingCharacterizationTest.php
M	site/tests/Unit/Cash/Service/Import/File/CashFileRowNormalizerTest.php
```

New untracked extraction/test paths (intentionally to be staged by parent):

```text
site/src/Cash/Application/Service/Import/File/CashFileDuplicatePlanner.php
site/src/Cash/Application/Service/Import/File/CashFileNormalizedRows.php
site/src/Cash/Infrastructure/Import/File/CashFileStorage.php
site/tests/Unit/Cash/Application/Service/Import/File/CashFileDuplicatePlannerTest.php
site/tests/Unit/Cash/Infrastructure/Import/File/FileTabularReaderTest.php
```

## External review MINOR fix cycle (final)

External Stage3 review: REVIEW_GREEN,0 BLOCKER/IMPORTANT and4 confirmed safe MINOR, all fixed without rerun as authorized:
1. Restored original Russian planExistingMatches/allocateExistingMatches docblocks verbatim onto extracted plan/allocate, including financial allocation explanation and account serialization precondition. Planner now129 LOC.
2. DescriptionPlan injects CashFileNormalizedRows directly and calls iterate; changing-normalizer integration test passes the SAME rows instance into coordinator and DescriptionPlan. Required public normalizedRows wrapper retained.
3. Existing hash-stability unit constructs the planner directly and calls public hash, retaining the Cyrillic input and existing golden coverage in CashFileDuplicatePlannerTest. Removed reflection/private-property access.
4. Moved storage lifetime/fallback cases to new `site/tests/Unit/Cash/Infrastructure/Import/File/CashFileStorageTest.php`; FileTabularReaderTest now covers its reader only.

Final affected verification:
- B2 exact planned filter:254 tests/986 assertions green, `/tmp/cash-stage3-review-fixes-b2.log`.
- Focused6 PHPStan no errors, `/tmp/cash-stage3-review-stan.log`.
- Narrow unmatched-baseline diagnostic identified1 removed pre-existing test suppression occurrence: createMock unresolved return count3→2 in CashFileImportServiceTest. Shrunk count only; baseline total now3258 (Stage3 start3260). Exact diagnostic `/tmp/cash-stage3-review-baseline.json`. Focused retest no errors `/tmp/cash-stage3-review-stan-shrink.log`.
- Focused CS dry-run6 files green, `/tmp/cash-stage3-review-cs-final.log` (initial safe fixes removed unused imports from split test classes).
- Focused syntax6 green, `/tmp/cash-stage3-review-lint.log`.
- Protected check and git diff --check green. No broad/full checks, external rerun, commit, push performed.

Final reviewfix source paths: CashFileDuplicatePlanner.php, CashFileDescriptionPlan.php, CashFileDescriptionApplyTest.php, CashFileImportServiceTest.php, FileTabularReaderTest.php, new CashFileStorageTest.php; baseline count shrink in site/phpstan-baseline.neon. The parent's separate stale-comment fix in coordinator is preserved. Source frozen for parent commit.
