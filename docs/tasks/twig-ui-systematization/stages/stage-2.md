### Stage 2: JS из шаблонов в `assets/` — IN PROGRESS (PR A, PR B на проде; PR C на handoff)

**Risk:** MEDIUM (поведение страниц; финансовые формы — в PR C)
**Stage base commit:** `3c7bb858c` (master после Stage 1)
**Work items:** 2.0–2.3; PR A = 2.0 (для своих страниц) + 2.1a; PR B = 2.1b; PR C = 2.2; PR D = 2.3

Правило плана «один PR — не больше ~5 страниц» делит 2.1 на две части:
2.1a — формы с `onsubmit="return confirm()"` (9 шаблонов, один механический
паттерн, один общий диалог), 2.1b — мелкие `<script>` и
`onchange="this.form.submit()"` (общий `data-autosubmit`). 2.2 и 2.3 — отдельными
PR C и PR D, а не вместе с 2.1b: каждый трогает другие страницы.

PR A: #2911, merge `2317ed9b`, деплой 07.11 08:19Z без даунтайма, GlitchTip чист,
контейнеры healthy. PR B: #2912, merge `92611b3e`, деплой 07.11 08:54Z без даунтайма, GlitchTip чист.

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

#### PR B (2.1b): мелкий inline-JS → общие модули легаси-бандла

- `onchange="this.form.submit()"` (9 мест в 8 шаблонах) → атрибут формы
  `data-autosubmit` + делегированный `assets/autosubmit.js` (select / checkbox /
  radio → `form.requestSubmit()`): `document/_list_layout`, `document/deleted_index`,
  `marketplace/index`, `marketplace/ozon_reconciliation/index`, `marketplace/pl_mappings`,
  `marketplace/returns`, `partials/_active_company_widget` (сайдбар каждой страницы),
  `budget/plan_fact` (год и версия). Скрипт «чекбокс архива» в `counterparty/index`
  удалён — его форма уже была `data-autosubmit`.
- Три одинаковых «спиннер на кнопке после submit» (`marketplace/sales`,
  `marketplace/returns`, `catalog/product/import`) → `data-busy-submit` +
  `data-busy-label`; обработчик — `assets/busy_submit.js`, куда без изменения
  поведения перенесён блок `data-moysklad-check` из `legacy-app.js` (общий селектор,
  подпись по умолчанию «Проверяем…», восстановление из bfcache).
- Подпись валюты счёта в «Новом переводе» → `assets/pages/cash_transfer.js`,
  `initCashTransfer()` в списке инициализаций `legacy-app.js`.
- Итог: минус 5 inline `<script>`, минус 9 `onchange`. Остались `onchange` в
  `marketplace/month_close/index` (3) и `marketplace/cost_pl_mapping/index` (1) —
  страницы с крупным скриптом, уходят в PR C/D целиком; `onchange="window.location…"`
  в `marketplace_analytics/cost_mappings` — там же.
- Тесты: `MarketplaceReturnsPageTest` (новый smoke: страница без теста), проверка
  `data-busy-submit` в `MarketplaceSalesControllerTest` и GET страницы импорта в
  `ProductImportUploadTest`; e2e `tests/e2e/legacy-forms.e2e.cjs` (puppeteer):
  autosubmit по select и чекбоксу, не по текстовому полю и не в чужой форме;
  busy-submit блокирует кнопку с подписью, второй submit — `preventDefault`;
  подпись валюты следует за select.

#### Checks (PR B)
- `make site-cs-twig` — 0 / OK (324); `npm run lint` — 0; `npm run build` — OK
- `make site-cs-check` — 0; `make site-stan` — OK
- функциональные тесты 11 файлов страниц PR B — OK (100 тестов)
- `tests/e2e/legacy-forms.e2e.cjs` — 8 passed
- `make site-test` — OK (8501 tests, 47782 assertions), 7 мин 09 с

#### Internal review (PR B)
- iteration 1: MINOR — после вырезания `<script>` в `marketplace/sales` и `returns`
  `</div>` и `{% endblock %}` оказались на одной строке; поправлено.

#### External review (PR B)
- rounds: 1 (codex, effort medium, `site/var/external-review/2317ed9b/review.txt`): REVIEW_GREEN
  с одним MINOR — фикстура e2e отменяла submit в capture-фазе до модуля, и проверка
  «второй submit отменён» проходила бы и без защиты. Исправлено: наблюдатель
  регистрируется после модулей и читает `defaultPrevented`; проверка «первый submit
  не отменён, второй отменён». Мутационная проверка: без защиты в `busy_submit.js`
  тест падает.

