### Stage 2: JS из шаблонов в `assets/` — DONE (PR A–I на проде 07.11.2026, #2910–#2921; закрывающий PR — удаление демо-партиалов dashboard)

**Risk:** MEDIUM (поведение страниц; финансовые формы — в PR C)
**Stage base commit:** `3c7bb858c` (master после Stage 1)
**Work items:** 2.0–2.3; PR A = 2.0 (для своих страниц) + 2.1a; PR B = 2.1b; PR C = 2.2 (пять модулей);
PR D = 2.2 (три marketplace-страницы); 2.3 (крупные скрипты) — PR E (маппинг ОПиУ), PR F (реклама), PR G (листинги и теги), далее операции, документы

Правило плана «один PR — не больше ~5 страниц» делит 2.1 на две части:
2.1a — формы с `onsubmit="return confirm()"` (9 шаблонов, один механический
паттерн, один общий диалог), 2.1b — мелкие `<script>` и
`onchange="this.form.submit()"` (общий `data-autosubmit`). 2.2 и 2.3 — отдельными
PR C и PR D, а не вместе с 2.1b: каждый трогает другие страницы.

PR A: #2911, merge `2317ed9b`, деплой 07.11 08:19Z без даунтайма, GlitchTip чист,
контейнеры healthy. PR B: #2912, merge `92611b3e`, деплой 07.11 08:54Z без даунтайма, GlitchTip чист.
PR C: #2914, merge `b4311777`, деплой 07.11 09:24Z без даунтайма, GlitchTip чист (последнее событие 04:37),
контейнеры healthy. PR D: #2915, merge `24dbd846`, деплой 07.11 09:58Z без даунтайма,
GlitchTip чист, контейнеры healthy (12). PR E: #2916, merge `97b51546`, деплой 07.11 10:41Z без даунтайма (перезапуск после flaky SettlementSourceLockOrderTest), GlitchTip чист.

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

#### PR D (2.2, часть 2): marketplace-страницы с Twig внутри JS

Три страницы, чьи скрипты подставляли `path()` и `csrf_token()` в JS:

- `marketplace/month_close/index` (141 строка JS) → `assets/pages/month_close.js`:
  адреса API на корне `[data-month-close]` (`data-preflight-url`, `data-rebuild-url`),
  кнопки `[data-preflight]` / `[data-preliminary-rebuild]` несут период и токен;
  результат preflight строится через DOM и `textContent` (раньше `label`/`message`
  проверок шли в `innerHTML` без экранирования, детали — через свой `escapeHtml`);
  спиннеры форм «Сверить с xlsx»/«Закрыть этап» — общий `data-busy-submit`;
  `confirm('Переоткрыть этап?')` → `_confirm_modal` с полями этапа в
  `data-confirm-fields`; фильтр периода — `data-autosubmit` (3 `onchange`).
- `marketplace/index` (77 строк) → `assets/pages/marketplace_index.js`: модал
  «Применить выручку» (`[data-apply-realization-*]`), «Обработать месяц» —
  `data-bulk-process-url` на модале, `apiFetch` вместо ручного разбора content-type.
- `marketplace_analytics/cost_mappings/index` (71 строка) → `assets/pages/cost_mappings.js`:
  `data-add-url` / `data-index-url` на корне `[data-cost-mappings]`; `confirm('Удалить
  маппинг?')` → `_confirm_modal`; удаление — DELETE через `apiFetch` по адресу триггера.
- Общее: `assets/confirm_modal.js` — событие `confirm:submit` (cancelable, на
  кнопке-триггере) для действий, которые выполняет JS страницы вместо POST формы
  диалога; `assets/bootstrap_modal.js` — один helper доступа к Bootstrap Modal
  (прежние три копии в `project_direction_picker`, `budget_versions`, `dds_operation`
  не трогал); `assets/api/client.ts` — метод `DELETE`, `204` → `undefined`, сообщение
  ошибки из легаси-тел `{error: "текст"}` / `{type, message}` (иначе пользователь
  видел бы «Ошибка сервера (422)» вместо «Подождите минуту…»).
