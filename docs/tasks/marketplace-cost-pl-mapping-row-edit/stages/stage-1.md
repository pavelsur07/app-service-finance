### Stage 1–2: backend и Twig — DONE

Backend и Twig закрыты одним проходом: без нового шаблона старая страница
ломалась бы на удалённом `bulk-save`, промежуточного рабочего состояния нет.

**Risk:** HIGH-LOCAL (новый endpoint, общий репозиторий Finance)
**Stage base commit:** `5785dbc1`
**Work items:** 1.1–1.5, 2.1–2.2

#### What was done
- `PLCategoryRepository::findTreeByCompany` — один SELECT, дерево в памяти, тот же DFS pre-order.
- `CostPLMappingListQuery` + `CostPLMappingRow` — список одной DBAL-выборкой.
- `UpdateCostPLMappingAction` (без изменений — без записи; `sortOrder` при создании сохраняется),
  `DeleteCostCategoryAction`, 4 доменных исключения, `findByIdAndCompanyId`.
- Контроллеры `CostPLMappingIndexController`, `CostPLMappingUpdateController` (JSON),
  `CostCategoryDeleteController`; `CostPLMappingController` и `bulk-save` удалены.
- Шаблон: таблица только для чтения, одна модалка, 2 CSRF-токена на страницу.

#### Definition of Done
- [x] дерево ОПиУ одним запросом, тест красный на старом коде
- [x] число запросов страницы не растёт с деревом (старый код 6 → 26, новый 6)
- [x] Action: happy + чужая категория + чужая/кривая статья ОПиУ + без изменений + sortOrder
- [x] endpoint: 200 / 403 CSRF / 404 IDOR / 422
- [x] ARCHITECTURE.md не затронут: новых Facade/Enum/Entity нет

#### Checks
- baseline: целевые тесты маппинга и импорта дерева — OK
- `make site-test` — OK, 5256 тестов; `make site-stan` — OK; `site-cs-check`, `site-cs-strict-types` — OK
- `npm run check:ui-kit` — красный до задачи (8998 нарушений), после 9012: +14 классов Tabler новой модалки, те же, что на странице
- ручной smoke в headless Chrome: сохранение, обновление строки, ошибка 403

#### Internal review
- свежая сессия, 1 итерация; BLOCKER 0, IMPORTANT 1 (поздний ответ пишется в чужую строку) — исправлен
- MINOR исправлены: шаг/диапазон порядка, предупреждение о потерянной статье, кнопки без права записи скрыты, имя статьи из data-атрибута
- FOLLOW-UP: см. handoff

#### External review
- required: yes (Large, HIGH-LOCAL); rounds: 1; результат: fixed without re-run
- IMPORTANT про `site/bin/capture-wb-inventory.sh` отклонён: неотслеживаемый файл Владельца, в PR не входит
- MINOR `1e2` → `parseInt` исправлен
