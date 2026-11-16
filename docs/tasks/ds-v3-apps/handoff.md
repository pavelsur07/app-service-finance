# ds-v3-apps — Stage 2, approval gate

**Branch:** `feat/ds-v3-stage-2`, base `fbfcc71c8`.
**Статус:** Draft, локальная реализация и ревью завершены; требуется отдельное
согласование production Docker/CI patch (AGENTS.md §3.3).

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

Следующий обязательный шаг требует named approval:
[stage-2-production.patch](proposals/stage-2-production.patch).
Он меняет только Docker frontend build context, SPA filter/install/cache,
локальный front-install и release-contract test. Patch пока не применён.
Без него штатные production build/CI не готовы; Stage 2 не переводится Ready.
После применения и фактических зелёных checks — обычный merge+deploy запрос.

Отдельное поручение по правилам: PR #3028 Ready, commit c043a73dc —
автоматический separate Codex fallback при недоступном Claude CLI.
Этот docs PR ещё не merged, decision merge+deploy #3028 ожидается.

Риски: DS audit4moderate/2low build-only (HIGH/CRITICAL0), старые apps package
versions не менялись; эталон пока PR #3022, непокрытые листами auth/loading/
error/404 — минимальные композиции с TODO(ds). Stage 3–5 ещё не начаты.
