### Stage 2: JS из шаблонов в `assets/` — IN PROGRESS (PR A из трёх)

**Risk:** MEDIUM (поведение страниц; финансовые формы — в PR C)
**Stage base commit:** `3c7bb858c` (master после Stage 1)
**Work items:** 2.0–2.3; PR A = 2.0 (для своих страниц) + 2.1a

Правило плана «один PR — не больше ~5 страниц» делит 2.1 на две части:
2.1a — формы с `onsubmit="return confirm()"` (9 шаблонов, один механический
паттерн, один общий диалог), 2.1b — мелкие `<script>` до 50 строк и
`onchange="this.form.submit()"` (общий `data-autosubmit`). 2.2 и 2.3 — как в плане.

#### PR A (2.0 + 2.1a): `confirm()` → `partials/_confirm_modal`

- 9 форм `onsubmit="return confirm('…')"` заменены кнопкой-триггером
  `data-confirm-*` и одним `{% include 'partials/_confirm_modal.html.twig' %}`
  на страницу: `company/_taxes_card` (диалог в `company/edit`), `company/role/index`,
  `bank_sync/connection/index`, `bank_sync/account/index`, `project_direction/index`,
  `financial_responsibility_center/index`, `loan/schedule`, `cash/transfer/show`,
  `cash/file_import_profiles/index`. Заодно ушли два `style="display:inline;"`.
  Текст диалога: заголовок — прежний вопрос из `confirm()`, пояснение после «?» —
  в `data-confirm-body`, подпись кнопки — глагол вопроса, вариант `danger`.
- `assets/confirm_modal.js`: новый атрибут `data-confirm-fields` (JSON-объект) —
  скрытые поля формы помимо `_token`; нужен ЦФО (`version` оптимистической
  блокировки). При каждом открытии прежние поля снимаются. Описан в шапке партиала.
- Тесты: `WebTestCaseBase::submitConfirmTrigger()` — POST по `data-confirm-action`
  с токеном и полями, как делает JS; пять тестов, отправлявших старую форму через
  crawler, переведены на него (`BankConnectionPagesTest`, `BankAccountsPageTest`,
  `FinancialResponsibilityCenterControllerTest` — проверяет `{"version":3}`,
  `CashTransferControllerTest`, `CompanyTaxProfileTest`). 2.0: новые smoke-тесты
  `Loan/LoanSchedulePageTest` (200 + триггер + чужой займ → 404) и
  `Cash/Controller/Import/CashFileImportProfilePageTest` (200 + триггер + чужой
  профиль не виден); в `ProjectDirectionControllerTest` — проверка триггера и диалога.
  Страницы `@BankSync/*` из списка 1.0 тесты уже имели (`tests/Functional/BankSync`,
  эвристика по каталогу их не увидела).
- Браузерная проверка JS: `tests/e2e/confirm-modal.e2e.cjs` + `confirm-modal.html`
  (puppeteer в контейнере, как `dds-tree-picker.e2e.cjs`; Bootstrap не нужен —
  тест сам шлёт `show.bs.modal` с `relatedTarget`): поля подставляются, при
  повторном открытии снимаются, пояснение — только текст, blocked прячет кнопку,
  триггер без `data-confirm-action` не открывает диалог.

Оставшиеся вхождения `confirm(`/`onclick=`/`onsubmit=` — внутри `<script>`
крупных страниц (`marketplace/*`, `document/*`, `marketplace_analytics/cost_mappings`)
и `admin/dashboard` (свой `<dialog>` админки) — уходят в PR B/C вместе со скриптами.

#### Files changed (PR A)
- `site/templates/**` — 9 страниц + `company/edit` (include) + `partials/_confirm_modal` (doc)
- `site/assets/confirm_modal.js` — `data-confirm-fields`
- `site/tests/Support/Kernel/WebTestCaseBase.php` — `submitConfirmTrigger()`
- `site/tests/Functional/{BankSync,Company,Cash}/…` — 6 правок, 2 новых теста
- `site/tests/e2e/confirm-modal.{html,e2e.cjs}` — new
- `docs/tasks/twig-ui-systematization/{plan,checkpoint}.md`, этот отчёт

#### Definition of Done (PR A)
- [x] 0 `onsubmit="return confirm"` вне `<script>`; каждая страница — один диалог
- [x] CSRF-токен и `version` доходят до контроллера (функциональные тесты через триггер)
- [x] `npm run lint` и `npm run build` зелёные; entry в `vite.config.js` не менялись
- [x] Страницы этапа без теста получили smoke (loan/schedule, cash/file_import_profiles)
- [ ] «ручной smoke на стенде» — заменён e2e на puppeteer + функциональными тестами
      атрибутов; живой стенд не поднимался (см. Risks)

#### Checks (PR A)
- `make site-cs-twig` — twigcs `No violation found`, `lint:twig` OK (324 файла)
- `npm run lint` — 0; `npm run build` — ✓ built in 2.48s
- `make site-cs-check` — после `site-cs-fix` 0 (1 файл: `WebTestCaseBase`)
- `make site-stan` — `[OK] No errors`
- функциональные тесты 9 файлов — OK (47 тестов)
- `tests/e2e/confirm-modal.e2e.cjs` — 5 passed, 0 failed
- `make site-test` — OK (8500 tests, 47775 assertions), 7 мин 16 с

#### Internal review
- iteration 1: MINOR — генератор взял подпись кнопки из «Удалить?» вместе со знаком
  вопроса (`project_direction`); исправлено на «Удалить направление?» / «Удалить».

#### External review
- required: yes (handoff PR этапа)
- rounds: 1 (codex, effort medium, `site/var/external-review/3c7bb858/review.txt`):
  единственная находка — IMPORTANT про посторонний untracked-файл Владельца
  `site/bin/capture-wb-inventory.sh`, который скрипт ревью подмешал в diff; к PR он не
  относится и в коммит не входит (коммит по pathspec). По коду PR замечаний нет.

#### Risks / reviewer focus (PR A)
- Поведение «без JS»: раньше форма отправлялась и без подтверждения, теперь кнопка
  без JS ничего не делает (как у всех `_confirm_modal` в проекте).
- `data-confirm-fields` — `|json_encode` без `e('html_attr')`: автоэкранирование
  Twig в атрибуте достаточно (образец — `data-confirm-links` в `pl_category`).
- Единственная страница со сложным текстом — `company/role/index`: имя роли попадает
  в `data-confirm-title` через автоэкранирование, прежний JS конкатенировал строку.

#### Next
- PR A → handoff «merge and deploy»; затем PR B (2.1b + 2.2), PR C (2.3)
