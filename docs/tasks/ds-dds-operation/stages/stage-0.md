# Stage 0 — аудит страницы «Операция ДДС» и модели статей

## 1. Какую задачу решаем
До правок понять, что реально стоит за страницей редактирования операции и
списком «Статья ДДС»: шаблоны, форма, контроллер, JS, модель дерева статей,
направление статьи, упоминания старого названия, число SQL-запросов. Без этого
план виджета и страницы — гипотеза.

## 2. Что будет результатом
Этот файл: факты с путями, расхождения макета с моделью, решения по
безопасным вариантам (SPEC §7) и разбивка на этапы/PR.

## 3. Что считаем выполненной работой
Ответы на все 5 пунктов Stage 0 из `PROMPT-dds-operation-edit.md`, каждый с
путём к коду; открытые вопросы выписаны отдельно.

## 4. Чек-лист приёмки
- [x] Найдены шаблон, partial формы, FormType, контроллер, JS страницы
- [x] Описано хранение иерархии статей, глубина, «направление» статьи, «Технические операции», «Не распределено»
- [x] Найдены все упоминания «Редактирование транзакции»
- [x] Зафиксирован объём SQL страницы (метод замера и оценка; точная цифра — тестом в Stage 1)
- [x] Предложена разбивка на этапы и PR
- [x] Открытые вопросы SPEC §7 сопоставлены с моделью

---

## Итог аудита

### 1. Что стоит за страницей
| Что | Где |
|---|---|
| Роут | `cash_transaction_edit`, `GET|POST /finance/cash-transactions/{id}/edit` — `site/src/Cash/Controller/Transaction/CashTransactionController.php` (`edit()`) |
| Шаблон | `site/templates/transaction/edit.html.twig` (`extends base.html.twig` → `_layout/legacy.html.twig`) |
| Форма | partial `site/templates/transaction/_form.html.twig` — **общий с `transaction/new.html.twig`** (создание) |
| FormType | `site/src/Cash/Form/Transaction/CashTransactionType.php` |
| Сохранение | `UpdateCashTransactionAction` (flush + пересчёт остатков) |
| JS страницы | inline-скрипт в `edit.html.twig`: копирует `data-currency` счёта в серое поле «Валюта» |
| Пикеры | Проект — `ProjectDirectionPickerType` + `assets/project_direction_picker.js` (`.pd-*`); Контрагент — `CounterpartyPickerType`, тема `templates/form/counterparty_picker_theme.html.twig`, `assets/counterparty_picker.js` + `picker_core/field/pill.js` (окно browse) |
| Сборка legacy | `assets/legacy-app.js` (импорт CSS `styles/legacy-app.css` и модулей пикеров), токены `assets/styles/design-tokens.css` (`--vf-*`) |

Поля формы: `occurredAt` (date), `moneyAccount` (ChoiceType, `data-currency`),
`direction` (ChoiceType select INFLOW/OUTFLOW), `amount` (NumberType scale 2),
`currency` (disabled, unmapped), `cashflowCategory` (ChoiceType, unmapped),
`projectDirection`, `responsibilityCenterId` (ChoiceType из
`FinancialResponsibilityCenterFacade`), `counterpartyId`, `description`.

Контроллер: компания через `getActiveCompany()`, `findOneByIdAndCompanyId` —
IDOR закрыт; перевод (CashTransfer) редактировать нельзя — редирект на show.
Удаление (`cash_transaction_delete`, CSRF `tx_delete{id}`) — soft-delete
(`deletedAt/deletedBy/deleteReason`); восстановление есть
(`cash_transaction_restore`, CSRF `tx_restore{id}`, редирект на список
удалённых). `findOneByIdAndCompanyId` не фильтрует `deletedAt` — страница
правки открывается и у удалённой операции.

### 2. Статьи ДДС (`CashflowCategory`, модуль Cash)
- Иерархия — `parent_id` (self ManyToOne / OneToMany `children`, порядок
  `sort`). Глубина в коде не ограничена; `getLevel()` — рекурсия по parent.
- Список для select: `CashflowCategoryRepository::findTreeByCompany()` —
  корни одним запросом, затем рекурсивный обход `getChildren()` → **по запросу
  на каждый узел** (N+1). `choice_attr` помечает группы `disabled` (только HTML:
  сервер группу примет), `choice_label` — отступ NBSP по уровню. Статус
  `disabled` не фильтруется.
- **Направление статьи** (приток/отток) в модели — `operationType`
  (`PaymentPlanType`: INFLOW / OUTFLOW / TRANSFER, nullable), наследуется от
  родителя (`PaymentPlanService::resolveTypeFromHierarchy`). В UI справочника
  это поле не редактируется — у большинства статей `null`.
  `flowKind` (Операционная / Инвестиционная / Финансовая / Технические) — это
  вид деятельности, не направление.