- Тесты: новый smoke `MarketplaceAnalytics/CostMappingsIndexPageTest` (страница из
  списка 1.0: хуки модуля, триггер удаления, диалог, нет `onclick`/`onchange`);
  `MonthCloseHistoryControllerTest` — хуки preflight/закрытия и переоткрытие через
  `submitConfirmTrigger()` (этап действительно переоткрывается);
  `MarketplaceIndexHistoryFilterMonthTest` — атрибуты модалов; e2e
  `tests/e2e/marketplace-modules.e2e.cjs` (9 проверок: preflight с XSS-строкой в
  label — текстом, multipart с токеном, 202/429 пересчёта, модал выручки, пакетная
  обработка с ошибкой сервера, добавление маппинга, удаление через диалог — DELETE
  с токеном и форма диалога не отправлена, смена маркетплейса — переход);
  заглушка `tests/e2e/api-client-stub.js` повторяет контракт `apiFetch`.

#### Checks (PR D)
- `make site-cs-twig` — 0 / OK (324); `npm run lint` — 0; `npm run build` — OK
- `make site-cs-check` — 0 of 3904; `make site-cs-strict-types` — 0; `make site-stan` — OK
- функциональные тесты 5 файлов — OK (11, 82 assertions); e2e `marketplace-modules` — 9 passed
- `make site-test` — OK (8506 tests, 47855 assertions), 7 мин 26 с

#### External review (PR D)
- rounds: 1 (codex, effort medium, дифф 1503 строк, `site/var/external-review/b4311777/review.txt`): REVIEW_GREEN без замечаний

#### Risks / reviewer focus (PR D)
- `apiFetch` вместо `fetch`: ответ 4xx/5xx — исключение с сообщением сервера;
  `X-Requested-With` больше не шлётся (ни один из эндпоинтов его не читает).
- Preflight: тело уходит `multipart/form-data` (FormData), контроллер читает
  `$request->request` — для PHP это тот же `$_POST`, что и urlencoded.
- `confirm:submit`: если модуль страницы не отменил событие, форма диалога уходит
  POST как раньше — поведение остальных диалогов не меняется.
- Кнопка «Закрыть этап» без JS остаётся `disabled` (как и прежде).

#### PR E (2.3, часть 1): модалы маппинга ОПиУ

- `marketplace/cost_pl_mapping/index` (343 строки JS) → `assets/pages/cost_pl_mapping.js`:
  модал удаления категории (имя и адрес формы — из триггера), модал правки маппинга строки
  (POST JSON по `data-update-url` строки, CSRF в `X-CSRF-Token`, ответ ошибки
  `{error: {code, message}}` читает `apiFetch`; защита от позднего ответа в чужую строку
  сохранена), модал базового маппинга затрат (preview/apply — JSON `{marketplace, _token}`;
  доменная ошибка приходит как 200 `{ok: false, message}` — проверяется в модуле). Карточки,
  вкладки и элементы preview строятся через DOM/`textContent`: раньше innerHTML с ручным
  `escapeHtml`, а подписи статусов и счётчики шли без него. `onchange="this.form.submit()"`
  фильтра → `data-autosubmit`. Id полей модала правки → `data-edit-mapping-*`
  (`id` остались только у `label for`).
- `marketplace/pl_mappings/_default_mapping_modal` (223 строки) →
  `assets/pages/default_sale_mapping.js`: тело запроса — FormData (контроллер читает
  `$request->request`), таблица preview через DOM; тексты Ozon/WB и правило «у настроенного
  правила показывать реальную статью» перенесены 1:1. Страница `marketplace/pl_mappings`
  теперь без `<script>`.
- `assets/dom.js` — общий `el(tag, class, children)` для новых модулей (прежние модули со
  своим `el` не трогал).
- Снято: обработчик click кнопки «Настроить базовый маппинг» («если модал уже открыт —
  перезагрузить preview») — недостижим, кнопка под backdrop'ом открытого модала.
- Тесты: `CostPLMappingControllerTest` — хуки трёх модалов, `data-autosubmit`, нет
  `onclick`/`onchange`/`onsubmit`; `OzonPlMappingUiTest` — атрибуты модала базовой настройки;
  e2e `tests/e2e/mapping-modals.e2e.cjs` (10 проверок: модал удаления, правка строки —
  заполнение, dangling-статья, ошибка 422 с текстом сервера, успех с перерисовкой строки;
  preview затрат — JSON с токеном, карточки/вкладки, XSS-строка текстом, `{ok:false}` при 200,
  отсутствие маркетплейса без запроса; preview продаж — FormData с токеном, таблица, конфликты и
  расхождение знака, успех apply).

