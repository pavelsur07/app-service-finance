## Current checkpoint

**Phase:** Stage 2 закрывающий PR #2923 на handoff → Stage 3 PR A на handoff
**Status:** Stage 1 (#2909) и Stage 2 PR A–I (#2910–#2921) на проде 07.11; закрывающий PR Stage 2 на handoff
**Stage base commit:** master 4ac412c42 · ветки `refactor/twig-ui-stage-2j` (#2923) → `refactor/twig-ui-stage-3a` (PR A)

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
- PR A — на проде. PR B (2.1b): `data-autosubmit` (`assets/autosubmit.js`), `data-busy-submit`
  (`assets/busy_submit.js`, туда же блок moysklad), `assets/pages/cash_transfer.js`; минус 5
  `<script>` и 9 `onchange`; e2e `tests/e2e/legacy-forms.e2e.cjs`.
- PR B — на проде. PR C (2.2): пять модулей (`tax_profile_fields`, `pages/money_account`,
  `pages/pl_category_edit`, `pages/product_import_status`, `pages/report_filters`), партиал
  `_filter_controls_script` удалён, попутно создан отсутствовавший `_import_result`;
  e2e `tests/e2e/page-modules.e2e.cjs`.
- PR C — на проде. PR D (2.2, часть 2): `pages/month_close`, `pages/marketplace_index`,
  `pages/cost_mappings`; `confirm:submit` в `confirm_modal.js`, `bootstrap_modal.js`,
  `apiFetch` с DELETE/204/легаси-ошибками; e2e `tests/e2e/marketplace-modules.e2e.cjs`.
- PR D — на проде 07.11 09:58Z. PR E (2.3, часть 1): `pages/cost_pl_mapping`, `pages/default_sale_mapping`,
  `assets/dom.js`; e2e `tests/e2e/mapping-modals.e2e.cjs`.
- PR E — на проде 07.11 10:41Z (#2916). PR F (#2917, 2.3, часть 2): `pages/marketplace_ads` (адреса с id —
  `path()` с плейсхолдером-UUID), e2e `tests/e2e/marketplace-ads.e2e.cjs` — на проде 07.11 17:17Z.
- PR G (2.3, часть 3): `pages/listings_index`, `pages/listing_tags`, `assets/url_template.js`;
  подтверждения через `_confirm_modal` + `confirm:submit`; e2e `tests/e2e/listings.e2e.cjs`.
- PR G — на проде 07.11 17:39Z (#2918). PR H (2.3, часть 4): `pages/cash_transactions` (поповеры фильтров,
  массовое удаление, автоправило, «Создать на основании»); e2e `tests/e2e/cash-transactions.e2e.cjs`.
- PR H — на проде 07.11 19:07Z (#2920). PR I (2.3, часть 5): `pages/pnl_document`, партиал `document/_page_scripts`
  удалён; e2e `tests/e2e/pnl-document.e2e.cjs` — на проде 07.11 19:24Z (#2921).
- Закрывающий PR: удалены неподключённые `dashboard/_demo_chart`, `_money_balance_chart`; Stage 2 DONE;
  план Stage 3 — `stages/stage-3.md`. Flaky `SettlementSourceLockOrderTest` — фикс таймаута PR #2922,
  на проде 07.11 19:44Z. Закрывающий PR #2923 — на handoff.
- Stage 3 PR A (3.0 + 3.1): один маппинг flash на три shell'а, `app/_ui/_flash` для кита, 9 inline-копий
  удалены, дефект `invite_token` закрыт; ветка `refactor/twig-ui-stage-3a` от 2j — после мержа #2923 дифф только PR A.

### Exact next action
- handoff #2923 и Stage 3 PR A («merge and deploy»); затем PR B (3.2, `partials/_empty`, 17 замен) с ветки от PR A

### Files to inspect first on resume
- `docs/tasks/twig-ui-systematization/plan.md`, `stages/stage-2.md`
- `site/assets/legacy-app.js` (список init), `site/assets/pages/*.js`
- `site/.twigcs.php`, `site/tests/Twigcs/Ruleset.php`, `.github/workflows/twig-lint.yml`

### Шаблоны без функционального теста (1.0, 41)
`@BankSync/account/index`, `@BankSync/connection/{edit,index,new}`,
`_partials/money_totals_widget`, `admin/dashboard/index`, `admin/security/login`,
`admin/service_gateway/coverage`, `api/settings/{check,created,error,form,index,permissions}`,
`billing/admin/company`, `billing/client/tariff`, `cash/file_import_profiles/{edit,index,new}`,
`company/company_member/{index,invite_show}`, `deals/charge_types/{edit,index,new}`,
`import_log/index`, `loan/{form,index,report,schedule,schedule_upload}`,
`marketplace/admin/mapping_error_list`, `marketplace/unmapped/index`,
`marketplace_analytics/cost_mappings/index` (smoke-тест — PR D), `marketplace_analytics/stock_turnover/index`,
`profile/money_account/{edit,index,new}`, `telegram/admin/bot/{edit,index,new}`,
`telegram/integration/index`. Smoke-тест дописывается тем этапом, который трогает страницу.
Уточнение 07.11 (Stage 2 PR A): `@BankSync/*` тесты имели (`tests/Functional/BankSync`);
`loan/schedule` и `cash/file_import_profiles/index` закрыты smoke-тестами PR A.
