# Stage 1 — каскад уровня потомков

stage_base_commit: `7c2b8278`. Риск HIGH-LOCAL.

## Сделано
- `PLCategory::setParent()` после смены родителя пересчитывает `level` всех
  потомков (`refreshDescendantLevels()`). Новый `getSubtreeHeight()`.
- Проверка глубины ветки — у вызывающих, по итоговому дереву:
  `PLCategoryController::edit` предлагает только родителей, при которых ветка
  не выходит за 5 уровней; импорт уже проверяет план (`preservedDescendantDepth`).
  Первая версия проверяла глубину ветки в сущности. Она сломала существующий
  `testDoesNotCountSiblingBeingReparentedElsewhereAsPreservedDepth`: импорт
  временно переносит узел глубже, пока его глубокий потомок ещё не вынесен.
  Проверку убрал из сущности.

## Тесты, красные на старом коде → зелёные
- unit `PLCategoryTest`: перенос в корень, перенос глубже, высота ветки (×2).
- functional `PLCategoryEditControllerTest`: сценарий прода через форму;
  фильтр родителей по высоте ветки.
- unit `ImportPLCategoryTreeActionTest::testPreservedDescendantFollowsNodeMovedToRoot`
  (red: `3 is identical to 2`); в тест промежуточного состояния добавлены
  проверки итоговых уровней.

Набор категорий ОПиУ: baseline OK (49) → OK (61 tests). CS 0, PHPStan по
изменённым файлам: No errors.

## Self-review
- IDOR: новых запросов нет; `edit` уже сверяет компанию.
- N+1: `getSubtreeHeight()` и каскад лениво грузят `children` переносимой
  ветки. Это одна ветка, а на проде всего 325 статей. Допустимо.
- `ARCHITECTURE.md`: контракты Facade не менялись.