#### Checks (PR E)
- `make site-cs-twig` — 0 / OK (324); `npm run lint` — 0; `npm run build` — OK
- `make site-cs-check` — 0 of 3904; `make site-cs-strict-types` — 0; `make site-stan` — OK
- функциональные тесты 4 файлов — OK (39 + расширенные 21); e2e `mapping-modals` — 10 passed
- `make site-test` — OK (8506 tests, 47869 assertions), 7 мин 31 с

#### External review (PR E)
- rounds: 1 (codex, effort medium, дифф 1553 строк, `site/var/external-review/24dbd846/review.txt`): REVIEW_GREEN без замечаний

#### Risks / reviewer focus (PR E)
- Состояния кнопки «Применить» (disabled + подсказка) — перенесены 1:1, проверены e2e.
- `apiFetch`: не-JSON ответ 2xx — исключение «Сервер вернул неожиданный ответ»; раньше текст
  был другой, смысл тот же. `X-Requested-With` не шлётся — контроллеры его не читают.
- `setTimeout(reload, 1000)` после apply сохранён.

#### PR F (2.3, часть 2): «Реклама маркетплейсов»

- `marketplace_ads/index` (364 строки JS: Twig внутри JS, захардкоженные фрагменты URL,
  `onclick="return confirm()"` внутри innerHTML) → `assets/pages/marketplace_ads.js`.
  Адреса API — на корне `[data-marketplace-ads]` (`.page-body`); адреса с id — `path()` с
  плейсхолдером-UUID `00000000-0000-0000-0000-000000000000`, модуль подставляет id
  (requirements маршрутов не примут `__ID__`, а прежний код собирал `/marketplace-ads/jobs/…`
  строками). Таблицы «История загрузок» и «Сырые документы» строятся через DOM/`textContent`
  (раньше innerHTML + `escapeHtml`, но числа `batchStats` и статусы — без него).
  «Обработать» — кнопка-триггер `_confirm_modal` (`data-confirm-action` + `extractToken` из
  API) вместо формы с `onclick=confirm` в строке. Модалы «Загрузить за период» и «Пометить
  FAILED», поллер прогресса (15 с, `data-poll-interval`) — через `apiFetch`; разные форматы
  ошибок эндпоинтов (`{message}`, `{error}`) читает `legacyErrorMessage`. Модалы и диалог
  перенесены внутрь корня `.page-body`. Id элементов → `data-*`.
- Тесты: `AdsIndexControllerTest` — адреса на корне (в т. ч. четыре шаблона с плейсхолдером),
  хуки таблиц, форм и модалов, диалог, нет `onclick`/`onchange`/`onsubmit`; e2e
  `tests/e2e/marketplace-ads.e2e.cjs` (9 проверок: таблицы с XSS-строками, прогресс batch'ей,
  триггер подтверждения и отправка формы диалога, фильтр сырых документов, reprocess с
  перечиткой, load-range и mark-failed с ошибками сервера и восстановлением кнопок, поллер).
  Поллер не даёт сети стать idle — e2e ждёт `domcontentloaded` и флаг готовности.

#### Checks (PR F)
- `make site-cs-twig` — 0 / OK (324); `npm run lint` — 0; `npm run build` — OK
- `make site-cs-check` — 0 of 3904; `make site-stan` — OK
- `AdsIndexControllerTest` — OK (3, 19 assertions); e2e `marketplace-ads` — 9 passed
- `make site-test` — OK (8506 tests, 47884 assertions), 7 мин 17 с

#### External review (PR F)
- rounds: 1 (codex, effort medium, дифф 1105 строк, `site/var/external-review/97b51546/review.txt`): REVIEW_GREEN;
  MINOR исправлен — в e2e ожидание reprocess было пустым (`waitForFunction(() => true)`), теперь
  `waitForResponse` на POST reprocess и GET перечитки

#### Деплой (PR F)
- #2917 влит 07.11, деплой 17:17Z без даунтайма, 12 контейнеров healthy, GlitchTip чист.

#### Risks / reviewer focus (PR F)
- Сеть vs сервер в alert'ах: `error.status === 0` — «Ошибка сети: …», иначе «Ошибка: <текст сервера>»
  (раньше сервер без JSON-тела давал «Неизвестная ошибка», теперь «Ошибка сервера (N)»).
- Страница без прав владельца: модалов нет в разметке — модуль проверяет наличие узлов.
- `X-Requested-With` не шлётся — контроллеры его не читают.

#### PR G (2.3, часть 3): реестр листингов и «Управление тегами»

