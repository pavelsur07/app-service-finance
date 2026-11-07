## Current checkpoint

**Phase:** Stage 2 / PR A (2.0 + 2.1a) / handoff
**Status:** Stage 1 на проде (PR #2909, 07.11 07:35Z, GlitchTip чист); PR A этапа 2 готов к handoff
**Stage base commit:** master 3c7bb858c · ветка `refactor/twig-ui-stage-2`

### Completed
- Stage 1 — см. `stages/stage-1.md`; на проде 07.11.
- 1.0 — список рендерящихся шаблонов без функционального теста снят (41, эвристика по
  каталогу шаблона в `tests/Functional`), ниже. Для Stage 1 страховка — не тесты, а
  доказательство: `git diff -w` по шаблонам пуст, кроме 12 файлов с переносом
  строк и удалением двух неиспользуемых `import` (список в stage-1.md).
- 1.1 — `docs/workflow/twig-conventions.md`; ссылки из `AGENTS.md`, `CLAUDE.md`,
  `CLAUDE.frontend.md`; раздел «Twig» в `docs/maintenance/quality-gates.md`.
- 1.2 — `site/tests/Twigcs/Ruleset.php` (Official без LowerCaseVariable), конфиг
  `site/.twigcs.php` (переименован из `.twig-cs-fixer.php`), порог `error`.
- 1.3 — 1636 нарушений пробелов исправлены скриптом по отчёту twigcs (байтовые колонки),
  20 многострочных выражений в 10 файлах сведены в одну строку (twigcs не допускает
  перенос вокруг операторов).
- 1.4 — удалены 2 реально неиспользуемых `import` макросов (`balance/target`,
  `marketplace/ozon_reconciliation/operations`); остальные 72+4 предупреждения ложные
  (переменные layout'а, `i` в циклах, import внутри блоков) — скрыты порогом.
- 1.5 — `make site-cs-twig`; workflow `.github/workflows/twig-lint.yml` вне `deploy.yml`.

### Checks and baseline
- baseline: `composer cs:twig` — 2286 violations; `lint:twig` — OK 319 файлов
- `composer cs:twig` → `No violation found`, exit 0
- `bin/console lint:twig templates` → OK
- `make site-cs-check` → 0 of 3897; `make site-cs-strict-types` → 0
- `make site-stan` — OK; `make site-test` — OK (8486 tests)

### Review status
- internal: iteration 1, open: none
- external: round 1, result: REVIEW_GREEN

### Stage 2 — PR A (2.0 + 2.1a), детали в `stages/stage-2.md`
- 9 форм `onsubmit=confirm` → `_confirm_modal`; `data-confirm-fields` в `confirm_modal.js`;
  `WebTestCaseBase::submitConfirmTrigger()`; smoke-тесты loan/schedule и
  cash/file_import_profiles; e2e `tests/e2e/confirm-modal.e2e.cjs`.
- 2.1 разделён: 2.1a (этот PR), 2.1b — мелкие `<script>` и `onchange=submit` (PR B вместе с 2.2).

### Exact next action
- handoff PR A («merge and deploy»); после деплоя — GlitchTip 30 мин по страницам PR A;
  затем PR B: 2.1b (`data-autosubmit`, мелкие `<script>` ≤50 строк) + 2.2 (Twig внутри JS)

### Files to inspect first on resume
- `docs/tasks/twig-ui-systematization/plan.md`, `stages/stage-2.md`
- `site/assets/confirm_modal.js`, `site/templates/partials/_confirm_modal.html.twig`
- `site/.twigcs.php`, `site/tests/Twigcs/Ruleset.php`, `.github/workflows/twig-lint.yml`

### Шаблоны без функционального теста (1.0, 41)
`@BankSync/account/index`, `@BankSync/connection/{edit,index,new}`,
`_partials/money_totals_widget`, `admin/dashboard/index`, `admin/security/login`,
`admin/service_gateway/coverage`, `api/settings/{check,created,error,form,index,permissions}`,
`billing/admin/company`, `billing/client/tariff`, `cash/file_import_profiles/{edit,index,new}`,
`company/company_member/{index,invite_show}`, `deals/charge_types/{edit,index,new}`,
`import_log/index`, `loan/{form,index,report,schedule,schedule_upload}`,
`marketplace/admin/mapping_error_list`, `marketplace/unmapped/index`,
`marketplace_analytics/cost_mappings/index`, `marketplace_analytics/stock_turnover/index`,
`profile/money_account/{edit,index,new}`, `telegram/admin/bot/{edit,index,new}`,
`telegram/integration/index`. Smoke-тест дописывается тем этапом, который трогает страницу.
Уточнение 07.11 (Stage 2 PR A): `@BankSync/*` тесты имели (`tests/Functional/BankSync`);
`loan/schedule` и `cash/file_import_profiles/index` закрыты smoke-тестами PR A.
