# Stage 0 — аудит

## 1. Какую задачу решаем
До правок понять, что реально стоит за страницами «Документ ОПиУ» и «Новый
документ ОПиУ», зафиксировать замеры «до» и закрыть решения SPEC §8 по коду,
чтобы этапы 1–6 не изобретали модель и не ломали роуты, поля и хуки.

## 2. Что будет результатом
Раздел «Аудит» в `docs/plan/ds-pnl-document.md`:
- файлы страницы: контроллер, шаблоны (`document/new`, `edit`, `_page`,
  `_page_scripts`), формы, JS, сущности `Document`/`DocumentOperation`,
  репозитории, Action'ы создания/правки/удаления/копии;
- поле «Статья ОПиУ»: откуда список (`PLCategoryRepository::findTreeByCompany`),
  какие типы/уровни попадают в select, как отмечены группы, `data-flow`;
  что переиспользуется от «Статьи ДДС» (`CashflowCategoryTreeType`,
  `form/cashflow_category_tree_theme.html.twig`, `assets/dds_tree_picker.js`);
- Проект/ЦФО: шапка и операции, как сервер их нормализует
  (`responsibilityCenterNormalizer`), что предзаполняет создание из ДДС;
- коллекция операций: `data-prototype`, добавление/удаление/дублирование
  строк, подвал итогов, сверка с основанием (`buildTransactionAllocationView`,
  `assertCanAllocateAmount`);
- soft-delete/restore, копия, JSON, история;
- все упоминания страниц: меню, крошки, ссылки из ДДС, тесты, e2e, документы;
- список `data-*`-хуков и имён полей, которые нельзя менять;
- SQL-запросы `document_edit` и `document_new` по профайлеру «до» (число,
  повторы), скриншоты «до» в 1440/1280/390;
- таблица решений SPEC §8 (см. `plan.md`) — подтверждена или исправлена.

## 3. Что считаем выполненной работой
Каждая строка таблицы §8 в `plan.md` подтверждена ссылкой на код или
исправлена; есть числа запросов «до» и скриншоты «до»; вопросы, которые код не
решает, записаны в «Открытые вопросы владельцу». Код не менялся.

## 4. Чек-лист приёмки
- [x] Файлы страницы и их роли перечислены, с путями
- [x] Источник списка статей, отбор листьев, глубина и `flow` — со ссылками на код
- [x] Проект/ЦФО шапки и операции: хранение и серверная логика описаны (SPEC §8.1)
- [x] Поле описания операции найдено или отмечено отсутствующим (SPEC §8.2)
- [x] Soft-delete/restore, копия, JSON, история — есть/нет (SPEC §8.3, §8.6)
- [x] Поток создания (из ДДС и вручную) описан; расхождение с SPEC §6 п.5 — вопрос (SPEC §8.4)
- [x] Неприкосновенные роуты, имена полей, CSRF, `data-*` — списком
- [x] Запросы «до» по `document_edit` и `document_new` записаны
- [x] Скриншоты «до» 1440/1280/390 сняты
- [x] Разбивка на PR подтверждена или поправлена в `plan.md`

---

## Итог (28.10.2026)
- **Файлы.** `Finance\Controller\DocumentController` (`document_new`, `document_copy`,
  `document_edit`, `document_delete`, `document_restore`, `document_export_json`,
  `document_show`, `document_deleted_index`); `templates/document/{new,edit}.html.twig` —
  обёртки над общим `_page.html.twig` (разметка) и `_page_scripts.html.twig` (inline-JS:
  итоги, сверка, добавление/удаление строк, уход без сохранения); формы
  `Finance\Form\DocumentType` / `DocumentOperationType`; сущности
  `Finance\Entity\Document` / `DocumentOperation` / `PLCategory`. Страница уже
  переведена на DS задачей #2695–#2699 (`docs/plan/ds-pnl-documents.md`): шапка,
  «Основание», «Сверка», сводка ошибок, подвал, модалки — есть.
- **Статья ОПиУ.** `DocumentOperationType::category` — `EntityType` по
  `PLCategoryRepository::findTreeByCompany($company)` (свой модуль Finance), подпись
  «— » × уровень, `choice_attr` `data-flow` = `PLFlow` (INCOME/EXPENSE/NONE),
  placeholder «Выберите статью», `required: false` — **сервер категорию не требует**,
  группы (`SUBTOTAL`) в select **не disabled**. Уровни 1–5 (`PLCategory::__construct`
  `Assert::range($level, 1, 5)`) — 4 группы + лист. От «Статьи ДДС» переиспользуются
  `assets/dds_tree_picker.js` и `partials/_dds_tree_picker.html.twig`; построитель
  (`CashflowCategoryTreeBuilder`) и тип (`CashflowCategoryTreeType`) — свои у Cash.
- **Тип операции.** Поля нет: «Доход/Расход» в строке — подпись по `flow` выбранной
  статьи, у `NONE` — по знаку суммы (как регистр, `DocumentPlContribution`). Решение
  #2699 «тип только показывается» сохраняется: select «Тип» в строке стал бы полем без
  модели. Следствие для виджета: direction-source нет, фильтра по типу и сброса
  несовместимой категории нет; у листьев всегда теги «Доход»/«Расход» (SPEC §5.3 —
  отклонение, в отчёт).
- **Проект/ЦФО.** У `Document` и у `DocumentOperation` есть `projectDirection`
  (`ProjectDirectionPickerType`, хук `data-document-project-direction` /
  `data-operation-project-direction`) и `responsibilityCenterId` (`ChoiceType`).
  JS подставляет значения шапки только в новую строку и только в пустое поле. Проект
  шапки на сервере необязателен (placeholder «—»). §8.1 решается хранением —
  эвристика не нужна.
- **Описание операции.** `DocumentOperation::comment` varchar(255), поле `comment`
  «Комментарий», `Length(max: 255)` — §8.2 без миграции, показываем как «Описание».
- **Контрагент операции** (`counterparty`) есть, в макете колонки нет — не прячем,
  переносим во вторую строку рядом с описанием.
- **Удаление.** Soft-delete (`deletedAt`), `document_restore`, «Удалённые»; правка
  удалённого запрещена (`denyDeletedDocument`) — баннер на форме не нужен.
  «Копировать» (`document_copy`) и «Выгрузить JSON» есть; истории нет (§8.6).
- **Создание.** Из ДДС — POST `cash_transaction_create_pnl_document` → документ
  создаётся сразу → `document_edit`; `document_new` — вручную без основания;
  `document_copy` — тот же шаблон (§8.4).
- **Хуки** (не менять): `data-document-form`, `data-collection-holder` +
  `data-prototype`, `.add_item_link`, `.remove-item`, `.document-operation-card`,
  `data-operation-row/-amount/-category/-flow-label/-counterparty/-project-direction/-responsibility-center`,
  `data-document-counterparty/-project-direction/-responsibility-center`,
  `data-allocation*`, `data-total-*`, `data-dirty-*`, `data-error-summary`,
  `data-leave-*`; имена `document[...]`, `document[operations][N][...]`; кнопка «Сохранить».
- **Запросы «до»** (dev-стенд, профайлер): `document_edit` с 2 операциями и основанием —
  30 запросов / 13 различных / 28 мс; `document_new` — 21 / 8 / 21 мс.
- **Скриншоты «до»** — 1440/1280/390 правки и создания (scratchpad, в отчёт).
- **Разбивка на PR** подтверждена.

Чек-лист: все пункты выполнены.