#### Risks / reviewer focus (PR B)
- `requestSubmit()` вместо `submit()`: срабатывает событие `submit` и HTML-валидация.
  Все 9 форм — GET-фильтры без `required` и без своих submit-обработчиков; у виджета
  смены компании — POST с одним select. В «План–факт» формы год/версия отдельные
  от пилюль `data-pf-pick-form`, которые держит `budget_plan_fact.js`.
- `busy_submit.js` теперь блокирует все `button[type=submit]` формы, прежние скрипты
  блокировали кнопку по id; в трёх формах кнопка submit одна.

#### PR C (2.2): Twig внутри JS → модули с data-*

Инвентарь 2.2 уточнён: `partials/_dds_tree_picker` в список попал ложно (Twig в
комментарии партиала, `<script>` там нет — виджет давно на `data-*`). Остальные
файлы с Twig в JS: пять вынесены здесь, `marketplace_ads/index`,
`marketplace/month_close/index`, `marketplace/index`,
`marketplace_analytics/cost_mappings/index` — PR D вместе с крупными скриптами;
`admin/tools/messenger_ping` (13 строк, admin shell) — отдельным мелким PR админки.

- `company/_tax_profile_fields` → `assets/tax_profile_fields.js` (контейнер
  `[data-tax-profile-fields]`, матрица уже была в `data-matrix`); `id` из `box_id|e('js')`
  не нужен. Страницы: `company/new`, `company/edit`.
- `profile/money_account/new` → `assets/pages/money_account.js`; id полей формы
  (`form.*.vars.id`) заменены атрибутами `data-money-account-*` на виджетах.
- `pl_category/edit` → `assets/pages/pl_category_edit.js`; id полей → `data-pl-category-*`;
  бейдж типа строки — три варианта в разметке, JS только снимает `hidden`
  (раньше `innerHTML` из JS); тексты подсказки формулы — `data-hint-*` на элементе.
- `catalog/product/import_status` → `assets/pages/product_import_status.js`: адреса
  (`path()`) — `data-status-url` / `data-import-url` / `data-products-url` на `#import-body`,
  только пока импорт pending/processing; итог строится через DOM и `textContent`
  (раньше `innerHTML` с сообщениями ошибок из файла импорта — XSS-вектор);
  запрос через `apiFetch`. Попутный дефект: ветка `done` страницы включала
  несуществующий `catalog/product/_import_result.html.twig` с момента фичи
  (`ed0de8d8`) — страница завершённого импорта отдавала 500; партиал создан.
- `finance/report/_filter_controls_script` (включался в `finance/report/preview` и
  `report/cashflow` с id формы через `|e('js')`) → `assets/pages/report_filters.js`,
  формы помечены `data-report-filters`; логика перенесена 1:1, партиал удалён.
- Тесты: новые smoke `MoneyAccountNewPageTest` (страница из списка 1.0) и
  `ProductImportStatusPageTest` (pending — атрибуты опроса; done — опроса нет, итог
  рендерится); проверки `data-*` в `PLCategoryEditControllerTest`,
  `PlReportPreviewControllerTest`, `CompanyTaxProfileTest` (company/new, отчёт ДДС);
  e2e `tests/e2e/page-modules.e2e.cjs` (11 проверок: матрица НДС, тип счёта и бейдж,
  бейдж/подсказка/вставка кода статьи, опрос статуса импорта с XSS-строкой в
  сообщении — рендер текстом, фильтры отчёта: месяц → даты и submit, активация
  множественного фильтра).

#### Checks (PR C)
- `make site-cs-twig` — 0 / OK (324); `npm run lint` — 0; `npm run build` — OK
- `make site-cs-check` — 0; `make site-stan` — OK
- функциональные тесты 5 файлов — OK (37); e2e `page-modules` — 11 passed
- `make site-test` — OK (8504 tests, 47814 assertions), 7 мин 07 с

#### External review (PR C)
- rounds: 1 (codex, effort medium, `site/var/external-review/92611b3e/review.txt`): REVIEW_GREEN без замечаний

#### Risks / reviewer focus (PR C)
- `apiFetch` вместо `fetch(...).then(r => r.json())`: не-2xx теперь исключение → опрос
  продолжается (раньше парсился JSON ошибки и опрос тоже продолжался); 401 →
  переход на вход.
- `report_filters.js`: `requestSubmit()` без запасного `submit()` (все целевые
  браузеры его поддерживают); dropdown-логика та же.
- Бейдж типа статьи ОПиУ: три `<span hidden>` вместо одного с подменой класса —
  визуально то же.

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
- PR C → handoff «merge and deploy»; затем PR D (2.3 крупные скрипты + marketplace-страницы из 2.2)