- `marketplace/listings/index` (412 строк JS: fallback-реализация модала без Bootstrap, `window.confirm`,
  строковые `/api/...`, innerHTML для `<option>` и подсказок) → `assets/pages/listings_index.js`;
  `marketplace/listings/tags` (151 строка: два `window.confirm`, innerHTML) → `assets/pages/listing_tags.js`.
  Адреса API — на корнях `[data-listings-index]` / `[data-listing-tags]` через `path()`; адреса с id —
  плейсхолдер-UUID, helper `withId` вынесен в `assets/url_template.js` (из `marketplace_ads.js`).
- Подтверждения — через `_confirm_modal` + `confirm:submit` (как `cost_mappings` в PR D): «присвоить тег всем
  по фильтру» — скрытый триггер в панели, тексты подставляет модуль (собственный `#bulk-tag-confirm` удалён);
  удаление тега — триггер в Twig (`data-confirm-*` из `tag.name`/`listingsCount`); слияние — пункты меню
  строит модуль из `data-tags` как триггеры. Форма диалога не отправляется (модуль шлёт JSON и отменяет
  `confirm:submit`); `data-confirm-token` = `csrf_token('listing_tags')` — нужен `confirm_modal.js` как условие
  открытия, эндпоинты тегов CSRF не проверяют (как и раньше).
- `<option>` и подсказки — через DOM; у плейсхолдера явный `value=""` (без него `select.value` отдаёт текст,
  и проверку «Выберите товар» можно было обойти — поймал e2e). Fallback «без Bootstrap» (`style.display`)
  удалён: Tabler всегда даёт `bootstrap.Modal` (`bootstrapModal()`). Сброс кнопки «Привязать» — по
  `hidden.bs.modal`, а не по клику на `[data-bs-dismiss]`.
- Тексты ошибок API: `{error:{message}}` → как раньше; строка `{error: "..."}` у `/map` → `legacyErrorMessage`;
  ответ без JSON → «Ошибка сервера (N)» вместо «Не удалось …». Сеть — прежние тексты.
- id сохранены только там, где они контракт: `#listings-filter-form`, `#bulk-tag-panel`, `.row-select`,
  `td.listing-tags` (тесты), `#product-select`/`#createTagName` (`label for`), `#createTagModal`
  (`data-bs-target`).
- Тесты: `MarketplaceListingsSearchTest` и `ListingTagsManageTest` — хуки модулей (адреса на корне,
  шаблон с плейсхолдером, триггеры диалога, нет `onclick`/`onchange`/`onsubmit`); e2e
  `tests/e2e/listings.e2e.cjs` (13 проверок на одной демо-странице с обеими разметками: автосабмит фильтра
  с отключением пустых полей, модал привязки с ошибкой 400, панель тегов и подсказки, диалог «по фильтру»
  с 422, снятие тега, меню слияния, создание/переименование/удаление с ошибками сервера, слияние с
  перезагрузкой, назначение с flash через sessionStorage и сбросом `page`).

#### Checks (PR G)
- `make site-cs-twig` — 0 / OK (324); `npm run lint` — 0; `npm run build` — OK
- `make site-cs-check` — 0 of 3904; `make site-cs-strict-types` — 0; `make site-stan` — OK
- 5 функциональных классов листингов — OK (59 tests, 214 assertions); e2e `listings` — 13 passed
- `make site-test` — OK (8506 tests, 47922 assertions), 7 мин 12 с

#### External review (PR G)
- rounds: 1 (codex, effort medium, база — ветка PR F, дифф 1788 строк с untracked-файлами Владельца, `site/var/external-review/36ac2ed3/review.txt`): REVIEW_GREEN без замечаний

#### Деплой (PR G)
- #2918 влит 07.11, деплой 17:39Z без даунтайма (перезапуск CI после flaky `SettlementSourceLockOrderTest`),
  12 контейнеров healthy, GlitchTip чист.

#### Risks / reviewer focus (PR G)
- Диалог «присвоить всем по фильтру» открывается программно: `Modal.show(trigger)` → `show.bs.modal` с
  `relatedTarget` — тот же путь, что у кнопки с `data-bs-toggle`.
- Пункты «Слить в…» — `<button data-bs-toggle="modal">` внутри dropdown: Bootstrap data-api делегирован на
  document, динамические элементы работают; после закрытия диалога фокус возвращается на «Слить в…»
  (`confirm_modal.js`).
- Переименование: blur inline-поля коммитит, как раньше; Chrome шлёт blur и при удалении узла.

