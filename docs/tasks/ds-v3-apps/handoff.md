# ds-v3-apps — частичная передача, Stage 1

**Branch:** `feat/ds-v3-stage-1` · **PR:** [#3027](https://github.com/pavelsur07/app-service-finance/pull/3027) (base master).
**Implementation commit:** `c5233de52`.
**CI:** [reviewed head ccb24c23d](https://github.com/pavelsur07/app-service-finance/actions/runs/43751112986) — SUCCESS: оба SPA checks, real backend/HTTPS
isolation, production images account/admin.
**Статус:** Ready, ожидается решение merge+deploy Stage 1.

Phase 0 завершена: [plan.md](plan.md).
Stage 1 реализован и локально проверен: [stages/stage-1.md](stages/stage-1.md).
Stage 2–5 ожидают доставки Stage 1; общий scope задания не завершён.

Добавлен автономный apps/packages/ds, 361 токен, Style Dictionary 5.8,
CSS/TS exports и тесты. Проверки apps расширены на пакет; site/ и runtime apps
не менялись. Миграций и публичных API/финансовых формул нет.

Проверки: typecheck/lint/build PASS, Vitest account 43 и admin 39;
preview Playwright 22 PASS + 4 production-only skipped; пакетные 8 тестов,
build/check:generated/typecheck PASS. 15 пар снимков 1440 и артефакты обоих
apps побайтно совпали с baseline. Изменений production нет.

Независимый internal review: полный diff + focused dependency review,
BLOCKER/IMPORTANT 0/0. По прямому решению Владельца 2026-11-16 Claude заменён
отдельной Codex-сессией: REVIEW_GREEN, BLOCKER/IMPORTANT 0/0. Две сессии
(один corrected retry после bwrap); финальная проверка — stdin-анализ
полного diff, исходных CSS/JSON, задания и правил, без выполнения команд.
[Дословный результат](reviews/codex-stage-1.md). Исправлений кода нет.

Решение Владельца:

Ready: PR #3027 "feat(ds): Stage 1 — пакет и токены ДС v3.4 для apps" —
merge into master with automatic production deploy?
Reply: "merge and deploy #3027"

Риски: source docs пока в PR #3022; транзитивные builder dependencies имеют
4 moderate + 2 low audit findings (HIGH/CRITICAL 0). Stage 2 отдельно требует
одобрения конкретного diff production Docker/CI (§3.3), включая apps/packages
в build context и change filter; на Stage 1 эти файлы не менялись.
