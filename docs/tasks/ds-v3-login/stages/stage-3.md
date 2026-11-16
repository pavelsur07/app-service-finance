# Stage 3 — финальная приёмка

Risk MEDIUM; base `c716a8d1e`. Work items3.1–3.3 DONE.

Добавлены browser regression tests account/admin: 1440/390 геометрия, локальный
логотип, footer, cookie expiry/event, contrast/focus, client/401, genuine expiry,
account logout из chooser со старым reason. DOM дополнили другими state.error
и guest-spoofed expiry. Скриншоты/сверка — visual-acceptance.md.

Полные frontend gates прошли (перед review fix account163/admin155); PHPStan,
cs-check, strict-types и unit4928/27251 прошли. Full preview account29/admin25 +
DS48 прошёл, 10 conditional skips. После review fix module account165/admin157
и оба typecheck GREEN; rebuild/lint GREEN; final focused browser account12/admin6
GREEN. После Avatar tint minor: module account166/admin158, rebuild/lint оба GREEN,
company browser4/4 GREEN.

Internal fresh session: B0/I1, MINOR2. IMPORTANT: URL reason без реального expiry
provenance → ошибочный info для guest/после logout. Fix AND state.expired + query,
RED→GREEN regression, отдельный browser сценарий. MINOR: field errors role=alert,
AuthLayoutProps interface. Дополнительно self-review visual MINOR: source shadow,
cookie spacing/up-shadow, joined company rows/tint avatars; RED style checks перед
правками. Fresh internal re-review REVIEW_GREEN, открытых B/I/MINOR нет.
External final: отдельный Codex CLI, read-only static packet, round1 REVIEW_GREEN,
B0/I0; Claude CLI отсутствует. Stage1: Codex round1 B0/I1 fixed without re-run.
После external green визуальный MINOR Avatar tint не применялся из-за precedence
utility layer: расширен существующий Avatar tone=solid|tint с SSR RED→GREEN и
actual-color E2E, default сохранён. Fresh internal снова REVIEW_GREEN; повтор
external ради MINOR не выполнялся (§7.2). Reviewer сам тесты не запускал.
