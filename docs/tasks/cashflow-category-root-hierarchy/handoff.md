# Handoff: пользовательские корневые категории ДДС

## Outcome

- Обычные категории ДДС могут находиться в корне и переноситься туда через форму или MCP.
- При отделении от родителя текущий `flowKind` сохраняется, если пользователь не изменил его явно.
- Обычные категории нельзя помещать в техническую системную ветку, создавать циклы, пересекать компании или превышать глубину дерева.
- Системные `CF_*` категории защищены от перемещения и изменения типа; удаление категории с детьми запрещено явно.
- Мигратор поддерживает только каноническую системную структуру и больше не перемещает обычные корневые категории.
- Legacy root с `TECHNICAL` не меняются автоматически и отображаются только агрегированным предупреждением без UUID/PII.

## Delivery

- Task base: `30fd264964d3f2309ff180320d471b346244a9f6`.
- Branch: `agent/cashflow-category-root-hierarchy`.
- Commits: `50f50024`, `5e4d1804`, `58510bb7`.
- Draft PR: #2316.
- Schema, dependencies, CI/CD and production were not changed.

## Verification

- Full unit suite: 1835 tests, 10583 assertions — green.
- Category integration: 12 tests, 83 assertions — green.
- Final migrator target: 3 tests, 50 assertions — green.
- Relevant functional: 9 tests, 54 assertions — green.
- Doctrine mapping, Twig lint, scoped PHP CS Fixer, ESLint, Vite build and `git diff --check` — green.
- Full repository CS check remains red on 572 unrelated pre-existing files; all task PHP files pass the scoped check.
- Vite reports the existing optional `@symfony/ux-turbo` warning; the build succeeds.

## Reviews

- Each Stage passed internal review and an external read-only review ending in `REVIEW_GREEN`.
- The final integrated review of `30fd264964d3f2309ff180320d471b346244a9f6...HEAD` also ended in `REVIEW_GREEN`.
- No unresolved BLOCKER or IMPORTANT findings remain.

## Gates

- Current state: final Release Gate, Draft PR remains Draft.
- Next permitted decision: mark PR #2316 Ready for review.
- Merge, deploy, production migration, queue processing, write smoke and production data changes remain unauthorized.
