# ds-v3-apps — Stage 3 handoff

**Branch:** feat/ds-v3-stage-3
**PR:** https://github.com/pavelsur07/app-service-finance/pull/3034
**Implementation:** ef01dc75d; action26px3e5ebd0ca; CI runner dependency fix — final PR head.
**CI:** required latest-head checks tracked in PR; Draft until success.

Stage1 PR3027 и Stage2 PR3030 доставлены; Stage3 заменяет старый UI в apps
16 базовыми компонентами ДС. Дополнительно ErrorBoundary для полной изоляции
от old UI и ToastStack для фиксированного размещения уведомлений. Native
props/ref/validation/keyboard; auth/session/CSRF handlers не изменены.

Файлы: apps/packages/ds components/icons/styles/config/locks; apps/account
и apps/admin screens/manifests/vitest; test-only Chromium fixture account;
README/task docs. site/, production Docker/CI, backend и migrations untouched.
Публичного API или финансовых изменений нет.

Проверки: front-install/typecheck/lint/test/build PASS; apps82/78, package47;
Chromium12PASS; preview26PASS8skips+fixture11; actual nginx builds/smoke
30PASS4skips+fixture11 до последнего ToastStack, targeted12PASS после.
PHPStan/CS/strict-types PASS; unit4889tests/27145assertions.15before/after1440
с описанной provenance,4fresh baseline. Контраст текста4.5/borders3 и focus2.
Актуальный production build/smoke и real HTTP/HTTPS дополнительно в PR CI.
CI runner correction: test:components устанавливает linked DS из lock перед
fixture build. Clean-room empty DS node_modules: install/build/12browserPASS.
Test:e2e сохраняет CLI --config через отдельный posttest:e2e lifecycle;
actual auth suite --list выбирает2реальных теста, затем fixture12PASS.

Internal fresh: B/I0/0, MINOR2fixed (Toast tones); focused review fixes GREEN.
Финальная локальная MINOR нормализация custom InputGroup action до26px по листу
проверена RED36→GREEN26. External separate Codex1round: B/I0/1, IMPORTANT
Toast position fixed/regression verified, **fixed without re-run**, open0.
Static stdin-only (Claude отсутствует), без независимого запуска тестов.

Риски/отклонения: source error-border2.803:1 заменён существующим semantic
danger-text с TODO(ds); native Select и outline2px по owner brief.
Auth/loading/error/404 sheets ещё отсутствуют — минимальные композиции.
DS audit baseline4moderate2low (build-only), apps dependencies versions
не менялись. CSS23.19→32.01KB (полный базовый kit); JS~+2%, Manrope unchanged.

Stage4/5 не начаты. Миграций и ручных production mutations нет.
Подробности: stages/stage-3.md, reviews/codex-stage-3.md, checkpoint.md.

Ready: PR #3034 "feat(ds): Stage 3 — базовые компоненты v3.4 в apps" — merge into master with automatic production deploy?
Reply: "merge and deploy #3034"
