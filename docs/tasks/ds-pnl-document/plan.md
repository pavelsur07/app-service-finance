# Задача: «Документ ОПиУ» по макету + виджет «Категория ОПиУ» (legacy-слой)

Поручение: `docs/design-system/PROMPT-pnl-document.md`; эталон —
`docs/design-system/PNL-DOCUMENT-SPEC.md`, `screens/PnlDocument.dc.html`,
`screens/PnlDocumentNew.dc.html`, `screens/pnl-refs/*.png`,
`components/dds-tree-picker/*`; приёмка — `CHECKLIST-pnl-document.md`,
проверка — `PROMPT-pnl-document-verify.md`. Итоговый отчёт —
`docs/plan/ds-pnl-document.md`.

Каждый этап — файл `stages/stage-N.md` из четырёх частей (задача, результат,
что считаем выполненным, чек-лист приёмки); план пишется до работ, итог
дописывается в тот же файл и сверяется с чек-листом.

| Этап | Что | PR | Статус |
|---|---|---|---|
| 0 | Аудит, замеры «до», решения SPEC §8 | — | готово |
| 1 | Данные дерева статей ОПиУ: один запрос, построитель с `flow`, тип поля | PR 1 | готово |
| 2 | Виджет «Категория ОПиУ» в строках коллекции: много экземпляров, динамические строки | PR 1 | готово |
| 3 | Редактирование: каркас, «Основные данные», «Основание», «Сверка», sticky-панель, «Ещё», модалки | PR 2 | план |
| 4 | Таблица операций: колонки, «Описание», Проект/ЦФО с наследованием от шапки, мобильные карточки | PR 2 | план |
| 5 | Валидация, сводка ошибок, удалённый документ, адаптив, скриншоты, гейты, отчёт, handoff | PR 2 | план |
| 6 | «Новый документ ОПиУ» (создание и копия) на тех же partial | PR 3 | план |

Ветки: `feat/ds-pnl-document-widget` (PR 1) → `feat/ds-pnl-document` (PR 2,
база — PR 1) → `feat/ds-pnl-document-new` (PR 3). Коммиты этапов локально,
push и PR — одним шагом на handoff.

## Что уже известно из кода (проверить и дополнить в Stage 0)
Страница — `Finance\Controller\DocumentController` (`document_new`,
`document_copy`, `document_edit`, `document_delete`, `document_restore`,
`document_export_json`), шаблоны `templates/document/{new,edit}.html.twig` →
общий `_page.html.twig` + `_page_scripts.html.twig`, формы
`Finance\Form\DocumentType` / `DocumentOperationType`.

| SPEC §8 | Что в коде | Предлагаемое решение |
|---|---|---|
| 8.1 Проект/ЦФО шапки | у `Document` уже есть `projectDirection` и `responsibilityCenterId`, в `DocumentType` — поля формы | хранить как есть; эвристика «самое частое значение» не нужна; схема не меняется |
| 8.2 Описание операции | у `DocumentOperation` есть `comment` (varchar 255), поле формы `comment` «Комментарий» | показать `comment` как «Описание» во второй строке; имя поля не меняется; `maxlength` — 255 по колонке (200 из SPEC обрезал бы существующие значения) — вынести в отчёт |
| 8.3 Удаление/восстановление | `deletedAt`/`deletedBy`, `document_restore`, `document_deleted_index` | модалка удаления + баннер удалённого и «Восстановить» |
| 8.4 Создание без основания | из операции ДДС документ создаётся сразу (`cash_transaction_create_pnl_document` → `document_edit`); `document_new` — ручной, без основания; `document_copy` — тот же шаблон | форма создания = `document_new`/`document_copy` без блока «Основание»; создание из ДДС не меняем — вопрос владельцу |
| 8.5 Иерархия статей | `PLCategory.parent`, `level` 1–5 (4 группы + лист), `flow` INCOME/EXPENSE/NONE, тип LEAF_INPUT/SUBTOTAL/KPI | лист = статья без детей, доступная сейчас в select; `NONE` виден при любом типе строки (как `both` у ДДС) |
| 8.6 Дублировать / JSON / История | копия и JSON есть, истории нет | в «Ещё» — «Дублировать документ», «Открыть операцию ДДС» (если есть основание), «Выгрузить JSON», «Удалить документ»; карточку «История» не показывать |

## Открытые вопросы владельцу
Заполняются по итогам Stage 0 (SPEC §8): 8.2 — длина описания; 8.4 — нужна
ли отдельная форма создания на основании ДДС; всё, что аудит не подтвердит.
