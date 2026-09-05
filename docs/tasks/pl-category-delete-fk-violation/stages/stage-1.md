### Stage 1: удаление статьи ОПиУ, привязанной к операциям документов, больше не даёт 500 — DONE

**Risk:** LOW
**Owner gate:** no
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** continue autonomously (merge)

#### Stage scope
- Stage base commit: `89b503d6`
- Work items completed: `1.1` (доменное исключение + Action + wiring), `1.2` (правка по внешнему ревью)

#### What was done

**Причина подтверждена в коде и воспроизведена тестом до правки.** GlitchTip issue 287
(2 события, последнее 2026-08-26): `ForeignKeyConstraintViolationException` при удалении статьи
ОПиУ. `PLCategoryController::delete` переносил агрегаты `pl_daily_totals` в «Без категории», но
не учитывал `document_operations.category_id -> pl_categories.id` (FK `fk_doc_oper_category`,
без `ON DELETE` — RESTRICT по умолчанию). Реальные операции документов — не производный агрегат,
поэтому просто перенести их в «Без категории» молча означало бы менять учётные данные без
ведома пользователя; вместо этого удаление отклоняется.

`PLCategoryController::delete` — единственное место в репозитории, удаляющее `PLCategory`.
Прецедент для этого класса ошибок уже был: `CompanyRoleInUseException` +
`DeleteCompanyRoleAction` (тот же паттерн — pre-check по счётчику, FK RESTRICT как страховка от
гонки, catch → доменное исключение, контроллер ловит его и показывает flash). Новый код
сознательно повторяет этот паттерн.

Сделано:
1. `DocumentOperationRepository::countByCategory(companyId, categoryId)` — company-scoped через
   join на `Document` (у `DocumentOperation` своего `companyId` нет).
2. `PLCategoryInUseException` — доменное исключение.
3. `DeletePLCategoryAction` — переносит логику удаления из контроллера: pre-check по счётчику,
   `wrapInTransaction` с переносом `pl_daily_totals` и `remove()`, catch
   `ForeignKeyConstraintViolationException` как страховка от гонки между pre-check и flush.
4. Контроллер: инжектит Action вместо `PLDailyTotalRepository`/инлайновой логики, ловит
   `PLCategoryInUseException` и показывает flash `danger` вместо 500.

#### Что изменилось по итогам внешнего ревью
Первая версия ловила `ForeignKeyConstraintViolationException` без проверки, какой именно constraint
нарушен. На `pl_categories` ссылаются без `ON DELETE` ещё `cashflow_categories`,
`wildberries_report_detail_mappings`, `finance_loan` — их нарушение транслировалось бы в то же
сообщение «привязаны операции документов», маскируя настоящую причину. Исправлено: catch
проверяет `str_contains($exception->getMessage(), 'fk_doc_oper_category')` и пробрасывает любой
другой FK-конфликт как есть. Добавлен негативный тест, доказывающий это.

#### Files changed
- `site/src/Finance/Application/DeletePLCategoryAction.php` — new
- `site/src/Finance/Exception/PLCategoryInUseException.php` — new
- `site/src/Finance/Repository/DocumentOperationRepository.php` — modified
- `site/src/Finance/Controller/PLCategoryController.php` — modified
- `site/tests/Unit/Finance/Application/DeletePLCategoryActionTest.php` — new
- `site/tests/Functional/Finance/PLCategoryEditControllerTest.php` — modified

#### Definition of Done
- [x] удаление статьи с операциями документов не даёт 500
- [x] статья и операция переживают отклонённое удаление без изменений
- [x] пользователь видит понятное сообщение
- [x] гонка между pre-check и flush тоже даёт доменное исключение, а не 500
- [x] FK-нарушение от других таблиц (не `document_operations`) не маскируется под этот случай
- [x] регрессия доказана красной на коде до правки (тот же текст ошибки, что в проде)
- Исключено из Stage: `cashflow_categories`, `wildberries_report_detail_mappings`, `finance_loan`
  — тот же класс дефекта, но отдельная задача (сознательно не расширял scope одного репортованного issue)

#### Baseline
- красного baseline в репозитории нет: cs, strict-types и stan были зелёными до задачи

#### Checks
- targeted: `php bin/phpunit --filter 'DeletePLCategoryActionTest|PLCategoryEditControllerTest'` — OK (7 тестов, 47 assertions)
- full relevant stage: `make site-test-unit` — OK (2301, 4 pre-existing deprecations);
  `make site-test-integration` — OK (1229); `composer test:functional` — OK (599, 2 pre-existing deprecations)
- `make site-cs-check` / `site-cs-strict-types` — Found 0 of 2471
- `make site-stan` — No errors; `phpstan-baseline.neon` не менялся

**Красное доказательство.** `testDeleteFailsGracefullyWhenDocumentOperationsReferenceCategory` на
коде до правки падает с тем же `SQLSTATE[23503]` / именем constraint `fk_doc_oper_category`, что
в прод-issue 287.

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: PHPStan сам поймал две реальные проблемы до отправки на внешнее ревью — nullsafe
  на заведомо non-null `PLCategory` после `assertInstanceOf` в функциональном тесте (упрощено
  через промежуточную переменную), и неиспользуемый `?string` в возвращаемом типе тестового
  анонимного класса (`getSQLState()` никогда не возвращает null, сужено до `string`)

#### External Claude Code review
- Реализация — Claude Code, внешний ревьюер — Codex (`codex exec -s read-only --ephemeral`), по таблице ролей `CLAUDE.md`
- Iterations: 2
- Result: REVIEW_GREEN
- Confirmed findings fixed: одна находка IMPORTANT — catch по `ForeignKeyConstraintViolationException`
  без проверки имени constraint маскировал бы FK-нарушения от `cashflow_categories`,
  `wildberries_report_detail_mappings`, `finance_loan` под «привязаны операции документов».
  Принята: catch сузен до `fk_doc_oper_category`, добавлен негативный тест
  `testDoesNotTranslateForeignKeyViolationFromAnUnrelatedConstraint`
- Rejected findings with reason: none
- Ограничение ревьюера: шелла у него не было, тесты не запускал, результаты принял из промпта

#### Review fixes applied
- Сужен catch до конкретного constraint, добавлен негативный тест на посторонний FK
- Переписан race-тест: вместо `createMock()` без текста сообщения — реальный
  `ForeignKeyConstraintViolationException` с текстом, дословно совпадающим с прод-ошибкой

#### Risks / reviewer focus
- Тот же класс дефекта (FK RESTRICT без обработки) есть ещё у трёх таблиц, ссылающихся на
  `pl_categories`: `cashflow_categories`, `wildberries_report_detail_mappings`, `finance_loan`.
  Сознательно вне scope — задача была про конкретный репортованный issue 287
  (`document_operations`), не про весь класс. Отдельный FOLLOW-UP для владельца.

#### Checkpoint
- `docs/tasks/pl-category-delete-fk-violation/checkpoint.md` обновлён
- exact next action: merge

#### Open questions
- none

#### Expected owner response
- не требуется; продолжаю автономно (merge)
