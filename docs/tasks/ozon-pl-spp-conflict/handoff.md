# Ozon СПП — handoff

Branch: fix/ozon-pl-spp-conflict; PR: https://github.com/pavelsur07/app-service-finance/pull/2827
Base: 1fc52299d; commits: cd73ff430 (backend), 8f44048dd (modal).

## Изменения
Один scoped query выявляет legacy Ozon sale_revenue/return_refund на стандартных
статьях с СПП либо в той же статье, что реализация. Report предупреждает без смены
финансовых формул; default preview добавляет warnings; apply блокируется на backend.
Modal показывает причину и сохраняет sign mismatch. Отдельное предупреждение о
месяцах с начислениями, но без обработанной реализации. XLSX читает тот же report.

## Проверки
Baseline: 33 integration/90 assertions; 14 unit/43 assertions.
Regression: 3 ожидаемых failure на старом коде; green после fix.
Module: 53 tests/240 assertions; functional UI: 23/170; focused PHPStan green.
Twig lint green; JS smoke conflict/normal/error green, textContent injection safe.
Frontend lint, build, API types green. Build initially EROFS in read-only node_modules;
исправлено локальным writable mount .vite-temp, владелец node_modules не изменён.
SQL smoke на временных таблицах: disable 2, repeat 0, rollback 2; guard rejection
не меняет legacy. Production scripts НЕ запускались.
Full suite `make site-test` (prepared local deps): PASS, 7591 tests/43288 assertions.
После review-fixes: PlPreview 37 tests/109 assertions; query 2/10; focused PHPStan green.
Full gates PASS: make site-stan (0 errors), site-cs-check (0/3431 fixes),
site-cs-strict-types (0/3431 fixes), site-test-unit (4248 tests/24515 assertions).
All commands use isolated compose project ozon-pl-spp-test; full test reuses installed
local dependencies and skips composer-install to preserve shared owner vendor.
Initial full PHPStan failed on the same test memoization issue as CI; failure fixed,
not baseline-suppressed. Focused CS after review fixes: 0/3 changes required.

## Ревью
Fresh internal review (review_ozon_spp), полный diff от базы: REVIEW_GREEN.
Metrics internal: BLOCKER 0 / IMPORTANT 0 / MINOR 0 / FOLLOW-UP 0.
External Claude Code round 1: REVIEW_GREEN; 0 BLOCKER / 0 IMPORTANT / 2 MINOR.
Оба MINOR исправлены: scope warning только null/OZON, distinct-month anti-join.
Marketplace filter regression proven red for Yandex before fix; module re-run recorded below.
Повторное fresh internal review этих изменений: REVIEW_GREEN, 0 findings.
External rerun не требуется (нет BLOCKER; §7.2).

## Production и оставшаяся работа
production-runbook.md + repair.sql/rollback.sql подготовлены, approval §3.3 требуется.
Отключаются только два подтверждённых legacy-правила одной компании/Ozon.
Read-only access codex-psql-ro не позволяет выполнить write: владелец запускает через
свой разрешённый канал, агент не расширяет доступ. До repair ошибка в prod остаётся.
September acceptance: 995659.13 − 77169.07 = 918490.06 вместо 3428236.06.
Исторический сентябрьский ОПиУ уже содержит stale REV_SPP_SALES 2632144.00;
его пересборка — отдельная одобряемая операция, не входит в repair.sql.
Миграций, новых зависимостей и CI/CD изменений нет.

## Owner decision
Ready: checks/review completed. Merge + automatic deploy — только после явного решения.
Отдельно согласовать исполнение repair.sql; исторический пересчёт отдельно.
