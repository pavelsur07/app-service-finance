# Stage 2 — виджет «Статья ДДС» в legacy-слое

## 1. Какую задачу решаем
Заменить нативный select «Статья ДДС» переиспользуемым виджетом выбора листа
дерева (SPEC §3) так, чтобы форма, имя поля, value и серверная валидация не
изменились, а без JS работал прежний select.

## 2. Что будет результатом
- CSS `site/assets/styles/components/dds-pickers.css` — эталонный, алиасы
  `--dds-*` сопоставлены с `--vf-*` в одном блоке; подключён в legacy-цепочку;
- JS-модуль `site/assets/dds_tree_picker.js` (эталонный класс +
  `initDdsTreePickers(root)`), импорт и автозапуск в `assets/legacy-app.js`;
- Twig-partial `site/templates/partials/_dds_tree_picker.html.twig` и тема
  формы `form/cashflow_category_tree_theme.html.twig` (блок
  `cashflow_category_tree_widget`, глобально в `twig.yaml`) — любое поле
  `CashflowCategoryTreeType` становится виджетом без правок шаблона;
- страница «Новая/Редактирование операции» использует виджет (через `_form`);
- e2e `site/tests/e2e/dds-tree-picker.e2e.cjs` (puppeteer в контейнере) на
  демо-странице с проектными JS/CSS + functional-тест разметки.

## 3. Что считаем выполненной работой
Сценарии эталонного e2e (17) проходят на проектных файлах; страница правки
рендерит виджет с деревом своей компании; сохранение формы с выбранной
статьёй работает как раньше; lint/build/тесты зелёные.

## 4. Чек-лист приёмки (CHECKLIST D + связанные пункты)
- [x] ⛔ Выбираются только листья; клик и Enter по группе лишь раскрывают/сворачивают (e2e)
- [x] 4 уровня групп + лист, отступ 22 px, направляющие линии (скриншот против `dds-refs/02`, `11`)
- [x] Счётчик группы = число листьев включая вложенные
- [x] Поиск: подсветка, раскрытие, скрытие пустых групп, первая строка активна, пустое состояние (e2e, скриншот против `03`, `12`)
- [x] ↑ ↓ Enter Esc; Esc возвращает фокус на кнопку; клик вне закрывает (e2e)
- [x] Фильтр по направлению + «Показать все»; `both` виден всегда (e2e)
- [x] Смена направления сбрасывает несовместимую статью, заметка, `change` на select (e2e)
- [x] Исходный `<select>` в форме и отправляется; без JS — прежний select (functional: имя поля, сохранение)
- [x] ARIA: `aria-haspopup/expanded`, `role=tree/treeitem`, `aria-selected`, `aria-activedescendant` (e2e/ручной обход)
- [x] Названия — текстом (`<script>`/`&` в имени не исполняются) (e2e)
- [x] Повторное подключение без правок кода — тема формы + демо-страница
- [x] Цвета только через блок алиасов (`--vf-*`), нет хардкода вне него
- [x] `npm run lint`, `npm run build`, функциональные тесты Cash зелёные

---

## Итог (27.10.2026)
- `site/assets/styles/components/dds-pickers.css` — эталонный CSS, блок
  алиасов `--dds-*` → `--vf-*`; пять значений без токена (hover поля,
  мягкое выделение, направляющая, фон счётчика, подсветка поиска) — там же с
  `TODO(ds)`. Отступы и направляющие — через `--d` (глубина строки) и
  `repeating-linear-gradient`: эталон держал 4 уровня жёстким списком
  селекторов, в модели глубина не ограничена.
- `site/assets/dds_tree_picker.js` — эталонный класс; отличия от эталона:
  select прячется только после запуска (`.is-ready`) — без JS виден обычный
  select (в эталоне он был скрыт всегда); `disabled` у select блокирует
  виджет; `aria-level`, `aria-labelledby` (подпись поля + значение);
  `is-invalid` select'а → рамка ошибки поля; клик по группе возвращает фокус в
  поиск; без неиспользуемого `COLLAPSE_KEY`.
- Подключение: `assets/legacy-app.js` (импорт CSS + `initDdsTreePickers()`),
  тема `form/cashflow_category_tree_theme.html.twig` (глобально в
  `twig.yaml`) рендерит любое поле `CashflowCategoryTreeType` через
  `partials/_dds_tree_picker.html.twig`. Шаблоны операции не правились —
  виджет появился на «Новой» и «Редактировании» операции через `_form`.
- e2e `site/tests/e2e/dds-tree-picker.e2e.cjs` на демо
  `tests/e2e/dds-tree-picker.html` (проектные JS/CSS, направление — select с
  value-индексами, как у формы): **30/30** (17 сценариев эталона + глубина и
  направляющие, счётчик, ARIA, activedescendant, `change`, фокус после Enter,
  пустой поиск, XSS, очистка). Запуск — в шапке файла.
- Functional `CashTransactionCategoryTreeTest::testEditPageRendersPickerOverTheFormSelectAndSavesTheLeaf`:
  на странице правки виджет поверх `select[name="cash_transaction[cashflowCategory]"]`,
  `data-tree` своей компании, группа `disabled`, сохранение листа работает.
- Проверки: `eslint` (изменённые JS) — чисто; `npm run build` — собрано;
  `tests/Functional/Cash` + `tests/Functional/Finance` — 320/320;
  `lint:twig` — чисто.
- Скриншоты демо: дерево — совпадает с `dds-refs/11` по отступам,
  направляющим, счётчикам, подвалу; поиск — с `03`/`12` (подсветка, раскрытие).
  Шрифт демо — системный (Inter грузит layout страницы).
