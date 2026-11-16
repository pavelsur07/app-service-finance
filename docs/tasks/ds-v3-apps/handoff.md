# ds-v3-apps — Stage 2 handoff

**Branch:** `feat/ds-v3-stage-2`, base `fbfcc71c8`.
**PR:** [#3030](https://github.com/pavelsur07/app-service-finance/pull/3030) — Ready, base master.
**Implementation commit:** `404c35c8b`.
**Статус:** Ready. Production Docker/CI patch одобрен и применён.
**CI:** [43814469343](https://github.com/pavelsur07/app-service-finance/actions/runs/43814469343)
SUCCESS на code head f82ec6d78; integration base87e6a7338.
Последующие изменения — только итоговая документация.

Stage 1: PR #3027 merged/deployed (`fbfcc71c8`), workflow 43804985365 SUCCESS,
account/admin healthy на новом SHA, HTTPS acceptance 19/19 PASS.

Stage 2: Tailwind4.3.5/Vite/preflight + тема v3.4, self-hosted Manrope/OFL,
перевод существующих экранов на CSS DS. site/ и auth/session logic не менялись.
Реализация: apps/packages/ds и apps/account/admin; документация apps/задачи.
Миграций, API/финансовых изменений, новых production mutations нет.

Проверки: front typecheck/lint/test/build PASS (48 account / 44 admin,13DS).
Preview E2E26 PASS/8skipped, local candidate nginx30 PASS/4skipped, оба images
собраны с candidate Dockerfile вне repo. После MINOR фикса повторены
compile tests/builds/nginx smoke, .visible из test fixtures отсутствует в CSS.
15 пар screenshots1440 до/после, focus/contrast/local font/light theme PASS.

Internal review0/0, MINOR1fixed; separate Codex1round REVIEW_GREEN0/0.
[Ревью](reviews/codex-stage-2.md) — статический stdin анализ полного текстового
diff, исходного эталона и production candidate; не независимый запуск тестов.
[Stage Report](stages/stage-2.md) содержит команды, результаты и ограничения.

Владелец одобрил [stage-2-production.patch](proposals/stage-2-production.patch)
2026-11-16. Пять actual файлов побайтно совпали с reviewed candidate.
Actual release contracts PASS; front-install APP=admin установил DS перед app;
свежие admin typecheck/tests44 PASS. Фактический CI полностью зелёный:
SPA checks, real-auth dev/preview/production HTTPS, images/browser smoke,
PHP gates/functional/integration/API types и empty-db migrations.
Stage2 Ready, merge+deploy #3030 ещё не разрешён.

Отдельное поручение по правилам: PR #3028 merged, commit87e6a7338;
workflow43813732309 SUCCESS, docs-only build/deploy skipped.
Автоматический отдельный Codex fallback теперь в master.
Post-check19/19 PASS, production account/admin healthy на текущем61798e4d5;
docs-only изменение не требовало rollout приложения.

Риски: DS audit4moderate/2low build-only (HIGH/CRITICAL0), старые apps package
versions не менялись; эталон пока PR #3022, непокрытые листами auth/loading/
error/404 — минимальные композиции с TODO(ds). Stage 3–5 ещё не начаты.

Решение Владельца:

Ready: PR #3030 "feat(ds): Stage 2 — Tailwind v4 и стили v3.4 в apps" —
merge into master with automatic production deploy?
Reply: "merge and deploy #3030"
