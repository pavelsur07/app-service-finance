# Settlement UI — handoff

**Branch:** `docs/settlement-design-system-plan` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2907 · **Base:** master
**Product commit:** `d0cd76c41579d2a5f1db8855eb91b65ea1b1aca4` (following commit records final acceptance only).

13страниц взаиморасчётов приведены к текущим «Транзакциям ДДС»: legacy оболочка/вкладки, компактные фильтры,6отдельных остатков, таблицы/статусы/пагинация, карточки форм со сведениями и нижней панелью действий. Сохранены исходные деньги, источники, POST/idempotency и права. UI исправляет route-id в пагинации, видимость ошибок фильтра и выбор контрагента в длинном справочнике.

## Files

- `site/templates/settlement/*.html.twig` — существующие13шаблонов+5локальных композиций.
- `site/templates/form/counterparty_picker_theme.html.twig` — optional URL с прежним default.
- `site/assets/styles/pages/settlement.css`, `site/assets/pages/settlement.js`, `site/assets/legacy-app.js` — scoped presentation/import, без нового entry.
- `site/tests/Functional/Settlement/SettlementUiRefactorTest.php` —11UI regressions; Journey/Access — только selectors.
- `docs/tasks/settlement-design-system/` — spec/plan/checkpoint/3StageReports/acceptance/handoff.

## Verification

- PHPStan3861files,CS/strict3897files PASS; unit4547tests/25384assertions PASS.
- Full clean suite PASS:8496tests/47743assertions. Первый прогон имел1сбой из-за5синтетических browser movements в той же БД; Lifecycle1test11assertions затем PASS. Browser data изолированы в отдельной локальной БД, перед повтором подтверждено0movements. Финансовые тесты/продуктовый код для этого не менялись.
- Frontend lint/build/67Vitest tests PASS; Twig19files PASS.
- Browser:72cases (13screens×4+5states×4),reader10,no-JS2,6interactions+3final refinements PASS. Overflow/pageerror/brokenlogo0. Evidence/matrix/commands — [acceptance.md](acceptance.md).
- Product CI:Frontend Lint42343484219 SUCCESS; main workflow42343484341 SUCCESS. Event pull_request; production deploy не запускался. Финальный documentation-only commit запускает обычные required checks повторно; тот же product diff уже проверен.

## Reviews

Fresh final internal:3IMPORTANT+1MINOR исправлены; повторный осмотр0open. Stage internal:ещё2IMPORTANT исправлены. Total internalB0/I5,open0.
External Claude:1round,REVIEW_GREEN;B0/I0,3MINOR исправлены и проверены без повторного раунда по AGENTS§7.2. Open0. Full report artifacts ignored under site/var/external-review/3c2cabc3/.

## Risk / delivery

Нет миграций, новых API/зависимостей/ролей/финансовых формул/React/CI/CD изменений. Product PHP untouched. Rollback — revert UI; data rollback не нужен.
Глобальная mobile navigation наследуется от DDS; её переработка вне scope. Production untouched. Реализация завершена, незавершённых product work items нет; ожидается решение владельца о release.

Ready: PR #2907 "feat: align settlement UI with cash transactions" — merge into master with automatic production deploy?
Reply: "merge and deploy #2907"
