# Stage 1 — данные дерева «Статья ДДС»

## 1. Какую задачу решаем
Виджету нужно дерево `[{id,name,children}|{id,name,flow}]`, где id листа ==
value `<option>`, построенное без запросов в цикле и только из статей своей
компании. Сейчас список строится N+1 (`findTreeByCompany` лениво грузит детей
каждого узла), направления у листа нет.

## 2. Что будет результатом
- `CashflowCategoryRepository::findTreeByCompany()` — один запрос (fetch-join
  детей), тот же порядок и тот же набор статей;
- `App\Cash\Form\Transaction\CashflowCategoryTreeBuilder` — чистый сервис:
  из списка статей (порядок дерева) строит вложенный массив с `flow`
  (`in`/`out`/`both`) по правилу Stage 0 §2;
- тип поля `CashflowCategoryTreeType` (parent `ChoiceType`): прежние choices,
  label, value, `disabled` у групп + `vars.tree` для виджета; подключён в
  `CashTransactionType` вместо голого `ChoiceType` — имя поля, value и
  валидация не меняются;
- тесты: unit построителя, functional изоляции и числа запросов.

## 3. Что считаем выполненной работой
Форма правки отдаёт `vars.tree`, число SQL на странице правки не растёт с
числом статей, тесты зелёные, гейты по изменённым файлам чистые.

## 4. Чек-лист приёмки
- [x] `findTreeByCompany` — один запрос; порядок «родитель, затем дети по `sort`» прежний (тест)
- [x] Построитель: вложенность ≥ 5 уровней, группа без листьев сохраняется как группа, `flow` из `operationType` с наследованием, `CF_TECH_IN/OUT`, TRANSFER/null → `both` (unit)
- [x] Чужая компания: в дереве и в select нет её статей (functional)
- [x] Число запросов страницы правки одинаково при 3 и 30 статьях (functional, красный на старом коде)
- [x] Имя поля `cash_transaction[cashflowCategory]`, value = id, группа по-прежнему `disabled` в select; существующие тесты страницы зелёные
- [x] `make site-cs-check`, `site-cs-strict-types`, PHPStan по изменённым файлам без новых ошибок

---

## Итог (27.10.2026)
- `CashflowCategoryRepository::findTreeByCompany()` — один запрос с
  fetch-join `children` (порядок `sort`, `id`); обход дерева в памяти.
  Выигрывают все вызывающие (форма операции, автоправила, сплиты, CashFacade).
- `CashflowCategoryTreeBuilder` (`src/Cash/Application/Service/`) — дерево с
  `flow`; `CashflowCategoryTreeType` (`src/Cash/Form/Transaction/`) — прежние
  label/value/`disabled` у групп, `vars.tree`, `vars.direction`
  (`source` = `[name="cash_transaction[direction]"]`, `in`/`out` = value option
  направления, найденные по `CashDirection`, а не по тексту).
- SQL страницы правки: **до** — 13 при 2 статьях и 40 при 29 (растёт на 1 с
  каждой статьёй); **после** — 13 в обоих случаях.
  `CashTransactionCategoryTreeTest::testEditPageQueryCountDoesNotGrowWithNumberOfCategories`
  красный на старом репозитории (`13 → 40`), зелёный на новом.
- Тесты: `CashflowCategoryTreeBuilderTest` (5), `CashTransactionCategoryTreeTest`
  (2: число запросов; дерево только своей компании + направление формы);
  `tests/Functional/Cash`, `tests/Unit/Cash`, `tests/Integration/Cash` — 700/700.
- Гейты по изменённым файлам: php-cs-fixer (`.php-cs-fixer.php`) — чисто
  после выравнивания phpdoc; PHPStan (`phpstan.dist.neon`) — `No errors`.
  Полные `make site-stan`/`site-test` — на handoff.