#### PR H (2.3, часть 4): «Операции ДДС» (`transaction/index`)

- 385 строк JS в четырёх `<script>` (поиск в поповерах строки фильтров `[data-ops-pop]`, массовое удаление
  выбранных строк, модал «Автоправило» с AJAX-«Применить», «Создать на основании» с модалом подтверждения
  и ручным backdrop) → `assets/pages/cash_transactions.js`, `initCashTransactions()` в `legacy-app.js`.
  Шаблон 779 → 394 строки.
- Хуки — `data-*` на корне `[data-cash-transactions]` (карточка `.cashflow-ops`, уже несла `data-csrf-token`)
  и на трёх модалах (`data-bulk-delete-*`, `data-auto-rule-*`, `data-create-from-*`). Форма «Применить» в
  партиале `cash_transaction_auto_rule/_auto_rule_modal_body`: `id="autoRuleApplyForm"` → `data-auto-rule-apply`.
  id сохранены только как контракт: `#cash-transactions-select-all`, `#bulk-delete-button`, `#bulkDeleteModal`,
  `#autoRuleModal`, `#createFromWarningModal` (тесты, `data-bs-target`), класс `.js-cash-transaction-select`
  (три теста).
- Запросы — `apiFetch` с `FormData` (контроллеры читают `$request->request`). Не-2xx с JSON-телом
  (`{ok:false,message}` 403 автоправила; `{error:true,message}` 404/403/422 «Создать на основании») приходит
  `ApiError` — текст сервера показывается там же и так же, как раньше; без JSON → «Ошибка сервера (N)».
- Модал «без маппинга» — через `bootstrap.Modal` вместо ручного `classList.add('show')` + backdrop. Тело
  «Автоправила» — HTML нашего шаблона с сервера, вставляется как раньше; результат «Применить», flash и иконки
  строки — DOM/`textContent` (`el()`).
- Защита GlitchTip #414 (один submit-обработчик, `dataset.submitting`, блокировка кнопки) сохранена;
  функциональный тест, читавший текст inline-скрипта, заменён `testPageExposesModuleHooksWithoutInlineScripts`
  (хуки разметки, нет `addEventListener(` в HTML, нет `onclick`/`onchange`/`onsubmit`); одиночная отправка —
  e2e (второй submit в полёте → один POST).
- e2e `tests/e2e/cash-transactions.e2e.cjs` — 15 проверок: поповер (фокус по `shown.bs.dropdown`, поиск,
  ArrowDown в список), массовое удаление (панель и подсветка, select-all, скрытые id по `show.bs.modal` и по
  submit, сброс), автоправило (загрузка HTML, двойной submit → один POST с полями формы, 403 → предупреждение),
  «Создать на основании» (needsConfirmation → модал, текст и action; подтверждение → `window.open`, иконка,
  блокировка; тихое создание → flash; 422 → flash-ошибка, кнопка снова активна).

#### Checks (PR H)
- `make site-cs-twig` — 0 / OK (324); `npm run lint` — 0; `npm run build` — OK
- `make site-cs-check` — 0 of 3913; `make site-cs-strict-types` — 0; `make site-stan` — OK
- 4 функциональных класса (bulk delete, create-from, auto-rule apply, settlement UI) — OK (14 tests, 115 assertions);
  e2e `cash-transactions` — 15 passed
- `make site-test` — OK (8538 tests, 48069 assertions), 8 мин 30 с

#### External review (PR H)
- rounds: 1 (codex, effort medium, база master 7e10ae310, дифф 1268 строк, `site/var/external-review/7e10ae31/review.txt`):
  REVIEW_GREEN без замечаний. Первый запуск шёл против отставшего локального `master` (97b51546, дифф 5421 строк
  с уже выкаченными PR F/G) — тоже REVIEW_GREEN; локальный `master` подтянут.

#### Деплой (PR H)
- #2920 влит 07.11, деплой 19:07Z без даунтайма, 12 контейнеров healthy, GlitchTip чист.

#### Risks / reviewer focus (PR H)
- Flash «Создать на основании» вставляется перед карточкой (`root.before`), как раньше `card.before`.
- `data-bs-toggle="modal"` на кнопке «Автоправило» остался: Bootstrap открывает модал сам, модуль дублирует
  `show()` как и прежний скрипт.
- HIGH-LOCAL по плану (финансовые формы): контроллеры не менялись, контракты ответов проверены по коду.

