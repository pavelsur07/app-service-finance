# ds-v3-apps — частичная передача, Stage 1

**Branch:** `feat/ds-v3-stage-1`.
**Статус:** Draft, не готов к merge. Внешний Claude reviewer недоступен.

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
BLOCKER/IMPORTANT 0/0. External: 0 раундов, failed preflight exit 2 —
`ревьюер 'claude' не установлен`. Не является зелёным review.

Для продолжения нужен доступный и авторизованный Claude Code executable.
Владелец не должен сам проводить review. После восстановления окружения агент
выполнит его, затем переведёт PR в Ready и запросит merge+deploy.
Сейчас решение merge+deploy не запрашивается.

Риски: source docs пока в PR #3022; транзитивные builder dependencies имеют
4 moderate + 2 low audit findings (HIGH/CRITICAL 0). Stage 2 отдельно требует
одобрения конкретного diff production Docker/CI (§3.3), включая apps/packages
в build context и change filter; на Stage 1 эти файлы не менялись.