- «Технические операции» — системная группа `CF_TECH` с детьми `CF_TECH_IN`
  «Поступления» и `CF_TECH_OUT` «Выбытия» (`CashflowSystemCategoryService`).
- «Не распределено» — системная статья `CF_UNALLOC` (`findSystemUnallocatedByCompany`);
  на форме пустое значение select = статья не выбрана.
- Решение для виджета: `flow` листа = ближайший непустой `operationType` вверх
  по дереву (INFLOW → `in`, OUTFLOW → `out`, TRANSFER/нет → `both`); системные
  `CF_TECH_IN`/`CF_TECH_OUT` → `in`/`out`. Новых правил не придумываем: где
  направление не задано данными, статья подходит обоим направлениям.

### 3. Упоминания «Редактирование транзакции»
- `site/templates/transaction/edit.html.twig` — `<title>` и `<h2>`;
- `site/src/Cash/Application/UpdateCashTransactionAction.php` — PHPDoc
  («Редактирование транзакции.»), не UI — не меняем.
- Тесты и e2e на текст заголовка не завязаны. Тесты страницы правки:
  `CashTransactionEditCurrencyTest` (селекторы `table thead th.text-end`,
  `table tbody td.text-end`), `CashTransactionEmptyAmountTest`,
  `CashTransactionCounterpartyPickerTest`, `CashTransactionRouteRequirementTest`,
  `CashCompanyIsolationTest` — их селекторы сохраняем.
- Флеши «Транзакция обновлена/удалена/восстановлена» — в контроллере, роуты и
  тексты флешей вне рамок вёрстки, не трогаем.

### 4. SQL страницы
Стек dev-окружения остановлен; замер — тестом через профайлер (паттерн
`DocumentOperationIndexViewTest::countQueries`). Оценка по коду: дерево статей
= 1 + (число статей) запросов (ленивые `children`), плюс `getLevel()` без
запросов (родители уже в identity map). Цифра «до/после» фиксируется тестом
Stage 1: число запросов не должно расти с числом статей.

### 5. Разрывы с макетом (SPEC §7) — безопасные варианты
| # | Вопрос | Модель | Что делаем |
|---|---|---|---|
| 7.1 | Валюта | валюта = валюта счёта (`resolveCurrencyForAccount`), поле `currency` disabled/unmapped | поле «Валюта» убираем из вида, подпись «Валюта берётся из счёта: …»; документы ОПиУ — в валюте операции (копия суммы) |
| 7.2 | Блокировка полей выписки | сервер разрешает менять всё у любой операции; источник — `importSource`: код банка `alfa` (BankSync), `file`, `telegram`; выписка 1С (`ClientBank1CImportService`) пишет `externalId`/`docType` без `importSource` | **не блокируем** (иначе меняем бизнес-правило только в UI): плашка «из выписки» + бейдж; вопрос владельцу |
| 7.3 | ЦФО | общий справочник компании (`getActiveChoices`), связка проект↔ЦФО — `CashTransactionResponsibilityCenterResolver` | вид меняем, логику нет |
| 7.4 | Дерево статей | `parent_id`, глубина не ограничена, направление — `operationType` | виджет рисует любую глубину; `flow` по правилу выше |
| 7.5 | «Дублировать», «История» | бэкенда нет | не показываем; в «Ещё» только «Удалить» |
| — | Восстановление | есть (`cash_transaction_restore`) | баннер удалённой операции с «Восстановить» — делаем |
| — | Автор операции | поля автора нет (только `deletedBy`) | строку «Автор» в «Сведениях» не показываем |
| — | Группа как значение | сервер принимает id группы (disabled только в HTML) | виджет выбирает только листья; серверное ограничение — вопрос (меняет валидацию) |

Прочее: скрипта `npm run check:ui-kit` больше нет (кит удалён 11.10.2026);
гейт из промпта неприменим. Playwright в проекте не установлен — e2e виджета
гоняем в контейнере.

### 6. Разбивка
| Этап | Что | PR |
|---|---|---|
| 1 | Данные дерева: один запрос, построитель дерева с `flow`, FormType виджета, тесты | PR 1 «виджет» |
| 2 | Виджет: CSS/JS/Twig в legacy, подключение к форме операции, демо, e2e | PR 1 |
| 3 | Страница: каркас (шапка, 2 колонки, карточки, «Сведения», sticky-панель, название) | PR 2 «страница» |
| 4 | Страница: поля (segmented, сумма с валютой, вид Проект/ЦФО/Контрагент, счётчик описания, dirty) | PR 2 |
| 5 | Страница: поведение (валидация, модалки ухода/удаления, удалённая операция, выписка) | PR 2 |
| 6 | Документы ОПиУ, адаптив, скриншоты, гейты, отчёт, handoff | PR 2 |

PR 2 ставится на ветку PR 1 (страница использует виджет). Форма `_form` общая
с созданием — новый вид получит и страница «Новая операция»; всё, что
специфично для правки (документы, сведения, удаление), — только в `edit`.
