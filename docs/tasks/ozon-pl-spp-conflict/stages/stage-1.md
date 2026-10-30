### Stage 1: backend conflict detection — DONE
Risk HIGH-LOCAL; stage_base_commit 1fc52299d.
Work items 1.1/1.2: единая read-only company-scoped проверка; preview warnings;
auto setup warnings + blocking apply; missing realization months warning.
Files: OzonPlMappingWarningsQuery, PlPreviewSalesCoverageQuery, two preview actions,
preview DTO/controller, apply action/console, regression tests.
Checks: baseline 33 integration + 14 unit; regression red 3 failures, green 5/23;
module 53 tests / 240 assertions; focused PHPStan no errors; focused CS fixed 2 files.
CS first invocation failed because multiple paths require explicit config; corrected --config passed.
Internal review: 0 BLOCKER / 0 IMPORTANT; company scopes and unchanged financial SQL checked.
External: not required at Stage (<500 lines); required at handoff.
DoD: done. Next: Stage 2 automatically.
