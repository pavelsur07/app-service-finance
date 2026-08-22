### Stage 1: Минимальный остаток компании — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously

#### Stage scope

- Stage base commit: `2520183b41244058644642461aa93ffeffb92737`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`

#### What was done

- `Company.minimumBalance` добавлен как неотрицательный embedded shared `Money`, default `0 RUB`.
- Добавлена обратимая PostgreSQL migration с backfill существующих компаний.
- Сумма и валюта редактируются в формах создания/редактирования компании.
- Ошибки формата, переполнения и отрицательной суммы показываются в форме.
- Account-level `minimumSafeBalance` не изменён.

#### Files changed

- `site/src/Company/Entity/Company.php` — новое Money-поле и invariant.
- `site/src/Shared/Form/Type/MoneyValueType.php` — compound Money transformer.
- `site/src/Company/Form/CompanyType.php` и `site/templates/company/*` — UI настройки.
- `site/migrations/Version20260822121000.php` — schema/backfill.
- `site/tests/{Unit,Integration,Functional}` — entity, form, mapping и behavior coverage.
- `ARCHITECTURE.md` и task docs — зафиксированные правила и checkpoint.

#### Definition of Done

- [x] Company хранит неотрицательный minimum balance как `Money`.
- [x] Existing rows получают `0 RUB`; migration обратима в local/test DB.
- [x] Поле редактируется в обеих Company forms с видимыми ошибками.
- [x] Embedded column names закреплены тестом metadata.
- [x] Account-level threshold не затронут.

#### Baseline

- `php bin/phpunit tests/Unit/Company/CompanyEntityTest.php` — 8 tests, 12 assertions, green.
- `npm run build` — green; сохранено существующее предупреждение Symfony UX Turbo.

#### Checks

- targeted: Company/Money unit + mapping + Company create flow — 183 tests, 611 assertions, green; 1 pre-existing deprecation.
- module: full `tests/Unit/Company` plus Money form test — green.
- migration: up/down/up in local test DB — green; task columns отсутствуют в schema update diff.
- lint: changed PHP CS Fixer, Twig lint, Doctrine mapping validate and `git diff --check` — green.
- unrelated global schema sync drift remains pre-existing and does not include task columns.

#### Internal automatic review

- Iterations: 3
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: overflow cause handling, visible compound-form errors, non-string input robustness, dead form label.
- FOLLOW-UP: generalize `MoneyValueType` only when a second consumer requiring negative values appears.

#### External Claude Code review

- Iterations: 3
- Result: REVIEW_GREEN
- Confirmed findings fixed: visible transformation errors; Company embedded column mapping guard; non-string input guard.
- Rejected findings with reason: `data_class=null` is required by Symfony here (removal broke all transformer tests); missing/invalid currency already maps to caught `InvalidArgumentException` or child desynchronization.

#### Review fixes applied

- Disabled error bubbling and added explicit user-facing invalid messages.
- Chained Money parse/overflow causes and covered overflow.
- Added Company mapping integration test and invalid form functional test.
- Guarded transformer input types before parsing.

#### Risks / reviewer focus

- No float arithmetic; database stores minor units and ISO currency.
- Migration defaults exist only for backfill and are dropped to match mapping.
- Threshold currency comparison and no-FX rule are enforced in Stage 2 provider tests.

#### Checkpoint

- `docs/tasks/ui-dashboard/checkpoint.md` updated.
- exact next action: commit/push Stage 1, create/update Draft PR, start Stage 2.

#### Open questions

- none

#### Expected owner response

- not required; continuing autonomously