#### PR I (2.3, часть 5): страница документа ОПиУ (`document/_page_scripts`)

- Партиал `document/_page_scripts` (460 строк: операции документа — добавить, дубль, удалить, наследование
  Проекта/ЦФО из шапки; итоги и остаток по основанию; формат сумм DS-Money; несохранённые правки,
  «Выйти без сохранения?», фокус на ошибку после неудачного сохранения) → `assets/pages/pnl_document.js`,
  `initPnlDocument()` в `legacy-app.js`. Партиал удалён, `document/{edit,new}` без блока `javascripts`.
- Скрипт уже работал на `data-*`-хуках без Twig-выражений — перенос механический: `function` → стрелки,
  `bootstrap.Modal` → `bootstrapModal()`, обёртка `DOMContentLoaded` снята (`legacy-app.js` сам ждёт DOM).
  Разметка `_page.html.twig` не менялась; `.add_item_link`, `.remove-item`, `.document-operation-card`,
  `#document-leave-modal` — прежние контракты (тесты, aria).
- `template.innerHTML` для `data-prototype` строки формы — как раньше (prototype Symfony — наша разметка).
- Тесты: `DocumentNewPageTest` — хуки (`[data-collection-holder][data-prototype]`, `.add_item_link`,
  `[data-leave-confirm]`), нет `addEventListener('DOMContentLoaded'` и inline-обработчиков; e2e
  `tests/e2e/pnl-document.e2e.cjs` — 15 проверок на демо-странице (итоги и формат сумм, смена Проекта шапки
  с уведомлением, «Как в шапке», добавление с индексом выше существующих, дубль, удаление и запрет удалить
  последнюю, «Добавить операцию на остаток», перерасход, счётчик описания, диалог ухода и `data-leave-safe`,
  нормализация сумм при submit, режим создания с одной строкой, страница после ошибки сохранения).
  Сценарии на живом стенде (`pnl-document-{page,new}.e2e.cjs`) не менялись.

#### Checks (PR I)
- `make site-cs-twig` — 0 / OK (323); `npm run lint` — 0; `npm run build` — OK
- `make site-cs-check` — 0 of 3913; `make site-cs-strict-types` — 0; `make site-stan` — OK
- `DocumentNewPageTest`, `DocumentCategoryPickerTest` — OK (9 tests, 73 assertions); e2e `pnl-document` — 15 passed
- `make site-test` — OK (8538 tests, 48074 assertions), 8 мин 25 с

#### External review (PR I)
- rounds: 1 (codex, effort medium, база — ветка PR H 0d64b52a5, дифф 1268 строк, `site/var/external-review/0d64b52a/review.txt`): REVIEW_GREEN без замечаний

#### Деплой (PR I)
- #2921 влит 07.11, деплой 19:24Z без даунтайма (перезапуск CI после flaky `SettlementSourceLockOrderTest`;
  фикс таймаута — PR #2922), 12 контейнеров healthy, GlitchTip чист.

#### Risks / reviewer focus (PR I)
- HIGH-LOCAL по плану (финансовые формы): контроллеры и формы не менялись, разметка не менялась.
- Порядок инициализации: раньше скрипт ждал `DOMContentLoaded` после бандла, теперь работает в `initDds()`
  после `initDdsTreePickers()` — виджеты строки по-прежнему подключает наблюдатель DOM.

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
- Закрывающий PR Stage 2 (эта ветка): удалены `dashboard/_demo_chart` и `dashboard/_money_balance_chart`
  (Chart.js с CDN, нигде не подключены — ни `include`, ни `render` в `src/`, `templates/`, `tests/`; история —
  «dashboard demo»); переносить было нечего. `lint:twig` — 321 файл. Итог этапа: 0 `<script>` с логикой страниц
  вне исключений конвенции (`_layout/*`, `admin/base`, `notifications/email`, JSON-данные `type="application/json"`).
- Внешнее ревью закрывающего PR (codex, база master 4ac412c42): по коду замечаний нет; единственная находка —
  артефакт скрипта: он диффует рабочее дерево двумя точками (`git diff master`), и в дифф попал «откат» чужого
  PR #2919 (`marketplace/index`, `data-busy-submit`), которого нет в ветке (три точки — только наши 5 файлов).
  Урок: перед ревью ветку с отставшей базой перебазировать на master или читать находки через `git diff master...HEAD`.
- Дальше — Stage 3 (`stages/stage-3.md`, план и замер «до» записаны).
